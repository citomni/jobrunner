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

use CitOmni\Infrastructure\Exception\DbQueryException;
use CitOmni\JobRunner\Exception\JobRunnerException;
use CitOmni\JobRunner\Repository\JobRepository;
use CitOmni\JobRunner\Support\Clock;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * StartJob: Create one queued job and launch its detached CLI worker.
 *
 * This Operation owns the transport-agnostic workflow for starting a single
 * job. It is invoked from HTTP controllers and CLI commands alike; the calling
 * adapter is responsible for request parsing, response/exit-code shaping, and
 * any UI redirect or status URL.
 *
 * Behavior:
 * - Validates and normalizes the requested job type, optional lock key, and
 *   optional title (early, cheap, byte-bounded input checks).
 * - Confirms a runnable handler is registered for the job type via JobRegistry.
 *   The resolved handler instance is intentionally discarded; StartJob never
 *   runs a handler. RunQueuedJob does that later inside the detached worker.
 * - When a lock key is supplied, performs a pre-check through
 *   JobRepository::findActiveByLockKey() and short-circuits to
 *   RESULT_ALREADY_ACTIVE as a friendly fast path. The database unique key on
 *   active_lock_key remains the authoritative duplicate guard (see Notes).
 * - Encodes the payload, generates a job UUID and a single-use worker token,
 *   and stores only the SHA-256 hash of that token.
 * - Persists the queued job through JobRepository::createQueuedJob() and then
 *   launches the detached worker through the jobLauncher service.
 * - If launch submission returns false, transitions the freshly created job to
 *   failed and returns RESULT_LAUNCH_FAILED. If the launcher instead throws a
 *   JobRunnerException, the row is transitioned to failed and the original
 *   exception is rethrown; if that transition cannot be applied, a
 *   launch_failed_terminal_transition_failed error is raised (original chained).
 *
 * Notes:
 * - Operations are SQL-free and instantiated explicitly by adapters; this class
 *   is not a service-map singleton.
 * - The lock-key pre-check is a friendly fast path, not the race guard. The
 *   authoritative guard is the UNIQUE key uq_jobrun_jobs_active_lock_key on the
 *   generated active_lock_key column, which carries the lock key only while a
 *   job is active (queued/running/cancel_requested) and is NULL otherwise. If
 *   two callers race past the pre-check, the database rejects the second insert
 *   and createQueuedJob() raises a DbQueryException. Duplicate-entry races are
 *   re-checked through JobRepository::findActiveByLockKey(); if the active row
 *   is now visible, StartJob returns RESULT_ALREADY_ACTIVE. If no active row is
 *   found, the original DbQueryException is rethrown so unrelated unique
 *   conflicts, such as a UUID collision, still fail fast.
 * - The raw worker token is never returned, logged, persisted, or placed in any
 *   exception message. Only hash('sha256', $token) is stored.
 * - All timestamps come from Clock; no date()/gmdate()/NOW()/CURRENT_TIMESTAMP.
 */
final class StartJob extends BaseOperation {

	public const RESULT_STARTED        = 'started';
	public const RESULT_ALREADY_ACTIVE = 'already_active';
	public const RESULT_LAUNCH_FAILED  = 'launch_failed';

	private const MAX_JOB_TYPE_BYTES = 128;
	private const MAX_LOCK_KEY_BYTES = 191;
	private const MAX_TITLE_BYTES    = 255;


	/**
	 * Start one job.
	 *
	 * @param string      $jobType Registered, namespaced job type (e.g. "devkit.create_app").
	 * @param array       $payload JSON-encodable payload. An empty array is stored as NULL.
	 * @param string|null $lockKey Optional logical lock; prevents a second active job for the same key.
	 * @param string|null $title   Optional human-facing title for status/UI.
	 * @return array{
	 *     status:string,
	 *     job_id?:int,
	 *     job_uuid?:string,
	 *     job_type?:string,
	 *     job_status?:string,
	 *     error_reason_code?:string
	 * } Domain-shaped result. Never contains the raw worker token.
	 * @throws \InvalidArgumentException When method input is invalid.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the handler is not runnable,
	 *         the payload cannot be encoded, a terminal failed-transition cannot be applied, or
	 *         (rethrown) the launcher cannot submit the worker process.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException When job insertion fails for an
	 *         unrelated database error, or when a duplicate entry cannot be resolved to an active lock row.
	 */
	public function execute(string $jobType, array $payload = [], ?string $lockKey = null, ?string $title = null): array {

		// -- 1. Validate and normalize method input -----------------------
		$jobType = \trim($jobType);
		if ($jobType === '') {
			throw new \InvalidArgumentException('Job type must be a non-empty string.');
		}
		if (\strlen($jobType) > self::MAX_JOB_TYPE_BYTES) {
			throw new \InvalidArgumentException(
				\sprintf('Job type must not exceed %d bytes.', self::MAX_JOB_TYPE_BYTES)
			);
		}

		$lockKey = $this->normalizeOptionalString($lockKey, self::MAX_LOCK_KEY_BYTES, 'Lock key');
		$title   = $this->normalizeOptionalString($title, self::MAX_TITLE_BYTES, 'Title');

		// -- 2. Confirm a runnable handler exists for this job type --------
		// Resolves and validates the configured handler (existence + contract).
		// The instance is discarded on purpose: StartJob does not run handlers.
		$this->app->jobRegistry->get($jobType);

		$repo = new JobRepository($this->app);

		// -- 3. Lock-key pre-check (friendly fast path) -------------------
		// This avoids a guaranteed DB error for the common "already running"
		// case. It is not the race guard: the UNIQUE key on the generated
		// active_lock_key column is authoritative, and createQueuedJob() handles
		// a duplicate active-lock race by re-checking the active row.
		if ($lockKey !== null) {
			$active = $repo->findActiveByLockKey($lockKey);
			if ($active !== null) {
				return $this->activeJobResult($active);
			}
		}

		// -- 4. Build job identity, token, and payload --------------------
		$payloadJson     = $this->encodePayload($payload);
		$jobUuid         = $this->generateUuidV4();
		$workerToken     = \bin2hex(\random_bytes(32)); // 64-char CLI-safe hex; never persisted/returned/logged.
		$workerTokenHash = \hash('sha256', $workerToken);

		$clock = new Clock($this->app);
		$now   = $clock->now();

		// -- 5. Create the queued job row ---------------------------------
		// SQL stays in the repository. Status is set to queued by createQueuedJob().
		try {
			$jobId = $repo->createQueuedJob([
				'job_uuid'          => $jobUuid,
				'job_type'          => $jobType,
				'lock_key'          => $lockKey,
				'title'             => $title,
				'payload_json'      => $payloadJson,
				'worker_token_hash' => $workerTokenHash,
				'created_at'        => $now,
				'queued_at'         => $now,
				'updated_at'        => $now,
			]);
		} catch (DbQueryException $e) {
			if ($lockKey !== null && $e->isDuplicateEntry()) {
				$active = $repo->findActiveByLockKey($lockKey);
				if ($active !== null) {
					return $this->activeJobResult($active);
				}
			}

			throw $e;
		}

		// -- 6. Launch the detached CLI worker ----------------------------
		// Two failure modes, neither of which may leave an orphaned queued row
		// (queued jobs are invisible to the heartbeat-based stale sweeper):
		//   - launch() returns false: the OS refused to submit the process. A
		//     clean per-job runtime failure, reported as RESULT_LAUNCH_FAILED.
		//   - launch() throws JobRunnerException: the launcher is misconfigured
		//     or cannot operate (systemic). The row is transitioned to failed
		//     best-effort and the original error is rethrown so the deployment
		//     problem surfaces loudly instead of being masked.
		try {
			$launched = $this->app->jobLauncher->launch($jobId, $workerToken);
		} catch (JobRunnerException $e) {
			// Terminal transition, kept consistent with the false path below and
			// with RunQueuedJob: a failed terminal transition is itself a hard error.
			// Never persist the token or the launch command; the launcher itself
			// keeps the token out of $e.
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

		if ($launched === false) {
			$failNow = $clock->now();
			if (!$repo->markFailed(
				$jobId,
				null,
				'launch_failed',
				'Failed to launch detached job worker process.',
				$failNow
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

		// -- 7. Job created and worker launched ---------------------------
		return [
			'status'   => self::RESULT_STARTED,
			'job_id'   => $jobId,
			'job_uuid' => $jobUuid,
			'job_type' => $jobType,
		];
	}


	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Build the already-active result shape.
	 *
	 * @param array $active Normalized active job row from JobRepository.
	 * @return array{status:string,job_id:int,job_uuid:string,job_type:string,job_status:string} Domain-shaped result.
	 */
	private function activeJobResult(array $active): array {
		return [
			'status'     => self::RESULT_ALREADY_ACTIVE,
			'job_id'     => (int)$active['id'],
			'job_uuid'   => (string)$active['job_uuid'],
			'job_type'   => (string)$active['job_type'],
			'job_status' => (string)$active['status'],
		];
	}

	/**
	 * Normalize an optional, byte-bounded string input.
	 *
	 * Trims the value, maps empty/whitespace-only to null, and enforces a byte
	 * limit using strlen() because the backing columns are storage-sensitive.
	 *
	 * @param string|null $value    Raw input.
	 * @param int         $maxBytes Inclusive byte limit for a non-null value.
	 * @param string      $field    Field label used in error messages.
	 * @return string|null Trimmed value, or null when empty.
	 * @throws \InvalidArgumentException When the trimmed value exceeds $maxBytes.
	 */
	private function normalizeOptionalString(?string $value, int $maxBytes, string $field): ?string {
		if ($value === null) {
			return null;
		}

		$value = \trim($value);
		if ($value === '') {
			return null;
		}

		if (\strlen($value) > $maxBytes) {
			throw new \InvalidArgumentException(
				\sprintf('%s must not exceed %d bytes.', $field, $maxBytes)
			);
		}

		return $value;
	}

	/**
	 * Encode the payload for storage in payload_json.
	 *
	 * An empty payload is stored as null. RunQueuedJob::decodePayload() treats
	 * null and empty string as [], so an empty job carries no JSON noise.
	 *
	 * Invalid UTF-8 is deliberately NOT substituted. The payload is structured
	 * domain input and part of the job contract, so it fails fast here with
	 * reason code payload_not_encodable rather than silently handing the handler
	 * altered bytes. This mirrors RunQueuedJob result encoding and contrasts with
	 * log encoding, which tolerates raw process output via substitution.
	 *
	 * @param array $payload JSON-encodable payload.
	 * @return string|null Encoded JSON, or null for an empty payload.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When encoding fails (reason: payload_not_encodable).
	 */
	private function encodePayload(array $payload): ?string {
		if ($payload === []) {
			return null;
		}

		$json = \json_encode(
			$payload,
			\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
		);

		if ($json === false) {
			throw new JobRunnerException(
				'Job payload could not be JSON-encoded: ' . \json_last_error_msg(),
				0,
				null,
				'payload_not_encodable'
			);
		}

		return $json;
	}

	/**
	 * Generate a canonical lowercase RFC 4122 version 4 UUID.
	 *
	 * Uses random_bytes(16) and sets the version (4) and variant (10xx) bits.
	 * No external package and no separate UUID service is introduced.
	 *
	 * @return string Canonical lowercase UUID.
	 */
	private function generateUuidV4(): string {
		$bytes = \random_bytes(16);

		$bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40); // Version 4.
		$bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80); // Variant 10xx.

		$hex = \bin2hex($bytes);

		return \sprintf(
			'%s-%s-%s-%s-%s',
			\substr($hex, 0, 8),
			\substr($hex, 8, 4),
			\substr($hex, 12, 4),
			\substr($hex, 16, 4),
			\substr($hex, 20, 12)
		);
	}
}
