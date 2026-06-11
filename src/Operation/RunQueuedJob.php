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
 * RunQueuedJob: Own the worker-boundary workflow for one queued job.
 *
 * Claims a single queued job, resolves and runs its handler, and applies the
 * terminal state transition deterministically. This is the only place in the
 * package that catches Throwable broadly; the catch exists solely to classify
 * a failed run into a stable terminal state, never to hide it.
 *
 * Behavior:
 * - Hashes the presented raw worker token to its storage-equivalent and uses
 *   it as the single worker ownership gate via JobRepository::claimQueued().
 * - On a failed claim returns a deterministic CLAIM_FAILED result. A failed
 *   claim is never reported in a way that distinguishes "not queued" from
 *   "wrong token", to avoid leaking ownership state.
 * - Distinguishes success, cooperative cancellation, and failure:
 *     1) Handler threw                      -> markFailed (FAILED)
 *     2) cancel_requested observed post-run -> markCancelled (CANCELLED)
 *     3) otherwise, while still running     -> markSucceeded (SUCCEEDED)
 *   A running job is never reported as succeeded merely because the handler
 *   returned; the post-run status read and final guarded transition are the
 *   deciding factors.
 * - Never performs running -> cancelled directly. Cancellation only ever runs
 *   the repository-allowed cancel_requested -> cancelled transition.
 *
 * Notes:
 * - No SQL here. All persistence goes through JobRepository's guarded methods.
 * - No Operation-level transaction. Each lifecycle transition (claim, succeed,
 *   fail, cancel) is committed independently on purpose, so live progress,
 *   heartbeats, and logs remain visible during the run and the claim is durable
 *   before the handler executes. Wrapping these in one transaction would defeat
 *   both goals.
 * - The handler return value must be JSON-encodable. A non-encodable result is
 *   treated as a hard failure (reason code "result_not_encodable") rather than
 *   silently mangled.
 * - Terminal transitions are guarded by the repository. A transition that does
 *   not apply (the row is no longer in an expected state) is normally an
 *   anomaly. The one allowed exception is a cancel request that wins the final
 *   race before success is persisted; that path is completed as cancellation.
 * - A post-claim load failure is handled inside the worker boundary so the
 *   claimed job is driven to a terminal state instead of being left running.
 * - The error message persisted on the job row is UTF-8 substituted and byte-
 *   capped (see MAX_ERROR_MESSAGE_BYTES) to stay within a TEXT column. The full,
 *   unclipped message is written to the job log instead, where JobLogger handles
 *   sanitization and chunking.
 *
 * @see \CitOmni\JobRunner\Repository\JobRepository
 * @see \CitOmni\JobRunner\Value\JobContext
 */
final class RunQueuedJob extends BaseOperation {

	public const RESULT_SUCCEEDED    = 'succeeded';
	public const RESULT_CANCELLED    = 'cancelled';
	public const RESULT_FAILED       = 'failed';
	public const RESULT_CLAIM_FAILED = 'claim_failed';

	/**
	 * Conservative byte cap for the persisted error_message, sized to stay well
	 * within a MySQL TEXT column (max 65535 bytes). The full message still goes
	 * to the job log.
	 */
	private const MAX_ERROR_MESSAGE_BYTES = 60000;

	/**
	 * Run one queued job at the worker boundary.
	 *
	 * @param int    $jobId       Job id to run. Must be >= 1.
	 * @param string $workerToken Raw worker token handed to the CLI worker. It is
	 *                            hashed here and compared against the stored hash;
	 *                            it is never logged or returned.
	 * @return array{
	 *     status:string,
	 *     job_id:int,
	 *     job_uuid?:string,
	 *     job_type?:string,
	 *     error_class?:?string,
	 *     error_reason_code?:?string,
	 *     error_message?:?string
	 * } Domain-shaped result. CLAIM_FAILED carries status, job_id, and
	 *   error_reason_code = "claim_failed".
	 * @throws \InvalidArgumentException When job id or worker token is invalid.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When a guarded terminal
	 *         transition unexpectedly does not apply.
	 */
	public function execute(int $jobId, string $workerToken): array {

		// -- 1. Validate operation input ----------------------------------
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}
		// Do not trim: a worker token is an opaque credential, and altering it
		// would change its hash. Only reject the empty caller-bug case.
		if ($workerToken === '') {
			throw new \InvalidArgumentException('Worker token cannot be empty.');
		}

		$clock = new Clock($this->app);
		$repo  = new JobRepository($this->app);

		// -- 2. Claim the queued job atomically ---------------------------
		// claimQueued() is the only ownership gate and performs queued -> running.
		$workerTokenHash = \hash('sha256', $workerToken);

		if (!$repo->claimQueued($jobId, $workerTokenHash, $clock->now())) {
			// Not queued, already owned, or wrong token. Deterministic, no leak.
			return [
				'status'            => self::RESULT_CLAIM_FAILED,
				'job_id'            => $jobId,
				'error_reason_code' => 'claim_failed',
			];
		}

		// -- 3. Run the handler at the worker boundary --------------------
		// This is the single broad-catch boundary. Everything inside fails fast;
		// the catch only classifies the run into one terminal outcome. The job
		// load lives here too, so a post-claim load failure is driven to a
		// terminal state instead of leaving the claimed job running.
		$jobUuid      = '';
		$jobType      = '';
		$outcome      = self::RESULT_FAILED;
		$resultJson   = null;
		$errorClass   = null;
		$errorReason  = null;
		$errorMessage = null;
		$caught       = null;

		try {
			// Load the claimed job. A successful claim means the row existed; a
			// null here is an anomalous post-claim disappearance, handled as a
			// failure so the worker boundary still drives a terminal transition.
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

			// -- 3a. Distinguish cooperative cancellation from success ----
			// A handler may stop cleanly on a cancel request and return an
			// empty/partial array. The post-run status decides the terminal
			// transition; the handler return value alone never does.
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

		// -- 4. Apply the terminal transition once ------------------------
		// Outside the catch on purpose: a genuine infrastructure failure in a
		// terminal write or log must surface, not be reclassified as a job error.
		// Each guarded transition is checked. A cancel request that wins the final
		// success race is completed as cancellation; other misses are anomalies.
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

		// Failure outcome.
		// Persist a DB-safe error message on the job row; mark failed first so
		// the claimed job reaches a terminal state before writing the failure log.
		$safeMessage = $this->normalizeErrorMessage($errorMessage);

		if (!$repo->markFailed($jobId, $errorClass, $errorReason, $safeMessage, $now)) {
			// The row is no longer in an active state (already terminal or gone),
			// so the failure cannot be recorded. Surface it; the original cause
			// is preserved as the previous exception.
			throw new JobRunnerException(
				\sprintf('Job %d could not transition to failed; unexpected job state.', $jobId),
				0,
				$caught,
				'failed_terminal_transition_failed'
			);
		}

		// Full, unclipped detail goes to the job log (JobLogger sanitizes UTF-8
		// and chunks long output); the job row keeps the capped variant.
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
	 * Repository-allowed path only: cancel_requested -> cancelled. The caller
	 * decides when cancellation is the right outcome; this method only applies
	 * the guarded write, writes the terminal log line, and returns the common
	 * domain-shaped result.
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
	 * Distinguishes malformed JSON from valid JSON that decodes to a non-array,
	 * so failures carry a precise reason code.
	 *
	 * @param string|null $payloadJson Stored payload JSON, or null/empty for no payload.
	 * @return array<string, mixed> Decoded payload, or an empty array when absent.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the stored JSON is
	 *         malformed (invalid_payload_json) or decodes to a non-array
	 *         (invalid_payload_shape).
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
	 * Make a Throwable message safe to store in the job row's error_message.
	 *
	 * A handler may throw with raw external process output in the message, which
	 * can be invalid UTF-8 (common on Windows) or larger than a TEXT column. This
	 * substitutes invalid UTF-8 without mbstring/iconv and caps the result on a
	 * UTF-8 boundary. The full message is logged separately and is not clipped here.
	 *
	 * @param string|null $message Raw exception message, or null.
	 * @return string|null DB-safe message, or null when the input was null.
	 */
	private function normalizeErrorMessage(?string $message): ?string {
		if ($message === null || $message === '') {
			return $message;
		}

		// Substitute invalid UTF-8 so the stored value is always valid UTF-8.
		if (\preg_match('//u', $message) !== 1) {
			$message = $this->substituteInvalidUtf8($message);
		}

		// Cap on a UTF-8 boundary: back off if the cut would land inside a
		// multibyte sequence (the byte at the cut is a continuation byte).
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
	 * Replace invalid UTF-8 byte sequences using only the JSON extension.
	 *
	 * @param string $value Possibly invalid UTF-8.
	 * @return string Valid UTF-8 with invalid sequences substituted, or '' on failure.
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
