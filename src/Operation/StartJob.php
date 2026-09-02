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

use CitOmni\JobRunner\Enum\ClaimMode;
use CitOmni\JobRunner\Exception\JobRunnerException;
use CitOmni\JobRunner\Repository\JobRepository;
use CitOmni\JobRunner\Support\Clock;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * StartJob: Enqueue one token/push job and launch its detached CLI worker.
 *
 * This Operation owns the transport-agnostic push workflow for one job. Shared
 * queued-job validation, payload encoding, UUID generation, active-lock handling,
 * and persistence live in EnqueueJob; StartJob adds only the single-use worker
 * capability and detached process launch required by the existing token/push
 * execution model.
 *
 * Behavior:
 * - Generates one cryptographically random raw worker token and derives the
 *   SHA-256 hash that is persisted with the queued TOKEN-mode job.
 * - Delegates queue creation to EnqueueJob, which validates the job type and
 *   optional metadata, confirms the registered handler, applies the friendly
 *   active-lock pre-check, and relies on the database unique active_lock_key as
 *   the authoritative duplicate guard.
 * - Preserves EnqueueJob's RESULT_ALREADY_ACTIVE outcome without launching a
 *   second worker for the same active lock.
 * - Launches the fixed detached `job:run <job-id> --token=<token>` worker through
 *   JobLauncher after the queued row has been durably created.
 * - If launch submission returns false, transitions the freshly queued job to
 *   FAILED and returns RESULT_LAUNCH_FAILED.
 * - If JobLauncher throws JobRunnerException because launch setup or submission
 *   cannot proceed, transitions the queued job to FAILED and rethrows the
 *   original exception. A failed terminal transition is surfaced separately as
 *   launch_failed_terminal_transition_failed.
 *
 * Notes:
 * - Operations remain SQL-free. EnqueueJob and JobRepository own all persistence
 *   access and guarded state transitions.
 * - The active-lock race semantics remain unchanged by the enqueue refactor:
 *   EnqueueJob's pre-check is only a friendly fast path; the generated
 *   active_lock_key unique index remains authoritative.
 * - The raw worker token is an opaque execution capability. It is never returned,
 *   logged, persisted, or included in an exception message. Only its SHA-256 hash
 *   reaches persistence, and the local raw value is cleared after launch
 *   submission returns or throws.
 * - A token/push job is considered started only after the detached child wins the
 *   repository's queued -> running claim. StartJob itself never marks a job
 *   running and never executes the handler.
 * - Trusted/pull jobs use EnqueueTrustedJob and the persistent `job:work`
 *   supervisor; they are never launched by this Operation.
 * - All lifecycle timestamps used here come from Clock. Database NOW()/CURRENT_TIMESTAMP
 *   is intentionally not used by this workflow.
 */
final class StartJob extends BaseOperation {

	public const RESULT_STARTED        = 'started';
	public const RESULT_ALREADY_ACTIVE = 'already_active';
	public const RESULT_LAUNCH_FAILED  = 'launch_failed';

	/**
	 * Start one token/push job.
	 *
	 * The queued row is created before launch submission so the child worker has a
	 * durable ownership target. The raw token remains process-local and is passed
	 * only to the fixed detached JobRunner child.
	 *
	 * @param string      $jobType Registered, namespaced job type (for example "devkit.create_app").
	 * @param array       $payload JSON-encodable domain payload. An empty array is stored as NULL by EnqueueJob.
	 * @param string|null $lockKey Optional logical active-job lock; prevents a second active job for the same key.
	 * @param string|null $title   Optional human-facing title for status/UI.
	 * @return array{
	 *     status:string,
	 *     job_id?:int,
	 *     job_uuid?:string,
	 *     job_type?:string,
	 *     job_status?:string,
	 *     error_reason_code?:string
	 * } Domain-shaped result. Never contains the raw worker token.
	 * @throws \InvalidArgumentException When queue input is invalid.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When handler validation,
	 *         payload encoding, launch setup/submission, or a required failed-state
	 *         transition cannot complete safely.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException When queue insertion
	 *         fails for an unrelated database error, or an active-lock duplicate
	 *         cannot be resolved to the already-active result.
	 */
	public function execute(string $jobType, array $payload = [], ?string $lockKey = null, ?string $title = null): array {

		// -- 1. Mint the push worker capability ----------------------------
		// Keep the raw capability process-local. EnqueueJob receives only the
		// storage-equivalent SHA-256 hash used later by the child claim gate.
		$workerToken     = \bin2hex(\random_bytes(32));
		$workerTokenHash = \hash('sha256', $workerToken);

		// -- 2. Create the queued TOKEN-mode job ---------------------------
		// EnqueueJob owns validation, handler resolution, payload encoding,
		// UUID generation, active-lock race handling, and the queued INSERT.
		$queued = (new EnqueueJob($this->app))->execute(
			$jobType,
			ClaimMode::TOKEN,
			$workerTokenHash,
			$payload,
			$lockKey,
			$title
		);

		if ((string)$queued['status'] === EnqueueJob::RESULT_ALREADY_ACTIVE) {
			$workerToken = '';

			return [
				'status'     => self::RESULT_ALREADY_ACTIVE,
				'job_id'     => (int)$queued['job_id'],
				'job_uuid'   => (string)$queued['job_uuid'],
				'job_type'   => (string)$queued['job_type'],
				'job_status' => (string)$queued['job_status'],
			];
		}

		if ((string)$queued['status'] !== EnqueueJob::RESULT_QUEUED) {
			$workerToken = '';

			throw new JobRunnerException(
				'EnqueueJob returned an unexpected result to StartJob.',
				0,
				null,
				'unexpected_enqueue_result'
			);
		}

		$jobId   = (int)$queued['job_id'];
		$jobUuid = (string)$queued['job_uuid'];
		$jobType = (string)$queued['job_type'];
		$repo     = new JobRepository($this->app);
		$clock    = new Clock($this->app);

		// -- 3. Submit the detached token worker ---------------------------
		// The row is already durable at this point. StartJob never performs the
		// queued -> running transition; the fresh job:run child owns that claim.
		try {
			$launched = $this->app->jobLauncher->launch($jobId, $workerToken);
		} catch (JobRunnerException $e) {
			$workerToken = '';

			if (!$repo->markFailed(
				$jobId,
				\get_class($e),
				$e->getReasonCode() ?? 'launch_failed',
				'Detached job worker process could not be launched.',
				$clock->now()
			)) {
				throw new JobRunnerException(
					\sprintf(
						'Job %d could not transition to failed after a launch exception; unexpected job state.',
						$jobId
					),
					0,
					$e,
					'launch_failed_terminal_transition_failed'
				);
			}

			throw $e;
		}

		$workerToken = '';

		if ($launched === false) {
			if (!$repo->markFailed(
				$jobId,
				null,
				'launch_failed',
				'Failed to launch detached job worker process.',
				$clock->now()
			)) {
				throw new JobRunnerException(
					\sprintf(
						'Job %d could not transition to failed after a launch submission failure; unexpected job state.',
						$jobId
					),
					0,
					null,
					'launch_failed_terminal_transition_failed'
				);
			}

			return [
				'status'            => self::RESULT_LAUNCH_FAILED,
				'job_id'            => $jobId,
				'job_uuid'          => $jobUuid,
				'job_type'          => $jobType,
				'error_reason_code' => 'launch_failed',
			];
		}

		return [
			'status'   => self::RESULT_STARTED,
			'job_id'   => $jobId,
			'job_uuid' => $jobUuid,
			'job_type' => $jobType,
		];
	}
}
