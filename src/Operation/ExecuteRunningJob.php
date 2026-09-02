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
use CitOmni\JobRunner\Value\JobContext;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * ExecuteRunningJob: Execute one already-claimed JobRunner job.
 *
 * Owns the worker-boundary lifecycle after a caller has atomically transitioned
 * the job from queued to running. Claim mechanics deliberately stay outside this
 * Operation so token/push and trusted/pull workers can share the exact same
 * handler execution, cancellation, logging, result, error, and terminal-state
 * behavior.
 *
 * Behavior:
 * - Loads the already-claimed job and resolves its registered handler.
 * - Runs the handler with JobContext inside the single broad Throwable boundary.
 * - Distinguishes success, cooperative cancellation, and failure.
 * - Applies exactly one guarded terminal transition and returns a domain-shaped
 *   result matching the existing RunQueuedJob contract.
 *
 * Notes:
 * - The caller must have won the job's queued -> running ownership gate before
 *   calling execute(). This Operation does not claim or authenticate workers.
 * - No SQL here. All persistence goes through JobRepository's guarded methods.
 * - No Operation-level transaction. Claim, progress, logs, heartbeat, and final
 *   state remain independently visible and durable during execution.
 * - The error message stored on the job row is UTF-8-normalized and byte-capped;
 *   the full message is written through JobLogger.
 *
 * @see \CitOmni\JobRunner\Operation\RunQueuedJob
 * @see \CitOmni\JobRunner\Operation\RunTrustedJob
 * @see \CitOmni\JobRunner\Repository\JobRepository
 */
final class ExecuteRunningJob extends BaseOperation {

	public const RESULT_SUCCEEDED = 'succeeded';
	public const RESULT_CANCELLED = 'cancelled';
	public const RESULT_FAILED    = 'failed';

	/**
	 * Conservative byte cap for persisted error_message.
	 */
	private const MAX_ERROR_MESSAGE_BYTES = 60000;

	/**
	 * Execute one already-claimed running job.
	 *
	 * @param int $jobId Claimed job id. Must be >= 1.
	 * @return array{
	 *     status:string,
	 *     job_id:int,
	 *     job_uuid:string,
	 *     job_type:string,
	 *     error_class?:?string,
	 *     error_reason_code?:?string,
	 *     error_message?:?string
	 * } Domain-shaped execution result.
	 * @throws \InvalidArgumentException When job id is invalid.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When a guarded terminal
	 *         transition unexpectedly does not apply.
	 */
	public function execute(int $jobId): array {

		// -- 1. Validate operation input ----------------------------------
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$clock = new Clock($this->app);
		$repo  = new JobRepository($this->app);

		// -- 2. Run the handler at the worker boundary --------------------
		$jobUuid      = '';
		$jobType      = '';
		$outcome      = self::RESULT_FAILED;
		$resultJson   = null;
		$errorClass   = null;
		$errorReason  = null;
		$errorMessage = null;
		$caught       = null;

		try {
			$job = $repo->findById($jobId);
			if ($job === null) {
				throw new JobRunnerException(
					\sprintf('Job %d was claimed but could not be loaded.', $jobId),
					0,
					null,
					'job_load_failed'
				);
			}

			$jobUuid = (string)$job['job_uuid'];
			$jobType = (string)$job['job_type'];

			$this->app->jobLogger->info($jobId, 'Job started.');

			$payload = $this->decodePayload($job['payload_json']);
			$handler = $this->app->jobRegistry->get($jobType);
			$context = new JobContext($this->app, $repo, $clock, $jobId, $jobUuid, $jobType, $payload);

			$result = $handler->run($context);

			if ($repo->getStatus($jobId) === JobStatus::CANCEL_REQUESTED->value) {
				$outcome = self::RESULT_CANCELLED;
			} else {
				$encoded = \json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
				if ($encoded === false) {
					throw new JobRunnerException(
						'Handler result could not be JSON-encoded: ' . \json_last_error_msg(),
						0,
						null,
						'result_not_encodable'
					);
				}

				$resultJson = $encoded;
				$outcome    = self::RESULT_SUCCEEDED;
			}
		} catch (\Throwable $e) {
			$caught       = $e;
			$outcome      = self::RESULT_FAILED;
			$errorClass   = \get_class($e);
			$errorReason  = $e instanceof JobRunnerException ? $e->getReasonCode() : null;
			$errorMessage = $e->getMessage();
		}

		// -- 3. Apply the terminal transition once ------------------------
		$now = $clock->now();

		if ($outcome === self::RESULT_SUCCEEDED) {
			if (!$repo->markSucceeded($jobId, $resultJson, $now)) {
				if ($repo->getStatus($jobId) === JobStatus::CANCEL_REQUESTED->value) {
					return $this->completeCancellation($repo, $clock, $jobId, $jobUuid, $jobType);
				}

				throw new JobRunnerException(
					\sprintf('Job %d could not transition to succeeded; unexpected job state.', $jobId),
					0,
					null,
					'succeeded_terminal_transition_failed'
				);
			}

			$this->app->jobLogger->info($jobId, 'Job succeeded.');

			return [
				'status'   => self::RESULT_SUCCEEDED,
				'job_id'   => $jobId,
				'job_uuid' => $jobUuid,
				'job_type' => $jobType,
			];
		}

		if ($outcome === self::RESULT_CANCELLED) {
			return $this->completeCancellation($repo, $clock, $jobId, $jobUuid, $jobType);
		}

		$safeMessage = $this->normalizeErrorMessage($errorMessage);

		if (!$repo->markFailed($jobId, $errorClass, $errorReason, $safeMessage, $now)) {
			throw new JobRunnerException(
				\sprintf('Job %d could not transition to failed; unexpected job state.', $jobId),
				0,
				$caught,
				'failed_terminal_transition_failed'
			);
		}

		$this->app->jobLogger->error($jobId, 'Job failed: ' . ($errorMessage ?? ''), null, [
			'error_class'       => $errorClass,
			'error_reason_code' => $errorReason,
		]);

		return [
			'status'            => self::RESULT_FAILED,
			'job_id'            => $jobId,
			'job_uuid'          => $jobUuid,
			'job_type'          => $jobType,
			'error_class'       => $errorClass,
			'error_reason_code' => $errorReason,
			'error_message'     => $safeMessage,
		];
	}


	/**
	 * Complete a cooperative cancellation terminal transition.
	 *
	 * @param JobRepository $repo    Job repository.
	 * @param Clock         $clock   Clock used for the terminal timestamp.
	 * @param int           $jobId   Job id.
	 * @param string        $jobUuid Job UUID.
	 * @param string        $jobType Job type.
	 * @return array{status:string,job_id:int,job_uuid:string,job_type:string} Cancelled result.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the guarded transition does not apply.
	 */
	private function completeCancellation(JobRepository $repo, Clock $clock, int $jobId, string $jobUuid, string $jobType): array {
		if (!$repo->markCancelled($jobId, $clock->now())) {
			throw new JobRunnerException(
				\sprintf('Job %d could not transition to cancelled; unexpected job state.', $jobId),
				0,
				null,
				'cancelled_terminal_transition_failed'
			);
		}

		$this->app->jobLogger->info($jobId, 'Job cancelled.');

		return [
			'status'   => self::RESULT_CANCELLED,
			'job_id'   => $jobId,
			'job_uuid' => $jobUuid,
			'job_type' => $jobType,
		];
	}


	/**
	 * Decode payload_json into an associative payload array.
	 *
	 * @param string|null $payloadJson Stored payload JSON, or null/empty for no payload.
	 * @return array<string,mixed> Decoded payload.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the stored JSON
	 *         is malformed or does not decode to an array.
	 */
	private function decodePayload(?string $payloadJson): array {
		if ($payloadJson === null || $payloadJson === '') {
			return [];
		}

		$decoded = \json_decode($payloadJson, true);
		if (\json_last_error() !== \JSON_ERROR_NONE) {
			throw new JobRunnerException(
				'Job payload_json is not valid JSON: ' . \json_last_error_msg(),
				0,
				null,
				'invalid_payload_json'
			);
		}

		if (!\is_array($decoded)) {
			throw new JobRunnerException(
				'Job payload_json did not decode to an array.',
				0,
				null,
				'invalid_payload_shape'
			);
		}

		return $decoded;
	}


	/**
	 * Normalize a Throwable message for the job row.
	 *
	 * @param string|null $message Raw exception message.
	 * @return string|null DB-safe message.
	 */
	private function normalizeErrorMessage(?string $message): ?string {
		if ($message === null || $message === '') {
			return $message;
		}

		if (\preg_match('//u', $message) !== 1) {
			$message = $this->substituteInvalidUtf8($message);
		}

		if (\strlen($message) > self::MAX_ERROR_MESSAGE_BYTES) {
			$size = self::MAX_ERROR_MESSAGE_BYTES;
			while ($size > 0 && (\ord($message[$size]) & 0xC0) === 0x80) {
				$size--;
			}
			$message = \substr($message, 0, $size);
		}

		return $message;
	}


	/**
	 * Replace invalid UTF-8 byte sequences using the JSON extension.
	 *
	 * @param string $value Possibly invalid UTF-8.
	 * @return string Valid UTF-8, or an empty string on failure.
	 */
	private function substituteInvalidUtf8(string $value): string {
		$encoded = \json_encode($value, \JSON_INVALID_UTF8_SUBSTITUTE);
		if ($encoded === false) {
			return '';
		}

		$decoded = \json_decode($encoded);

		return \is_string($decoded) ? $decoded : '';
	}

}
