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
use CitOmni\JobRunner\Repository\JobRepository;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * GetJobStatus: Compose the public, transport-agnostic status read-model for one job.
 *
 * This is the official read path that lets a UI poll a job without touching SQL
 * directly. It loads the job row and a bounded slice of logs through the
 * repository, decodes the stored JSON columns, derives lifecycle flags from the
 * JobStatus enum, and returns a stable domain-shaped array. Adapters
 * (HTTP controllers, CLI commands) format this result; this Operation never does.
 *
 * Behavior:
 * - Validates method input early and cheaply, then caps log_limit to a sane max.
 * - Loads the job via JobRepository::findById(); a missing job yields a
 *   not_found result rather than an exception.
 * - Log selection is cursor-based:
 *   1) afterSeq === 0 returns the most recent slice via fetchRecentLogs(), giving
 *      a UI its initial "tail" view of recent activity.
 *   2) afterSeq > 0 returns only newer rows via fetchLogsAfterSeq(), for
 *      incremental polling.
 * - last_log_seq is the highest seq among the returned logs, or the incoming
 *   afterSeq when no rows are returned, so a polling client can feed it back as
 *   the next afterSeq without rewinding its cursor.
 * - result_json and each log context_json are decoded to arrays, or null when
 *   absent or not array-shaped.
 * - is_active / is_terminal are derived from JobStatus.
 * - is_stale is always false in this version; staleness requires a deliberate
 *   stale_after_sec config addition, which is intentionally out of scope here.
 *
 * Notes:
 * - Read-only: no writes, no transaction boundary, no services beyond the
 *   repository. All SQL stays in JobRepository.
 * - Status is written exclusively by this package's guarded transitions, so a
 *   non-enum status value is corrupt state. JobStatus::from() is used so such a
 *   value fails fast rather than being masked with default flags.
 * - Operations are instantiated explicitly by adapters via new GetJobStatus($this->app);
 *   they are not service-map singletons.
 */
final class GetJobStatus extends BaseOperation {

	public const RESULT_FOUND     = 'found';
	public const RESULT_NOT_FOUND = 'not_found';

	/** Hard ceiling for the number of log rows returned in one call. */
	private const MAX_LOG_LIMIT = 1000;


	/**
	 * Build the status read-model for one job.
	 *
	 * @param int $jobId    Job id (>= 1).
	 * @param int $afterSeq Exclusive lower-bound log seq the caller already holds (>= 0; 0 for initial view).
	 * @param int $logLimit Maximum log rows to return (>= 1; capped at MAX_LOG_LIMIT).
	 * @return array<string,mixed> Domain-shaped read-model. See class docblock for the contract.
	 * @throws \InvalidArgumentException When jobId, afterSeq, or logLimit is invalid.
	 */
	public function execute(int $jobId, int $afterSeq = 0, int $logLimit = 200): array {

		// -- 1. Validate and normalize input --------------------------------
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}
		if ($afterSeq < 0) {
			throw new \InvalidArgumentException('After seq must be >= 0.');
		}
		if ($logLimit < 1) {
			throw new \InvalidArgumentException('Log limit must be >= 1.');
		}
		if ($logLimit > self::MAX_LOG_LIMIT) {
			$logLimit = self::MAX_LOG_LIMIT;
		}

		// -- 2. Load the job -------------------------------------------------
		$repo = new JobRepository($this->app);
		$job  = $repo->findById($jobId);

		if ($job === null) {
			return [
				'status'       => self::RESULT_NOT_FOUND,
				'job'          => null,
				'logs'         => [],
				'last_log_seq' => 0,
				'result'       => null,
				'error'        => null,
			];
		}

		// -- 3. Load the relevant log slice ----------------------------------
		// afterSeq === 0 means "initial view": show the most recent activity.
		// afterSeq > 0 means "poll": show only rows newer than the held cursor.
		$rawLogs = $afterSeq > 0
			? $repo->fetchLogsAfterSeq($jobId, $afterSeq, $logLimit)
			: $repo->fetchRecentLogs($jobId, $logLimit);

		$logs       = [];
		$lastLogSeq = $afterSeq;

		foreach ($rawLogs as $row) {
			$seq = (int)$row['seq'];
			if ($seq > $lastLogSeq) {
				$lastLogSeq = $seq;
			}

			$logs[] = [
				'seq'        => $seq,
				'created_at' => $row['created_at'],
				'level'      => $row['level'],
				'stream'     => $row['stream'],
				'step_key'   => $row['step_key'],
				'message'    => $row['message'],
				'context'    => $this->decodeJsonArray($row['context_json']),
			];
		}

		// -- 4. Derive lifecycle flags and decode payload-shaped columns -----
		$statusEnum = JobStatus::from((string)$job['status']);

		$error = null;
		if ($job['error_class'] !== null || $job['error_reason_code'] !== null || $job['error_message'] !== null) {
			$error = [
				'class'       => $job['error_class'],
				'reason_code' => $job['error_reason_code'],
				'message'     => $job['error_message'],
			];
		}

		// -- 5. Compose the read-model ---------------------------------------
		return [
			'status' => self::RESULT_FOUND,
			'job'    => [
				'id'                 => $job['id'],
				'uuid'               => $job['job_uuid'],
				'type'               => $job['job_type'],
				'title'              => $job['title'],
				'status'             => $job['status'],
				'is_active'          => $statusEnum->isActive(),
				'is_terminal'        => $statusEnum->isTerminal(),
				'is_stale'           => false,
				'current_step_key'   => $job['current_step_key'],
				'current_step_label' => $job['current_step_label'],
				'step_index'         => $job['step_index'],
				'step_total'         => $job['step_total'],
				'created_at'         => $job['created_at'],
				'queued_at'          => $job['queued_at'],
				'started_at'         => $job['started_at'],
				'heartbeat_at'       => $job['heartbeat_at'],
				'finished_at'        => $job['finished_at'],
				'updated_at'         => $job['updated_at'],
			],
			'logs'         => $logs,
			'last_log_seq' => $lastLogSeq,
			'result'       => $this->decodeJsonArray($job['result_json']),
			'error'        => $error,
		];
	}


	/**
	 * Decode a stored JSON string into an array, or null when absent/non-array.
	 *
	 * Read-model decoding is fail-soft on purpose: a status view must keep
	 * rendering even if a stored JSON blob is unexpectedly malformed or scalar.
	 * Workflow-critical decoding (e.g. job payload) lives in RunQueuedJob, where
	 * it is fail-fast instead.
	 *
	 * @param mixed $json Raw JSON string, or null.
	 * @return array<mixed>|null Decoded array, or null when not array-shaped.
	 */
	private function decodeJsonArray(mixed $json): ?array {
		if (!\is_string($json) || $json === '') {
			return null;
		}

		$decoded = \json_decode($json, true);

		return \is_array($decoded) ? $decoded : null;
	}

}
