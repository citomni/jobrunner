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
use CitOmni\JobRunner\Enum\ClaimMode;
use CitOmni\JobRunner\Exception\JobRunnerException;
use CitOmni\JobRunner\Repository\JobRepository;
use CitOmni\JobRunner\Support\Clock;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * EnqueueJob: Create one queued JobRunner job without launching a worker.
 *
 * Owns the shared queue-creation workflow used by token/push and trusted/pull
 * entrypoints. Worker launching and execution remain separate concerns.
 *
 * Behavior:
 * - Validates and normalizes the job type, optional lock key, and title.
 * - Validates the claim-mode/token-hash enqueue invariant.
 * - Confirms the configured handler is runnable before creating the row.
 * - Applies the friendly active-lock pre-check while leaving the database unique
 *   key as the authoritative concurrent duplicate guard.
 * - Encodes payload JSON, generates the job UUID, and persists a queued row.
 *
 * Notes:
 * - Operations are SQL-free; all persistence goes through JobRepository.
 * - TOKEN jobs must be created with a worker-token hash.
 * - TRUSTED jobs must be created without a worker-token hash. The trusted
 *   supervisor prepares an ephemeral handoff hash later while the row is queued.
 * - The raw worker token never reaches this Operation.
 */
final class EnqueueJob extends BaseOperation {

	public const RESULT_QUEUED         = 'queued';
	public const RESULT_ALREADY_ACTIVE = 'already_active';

	private const MAX_JOB_TYPE_BYTES = 128;
	private const MAX_LOCK_KEY_BYTES = 191;
	private const MAX_TITLE_BYTES    = 255;

	/**
	 * Create one queued job.
	 *
	 * @param string      $jobType        Registered job type.
	 * @param ClaimMode   $claimMode      Persisted worker ownership model.
	 * @param string|null $workerTokenHash SHA-256 worker-token hash for TOKEN mode; null for TRUSTED mode.
	 * @param array       $payload        JSON-encodable payload. Empty array is stored as NULL.
	 * @param string|null $lockKey        Optional active-job lock key.
	 * @param string|null $title          Optional human-facing title.
	 * @return array{
	 *     status:string,
	 *     job_id:int,
	 *     job_uuid:string,
	 *     job_type:string,
	 *     job_status?:string
	 * } Domain-shaped queue result.
	 * @throws \InvalidArgumentException When method input is invalid.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the handler is not runnable or payload encoding fails.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On unrelated insert/constraint failure.
	 */
	public function execute(
		string $jobType,
		ClaimMode $claimMode,
		?string $workerTokenHash,
		array $payload = [],
		?string $lockKey = null,
		?string $title = null
	): array {

		// -- 1. Validate and normalize queue input -------------------------
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

		$this->validateClaimInput($claimMode, $workerTokenHash);

		// -- 2. Confirm the configured handler is runnable -----------------
		$this->app->jobRegistry->get($jobType);

		$repo = new JobRepository($this->app);

		// -- 3. Friendly active-lock pre-check -----------------------------
		if ($lockKey !== null) {
			$active = $repo->findActiveByLockKey($lockKey);
			if ($active !== null) {
				return $this->activeJobResult($active);
			}
		}

		// -- 4. Build persisted job data -----------------------------------
		$payloadJson = $this->encodePayload($payload);
		$jobUuid     = $this->generateUuidV4();
		$clock       = new Clock($this->app);
		$now         = $clock->now();

		// -- 5. Persist queued row; DB uniqueness is authoritative ---------
		try {
			$jobId = $repo->createQueuedJob([
				'job_uuid'          => $jobUuid,
				'job_type'          => $jobType,
				'claim_mode'        => $claimMode->value,
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

		return [
			'status'   => self::RESULT_QUEUED,
			'job_id'   => $jobId,
			'job_uuid' => $jobUuid,
			'job_type' => $jobType,
		];
	}


	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Validate the queue-time ownership invariant for the selected claim mode.
	 *
	 * @param ClaimMode   $claimMode      Persisted claim mode.
	 * @param string|null $workerTokenHash Worker-token hash supplied at enqueue, if any.
	 * @return void
	 * @throws \InvalidArgumentException When claim mode and token-hash state do not match.
	 */
	private function validateClaimInput(ClaimMode $claimMode, ?string $workerTokenHash): void {
		if ($claimMode === ClaimMode::TOKEN) {
			if ($workerTokenHash === null || $workerTokenHash === '') {
				throw new \InvalidArgumentException('Token-mode jobs require a worker token hash.');
			}
			return;
		}

		if ($workerTokenHash !== null) {
			throw new \InvalidArgumentException('Trusted-mode jobs must be enqueued without a worker token hash.');
		}
	}

	/**
	 * Build the already-active result shape.
	 *
	 * @param array $active Normalized active job row.
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
	 * Normalize one optional byte-bounded string.
	 *
	 * @param string|null $value Raw value.
	 * @param int $maxBytes Maximum byte length.
	 * @param string $field Field label for validation errors.
	 * @return string|null Normalized value.
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
	 * Encode the domain payload for payload_json.
	 *
	 * @param array $payload JSON-encodable payload.
	 * @return string|null Encoded JSON, or null for an empty payload.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When encoding fails.
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
	 * @return string UUID.
	 */
	private function generateUuidV4(): string {
		$bytes = \random_bytes(16);

		$bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

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
