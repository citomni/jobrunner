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

namespace CitOmni\JobRunner\Service;

use CitOmni\JobRunner\Enum\JobLogLevel;
use CitOmni\JobRunner\Enum\JobStream;
use CitOmni\JobRunner\Repository\JobRepository;
use CitOmni\JobRunner\Support\Clock;
use CitOmni\Kernel\Service\BaseService;

/**
 * JobLogger: Thin write-through service for appending job log entries.
 *
 * The logger is a small facade over JobRepository::appendLog(). It owns only
 * the message-shaping concerns that every caller would otherwise repeat:
 * line-ending normalization, UTF-8 sanitization, JSON context encoding, and
 * byte-bounded chunking of oversized messages. It has no knowledge of
 * handlers, worker processes, CLI transport, or job lifecycle.
 *
 * Behavior:
 * - Level methods (info/warning/error/debug) write with an empty stream.
 * - Stream methods (stdout/stderr) write at INFO level with the stream set;
 *   stream is orthogonal to severity, since stderr output is frequently
 *   non-error (e.g. Composer/Git progress).
 * - Messages are sanitized to valid UTF-8 before insert so encoding noise in
 *   captured process output cannot fail a utf8mb4 INSERT and abort a job.
 * - Messages larger than log_chunk_max_bytes are split into multiple rows;
 *   splitting never breaks a multibyte UTF-8 sequence.
 * - All chunks of one call share a single timestamp; deterministic ordering
 *   is guaranteed by the repository's per-job seq, not by the timestamp.
 *
 * Notes:
 * - No SQL: all persistence is delegated to JobRepository.
 * - No transport concerns.
 * - Timestamps come from the shared Clock (application timezone, DATETIME(6)
 *   precision) so every jobrunner writer agrees on the wall clock.
 * - Context encoding is best-effort: on JSON failure the context is omitted
 *   (stored as NULL) so that a log call can never abort a running job.
 *
 * Typical usage:
 *   $this->app->jobLogger->info($jobId, 'Step started', 'create_app');
 *   $this->app->jobLogger->stdout($jobId, $line, 'composer_update');
 */
final class JobLogger extends BaseService {

	private int $logChunkMaxBytes = 0;

	private Clock $clock;

	private JobRepository $jobRepository;

	/**
	 * Initialize cheap immutable state.
	 *
	 * Behavior:
	 * - Read package-owned chunk size from cfg (baseline ships the default).
	 * - Enforce a hard floor so the no-split-UTF-8 contract is always honorable
	 *   (>= 4, i.e. at least the largest UTF-8 code unit width).
	 * - Build the shared Clock used for persistence timestamps.
	 * - Cache one repository instance for the lifetime of the service.
	 *
	 * @return void
	 * @throws \UnexpectedValueException When jobrunner.log_chunk_max_bytes < 4.
	 * @throws \Exception When locale.timezone is not a valid timezone id.
	 */
	protected function init(): void {
		$max = (int)$this->app->cfg->jobrunner->log_chunk_max_bytes;

		if ($max < 4) {
			throw new \UnexpectedValueException('jobrunner.log_chunk_max_bytes must be >= 4.');
		}

		$this->logChunkMaxBytes = $max;

		$this->clock = new Clock($this->app);
		$this->jobRepository = new JobRepository($this->app);
	}


	// ----------------------------------------------------------------
	// Level-based logging
	// ----------------------------------------------------------------

	/**
	 * Write an informational message.
	 *
	 * @param int          $jobId   Target job id (>= 1).
	 * @param string       $message Log message; oversized messages are chunked.
	 * @param string|null  $stepKey Optional step key the message belongs to.
	 * @param array        $context Optional structured context, JSON-encoded.
	 * @return int Id of the last inserted log row (last chunk when chunked).
	 * @throws \InvalidArgumentException When $jobId < 1.
	 */
	public function info(int $jobId, string $message, ?string $stepKey = null, array $context = []): int {
		return $this->log($jobId, JobLogLevel::INFO, JobStream::NONE, $stepKey, $message, $context);
	}

	/**
	 * Write a warning message.
	 *
	 * @param int          $jobId   Target job id (>= 1).
	 * @param string       $message Log message; oversized messages are chunked.
	 * @param string|null  $stepKey Optional step key the message belongs to.
	 * @param array        $context Optional structured context, JSON-encoded.
	 * @return int Id of the last inserted log row (last chunk when chunked).
	 * @throws \InvalidArgumentException When $jobId < 1.
	 */
	public function warning(int $jobId, string $message, ?string $stepKey = null, array $context = []): int {
		return $this->log($jobId, JobLogLevel::WARNING, JobStream::NONE, $stepKey, $message, $context);
	}

	/**
	 * Write an error message.
	 *
	 * @param int          $jobId   Target job id (>= 1).
	 * @param string       $message Log message; oversized messages are chunked.
	 * @param string|null  $stepKey Optional step key the message belongs to.
	 * @param array        $context Optional structured context, JSON-encoded.
	 * @return int Id of the last inserted log row (last chunk when chunked).
	 * @throws \InvalidArgumentException When $jobId < 1.
	 */
	public function error(int $jobId, string $message, ?string $stepKey = null, array $context = []): int {
		return $this->log($jobId, JobLogLevel::ERROR, JobStream::NONE, $stepKey, $message, $context);
	}

	/**
	 * Write a debug message.
	 *
	 * @param int          $jobId   Target job id (>= 1).
	 * @param string       $message Log message; oversized messages are chunked.
	 * @param string|null  $stepKey Optional step key the message belongs to.
	 * @param array        $context Optional structured context, JSON-encoded.
	 * @return int Id of the last inserted log row (last chunk when chunked).
	 * @throws \InvalidArgumentException When $jobId < 1.
	 */
	public function debug(int $jobId, string $message, ?string $stepKey = null, array $context = []): int {
		return $this->log($jobId, JobLogLevel::DEBUG, JobStream::NONE, $stepKey, $message, $context);
	}


	// ----------------------------------------------------------------
	// Stream-based logging
	// ----------------------------------------------------------------

	/**
	 * Write captured stdout output (INFO level, stdout stream).
	 *
	 * @param int          $jobId   Target job id (>= 1).
	 * @param string       $message Raw stdout text; oversized output is chunked.
	 * @param string|null  $stepKey Optional step key the output belongs to.
	 * @return int Id of the last inserted log row (last chunk when chunked).
	 * @throws \InvalidArgumentException When $jobId < 1.
	 */
	public function stdout(int $jobId, string $message, ?string $stepKey = null): int {
		return $this->log($jobId, JobLogLevel::INFO, JobStream::STDOUT, $stepKey, $message, []);
	}

	/**
	 * Write captured stderr output (INFO level, stderr stream).
	 *
	 * stderr is recorded at INFO level because many tools emit non-error
	 * progress on stderr; the stream column preserves the origin.
	 *
	 * @param int          $jobId   Target job id (>= 1).
	 * @param string       $message Raw stderr text; oversized output is chunked.
	 * @param string|null  $stepKey Optional step key the output belongs to.
	 * @return int Id of the last inserted log row (last chunk when chunked).
	 * @throws \InvalidArgumentException When $jobId < 1.
	 */
	public function stderr(int $jobId, string $message, ?string $stepKey = null): int {
		return $this->log($jobId, JobLogLevel::INFO, JobStream::STDERR, $stepKey, $message, []);
	}


	// ----------------------------------------------------------------
	// Internal write path
	// ----------------------------------------------------------------

	/**
	 * Shape and persist one logical log entry.
	 *
	 * Behavior:
	 * - Validate the job id once at the service boundary.
	 * - Normalize the message and encode context.
	 * - Chunk the message into one or more byte-bounded rows.
	 * - Persist all chunks under a single timestamp; return the last row id.
	 *
	 * Notes:
	 * - An empty message still produces exactly one (empty) log row.
	 *
	 * @param int          $jobId   Target job id (>= 1).
	 * @param JobLogLevel  $level   Severity level.
	 * @param JobStream    $stream  Source stream (NONE for plain messages).
	 * @param string|null  $stepKey Optional step key.
	 * @param string       $message Message text.
	 * @param array        $context Structured context (empty => NULL).
	 * @return int Id of the last inserted log row.
	 * @throws \InvalidArgumentException When $jobId < 1.
	 */
	private function log(int $jobId, JobLogLevel $level, JobStream $stream, ?string $stepKey, string $message, array $context): int {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$message     = $this->normalizeMessage($message);
		$contextJson = $this->encodeContext($context);
		$now         = $this->clock->now();

		$chunks = $this->chunkMessage($message);
		if ($chunks === []) {
			$chunks = [''];
		}

		$lastId = 0;
		foreach ($chunks as $chunk) {
			$lastId = $this->jobRepository->appendLog(
				$jobId,
				$level->value,
				$stream->value,
				$stepKey,
				$chunk,
				$contextJson,
				$now
			);
		}

		return $lastId;
	}

	/**
	 * Normalize a message for safe, consistent storage.
	 *
	 * Behavior:
	 * - Collapses CRLF and bare CR to LF so output captured on Windows and
	 *   Unix worker hosts is stored uniformly.
	 * - Substitutes invalid UTF-8 byte sequences so a utf8mb4 INSERT cannot
	 *   fail on encoding noise from process output and abort a running job.
	 *
	 * Notes:
	 * - The UTF-8 substitution is conditional: the cheap PCRE validity check
	 *   short-circuits the common (valid) path, so the round-trip only runs
	 *   for genuinely malformed input.
	 *
	 * @param string $message Raw message.
	 * @return string Normalized, valid-UTF-8 message.
	 */
	private function normalizeMessage(string $message): string {
		if ($message === '') {
			return '';
		}

		$message = \str_replace(["\r\n", "\r"], "\n", $message);

		// `//u` validates the subject as UTF-8; non-1 result means invalid bytes.
		if (\preg_match('//u', $message) !== 1) {
			$message = $this->substituteInvalidUtf8($message);
		}

		return $message;
	}

	/**
	 * Replace invalid UTF-8 byte sequences with U+FFFD.
	 *
	 * Uses a json_encode/json_decode round-trip with JSON_INVALID_UTF8_SUBSTITUTE
	 * to avoid an mbstring/iconv dependency. On the (practically unreachable)
	 * encode failure the content is dropped rather than risking a failed insert.
	 *
	 * @param string $value Possibly invalid UTF-8 input.
	 * @return string Valid UTF-8 output.
	 */
	private function substituteInvalidUtf8(string $value): string {
		$encoded = \json_encode($value, \JSON_INVALID_UTF8_SUBSTITUTE);
		if ($encoded === false) {
			return '';
		}

		$decoded = \json_decode($encoded);

		return \is_string($decoded) ? $decoded : '';
	}

	/**
	 * Encode structured context to JSON.
	 *
	 * Best-effort by design: invalid UTF-8 is substituted, and any encoding
	 * failure yields NULL so that a log call can never abort a running job.
	 *
	 * @param array $context Structured context.
	 * @return string|null JSON string, or NULL when empty or unencodable.
	 */
	private function encodeContext(array $context): ?string {
		if ($context === []) {
			return null;
		}

		$json = \json_encode(
			$context,
			\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
		);

		return $json === false ? null : $json;
	}

	/**
	 * Split a message into byte-bounded chunks without breaking UTF-8.
	 *
	 * Behavior:
	 * - Returns an empty list for an empty message.
	 * - Returns a single chunk when the message fits within the limit.
	 * - Otherwise cuts at the byte limit, moving the cut back so it lands on a
	 *   UTF-8 code-point boundary.
	 *
	 * Notes:
	 * - The no-split guarantee is unconditional here: input is valid UTF-8
	 *   (normalizeMessage) and the limit is >= 4 (init), i.e. at least the
	 *   largest UTF-8 code-unit width, so every boundary can be reached.
	 * - Pure byte arithmetic; no mbstring/iconv dependency.
	 *
	 * @param string $message Normalized, valid-UTF-8 message.
	 * @return array<int, string> Ordered list of chunks.
	 */
	private function chunkMessage(string $message): array {
		$len = \strlen($message);

		if ($len === 0) {
			return [];
		}
		if ($len <= $this->logChunkMaxBytes) {
			return [$message];
		}

		$chunks = [];
		$offset = 0;
		$max    = $this->logChunkMaxBytes;

		while ($offset < $len) {
			$size = \min($max, $len - $offset);

			// Inspect the first byte *after* the prospective chunk. If it is a
			// UTF-8 continuation byte (10xxxxxx), the boundary falls inside a
			// multibyte sequence, so move the cut back until it lands on a
			// code-point start. Only relevant in the string interior.
			if ($offset + $size < $len) {
				while ($size > 1 && (\ord($message[$offset + $size]) & 0xC0) === 0x80) {
					$size--;
				}
			}

			$chunks[] = \substr($message, $offset, $size);
			$offset  += $size;
		}

		return $chunks;
	}
}
