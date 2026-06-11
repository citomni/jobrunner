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

namespace CitOmni\JobRunner\Exception;

/**
 * Base exception for the citomni/jobrunner package.
 *
 * All jobrunner-related exceptions in this package extend this class,
 * allowing callers to catch the entire jobrunner exception family with a
 * single type when appropriate.
 *
 * Supports an optional machine-readable reason code for structured logging,
 * worker failure mapping, status reporting, and tests. The reason code is
 * distinct from the integer exception code inherited from \RuntimeException:
 *
 * - $code (int)                 Standard PHP exception code, rarely used here.
 * - $reasonCode (string|null)   Stable machine-readable failure identifier.
 *
 * Reason codes are useful when the worker boundary needs to store or expose
 * what failed without parsing the human-readable message. They are especially
 * useful for CLI exit mapping, job status summaries, and compact UI feedback.
 *
 * Notes:
 * - Keep this base exception small.
 * - Do not store job payloads, worker tokens, or large context arrays here.
 * - Job-specific details belong in job logs, result data, or repository rows.
 * - Stable reason codes must not be renamed casually. Databases remember.
 *
 * Typical usage:
 *
 *   throw new JobRunnerException(
 *       'Job handler is not registered.',
 *       0,
 *       null,
 *       'handler_not_registered',
 *   );
 */
class JobRunnerException extends \RuntimeException {

	private ?string $reasonCode;


	/**
	 * @param string $message Human-readable error description.
	 * @param int $code Standard PHP exception code.
	 * @param ?\Throwable $previous Previous exception for chaining.
	 * @param ?string $reasonCode Stable machine-readable reason identifier.
	 */
	public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, ?string $reasonCode = null) {
		parent::__construct($message, $code, $previous);
		$this->reasonCode = $reasonCode;
	}


	/**
	 * Return the machine-readable reason code, or null if none was set.
	 *
	 * Reason codes are stable identifiers intended for logs, status summaries,
	 * tests, and programmatic branching. They must not be confused with the
	 * integer exception code from \RuntimeException.
	 *
	 * @return ?string Reason code, or null when no reason code was provided.
	 */
	public function getReasonCode(): ?string {
		return $this->reasonCode;
	}

}
