<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\JobRunner\Operation;

use CitOmni\JobRunner\Enum\JobStatus;
use CitOmni\JobRunner\Exception\JobRunnerException;
use CitOmni\JobRunner\Repository\JobRepository;
use CitOmni\JobRunner\Support\Clock;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * CancelJob: Cancel one job through the public jobrunner API, without manual SQL.
 *
 * This is the transport-agnostic cancellation decision graph for V1. Cancellation
 * is cooperative: a running job is never terminal-cancelled underneath its worker.
 * Instead it is moved to cancel_requested, and the worker observes that through
 * JobContext::isCancellationRequested() and lets RunQueuedJob finalize it as
 * cancelled. A queued job that no worker has claimed yet is cancelled directly.
 *
 * Behavior:
 * - Validates the job id early and cheaply.
 * - Loads the current job state through JobRepository.
 * - Dispatches on status:
 *     queued           -> markCancelled()  => cancelled
 *     running          -> requestCancel()  => cancel_requested
 *     cancel_requested -> idempotent no-op => already_cancel_requested
 *     succeeded/failed/cancelled           => already_terminal
 *     missing                              => not_found
 * - On a guarded transition miss, the row raced between the read and the write.
 *   The job is then re-read exactly once and the decision is re-evaluated against
 *   the fresh status (e.g. queued got claimed into running just before our write,
 *   so the correct outcome is cancel_requested).
 * - If a guarded transition still misses after that single re-read, the state is
 *   genuinely conflicting and a JobRunnerException is thrown rather than guessing.
 *
 * Notes:
 * - No transaction is used or needed. Each transition is a single conditional
 *   UPDATE guarded on status in the repository; that guard is the concurrency
 *   control. There is no multi-write sequence to coordinate atomically.
 * - No logs are written here. The status change itself is the record, and the
 *   terminal "Job cancelled." log for the cooperative path is emitted by
 *   RunQueuedJob when it finalizes cancel_requested -> cancelled.
 * - Operations are instantiated explicitly (new CancelJob($app)); this class is
 *   not a service-map singleton.
 *
 * Typical usage:
 *   $result = (new CancelJob($this->app))->execute($jobId);
 *   // adapter maps $result['status'] to HTTP/CLI output.
 */
final class CancelJob extends BaseOperation {

	public const RESULT_CANCELLED                = 'cancelled';
	public const RESULT_CANCEL_REQUESTED         = 'cancel_requested';
	public const RESULT_ALREADY_CANCEL_REQUESTED = 'already_cancel_requested';
	public const RESULT_ALREADY_TERMINAL         = 'already_terminal';
	public const RESULT_NOT_FOUND                = 'not_found';




	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Cancel one job by id.
	 *
	 * @param int $jobId Job id (>= 1).
	 * @return array<string,int|string> Domain-shaped cancellation result. See class docblock for the per-status shapes.
	 * @throws \InvalidArgumentException When the job id is invalid.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When a guarded transition still conflicts after a single re-read.
	 * @throws \ValueError When the persisted status is not a known JobStatus value (corrupt state, fail-fast).
	 */
	public function execute(int $jobId): array {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$repo  = new JobRepository($this->app);
		$clock = new Clock($this->app);

		$job = $repo->findById($jobId);
		if ($job === null) {
			return $this->notFound($jobId);
		}

		return $this->decide($repo, $clock, $job, false);
	}





	// ----------------------------------------------------------------
	// Decision graph
	// ----------------------------------------------------------------

	/**
	 * Decide and apply the cancellation transition for a single loaded job row.
	 *
	 * Called first with the initial read ($isReread = false). When a guarded
	 * transition misses, the job is re-read once and this method is invoked again
	 * with $isReread = true, which bounds the retry budget to a single re-read.
	 *
	 * @param JobRepository       $repo     Repository owning all SQL.
	 * @param Clock               $clock    Clock supplying write timestamps.
	 * @param array<string,mixed> $job      Normalized job row.
	 * @param bool                $isReread Whether this is the post-miss re-read pass.
	 * @return array<string,int|string> Domain-shaped cancellation result.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the transition conflicts after the re-read, or on unhandled status (enum drift).
	 * @throws \ValueError When the status is not a known JobStatus value.
	 */
	private function decide(JobRepository $repo, Clock $clock, array $job, bool $isReread): array {
		$jobId  = (int)$job['id'];
		$status = JobStatus::from((string)$job['status']);

		// Queued: no worker owns it yet, so cancel directly. markCancelled()
		// competes atomically with claimQueued() on the same status guard.
		if ($status === JobStatus::QUEUED) {
			if ($repo->markCancelled($jobId, $clock->now())) {
				return $this->transitionResult(
					self::RESULT_CANCELLED,
					$job,
					JobStatus::QUEUED->value,
					JobStatus::CANCELLED->value
				);
			}
			return $this->resolveAfterMiss($repo, $clock, $job, $isReread);
		}

		// Running: a live worker owns it. Never terminal-cancel underneath it;
		// request cooperative cancellation and let RunQueuedJob finalize it.
		if ($status === JobStatus::RUNNING) {
			if ($repo->requestCancel($jobId, $clock->now())) {
				return $this->transitionResult(
					self::RESULT_CANCEL_REQUESTED,
					$job,
					JobStatus::RUNNING->value,
					JobStatus::CANCEL_REQUESTED->value
				);
			}
			return $this->resolveAfterMiss($repo, $clock, $job, $isReread);
		}

		// Cancellation already requested: idempotent no-op.
		if ($status === JobStatus::CANCEL_REQUESTED) {
			return $this->transitionResult(
				self::RESULT_ALREADY_CANCEL_REQUESTED,
				$job,
				JobStatus::CANCEL_REQUESTED->value,
				JobStatus::CANCEL_REQUESTED->value
			);
		}

		// Terminal (succeeded/failed/cancelled): nothing to do.
		if ($status->isTerminal()) {
			return $this->transitionResult(
				self::RESULT_ALREADY_TERMINAL,
				$job,
				$status->value,
				$status->value
			);
		}

		// Unreachable for the current JobStatus enum. Guards against future enum
		// drift: a new status added without handling here fails fast instead of
		// silently returning null.
		throw new JobRunnerException(
			\sprintf('Job %d has unhandled status "%s" in CancelJob.', $jobId, $status->value),
			0,
			null,
			'cancel_unhandled_status'
		);
	}


	/**
	 * Resolve a guarded transition miss by re-reading the job exactly once.
	 *
	 * A miss means the row's status changed between our read and our write. On the
	 * first miss we re-read and re-evaluate against the fresh status. A miss that
	 * survives the re-read is a genuine conflict and is surfaced as an exception.
	 *
	 * @param JobRepository       $repo     Repository owning all SQL.
	 * @param Clock               $clock    Clock supplying write timestamps.
	 * @param array<string,mixed> $job      The job row we just failed to transition.
	 * @param bool                $isReread Whether the single re-read was already spent.
	 * @return array<string,int|string> Domain-shaped cancellation result.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the conflict persists after the re-read.
	 */
	private function resolveAfterMiss(JobRepository $repo, Clock $clock, array $job, bool $isReread): array {
		$jobId = (int)$job['id'];

		if ($isReread) {
			// One re-read has already been spent. A second guarded miss means the
			// row raced past the status we just observed; refuse to guess.
			throw new JobRunnerException(
				\sprintf(
					'Job %d cancellation hit a guarded transition conflict; observed status "%s" no longer applied at write time.',
					$jobId,
					(string)$job['status']
				),
				0,
				null,
				'cancel_transition_conflict'
			);
		}

		$fresh = $repo->findById($jobId);
		if ($fresh === null) {
			return $this->notFound($jobId);
		}

		return $this->decide($repo, $clock, $fresh, true);
	}





	// ----------------------------------------------------------------
	// Result shaping
	// ----------------------------------------------------------------

	/**
	 * Build a domain-shaped result for a job whose row is known.
	 *
	 * @param string              $status         One of the RESULT_* constants.
	 * @param array<string,mixed> $job            Normalized job row.
	 * @param string              $previousStatus Status the job held at the decision point.
	 * @param string              $newStatus      Status the job holds after this operation.
	 * @return array<string,int|string> Domain-shaped result.
	 */
	private function transitionResult(string $status, array $job, string $previousStatus, string $newStatus): array {
		return [
			'status'          => $status,
			'job_id'          => (int)$job['id'],
			'job_uuid'        => (string)$job['job_uuid'],
			'job_type'        => (string)$job['job_type'],
			'previous_status' => $previousStatus,
			'new_status'      => $newStatus,
		];
	}


	/**
	 * Build the not-found result shape.
	 *
	 * @param int $jobId Job id.
	 * @return array{status:string,job_id:int} Domain-shaped result.
	 */
	private function notFound(int $jobId): array {
		return [
			'status' => self::RESULT_NOT_FOUND,
			'job_id' => $jobId,
		];
	}

}
