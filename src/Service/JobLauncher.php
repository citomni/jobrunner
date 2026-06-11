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

use CitOmni\JobRunner\Exception\JobRunnerException;
use CitOmni\Kernel\Service\BaseService;

/**
 * JobLauncher: Start a detached CLI worker process for one queued job.
 *
 * This service does one thing only: it submits an OS-aware, detached CLI
 * command for the fixed internal worker entrypoint:
 *
 *   php bin/citomni job:run <job-id> --token=<token>
 *
 * It does not claim jobs, resolve handlers, build JobContext, touch the
 * database, or run any job workflow. Job lifecycle and outcome are owned by
 * RunJobCommand / RunQueuedJob and reported through the database. This service
 * never blocks the calling (HTTP) request: it submits the background process
 * and returns immediately.
 *
 * Behavior:
 * - Validates method input and fails fast on invalid input (SPL exceptions).
 * - Resolves the configured CLI entrypoint and fails fast on impossible
 *   launch setup (JobRunnerException).
 * - Builds the command from escaped argument parts; never concatenates raw
 *   dynamic values into the command line.
 * - Submits a detached worker on Windows (`start "" /B`) and Unix-like
 *   (`... > /dev/null 2>&1 &`) without waiting for the worker to finish.
 *
 * Notes:
 * - The only command this service may build is the fixed worker command above.
 *   It is not a general command executor.
 * - The worker token is an opaque credential: it is escaped for the shell, but
 *   it is never trimmed, printed, logged, returned, or placed in any exception
 *   message or return value.
 * - Output is intentionally discarded in V1; worker output goes to the database
 *   via JobLogger, not through captured streams.
 * - No SQL. No transport concerns. No external process libraries.
 *
 * Typical usage:
 *   $submitted = $this->app->jobLauncher->launch($jobId, $rawWorkerToken);
 */
final class JobLauncher extends BaseService {

	private string $phpBinary = 'php';
	private string $cliEntrypoint = 'bin/citomni';

	/**
	 * Initialize cheap, immutable launcher configuration.
	 *
	 * Behavior:
	 * - Reads package-owned launcher cfg. The `jobrunner` node is guaranteed by
	 *   Registry::CFG_COMMON, so `??` on the leaf is the blessed read pattern.
	 * - Validates the static config once and fails fast on objectively invalid
	 *   (empty) values.
	 *
	 * Notes:
	 * - No I/O happens here. Entrypoint resolution and existence checks are
	 *   deferred to launch().
	 *
	 * @return void
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When launcher cfg is objectively invalid.
	 */
	protected function init(): void {
		$phpBinary     = (string)($this->app->cfg->jobrunner->php_binary ?? 'php');
		$cliEntrypoint = (string)($this->app->cfg->jobrunner->cli_entrypoint ?? 'bin/citomni');

		if ($phpBinary === '') {
			throw new JobRunnerException(
				'Launcher config "jobrunner.php_binary" must be a non-empty string.',
				0,
				null,
				'invalid_php_binary'
			);
		}

		if ($cliEntrypoint === '') {
			throw new JobRunnerException(
				'Launcher config "jobrunner.cli_entrypoint" must be a non-empty string.',
				0,
				null,
				'invalid_cli_entrypoint'
			);
		}

		$this->phpBinary     = $phpBinary;
		$this->cliEntrypoint = $cliEntrypoint;
	}


	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Submit a detached CLI worker for one queued job.
	 *
	 * Builds and submits the fixed worker command for the given job id and raw
	 * worker token, then returns immediately. The boolean return reflects only
	 * whether the detached process could be submitted to the OS, not whether the
	 * job will succeed. Job outcome is reported through the database by
	 * RunJobCommand / RunQueuedJob.
	 *
	 * Behavior:
	 * - Fails fast on invalid method input.
	 * - Fails fast on impossible launch setup (entrypoint cannot be resolved or
	 *   does not exist).
	 * - Submits an OS-aware detached process and discards its output.
	 *
	 * Notes:
	 * - The token is not trimmed and is treated as an opaque credential.
	 * - The token is never logged, printed, returned, or included in exceptions.
	 *
	 * @param int    $jobId       Numeric id of the queued job. Must be >= 1.
	 * @param string $workerToken Raw worker token issued at job creation. Must be non-empty.
	 * @return bool True if the detached launch command was submitted; false if the OS could not submit it.
	 * @throws \InvalidArgumentException When $jobId < 1 or $workerToken is empty.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the CLI entrypoint cannot be resolved or does not exist.
	 */
	public function launch(int $jobId, string $workerToken): bool {

		// -- 1. Validate method input (ordinary invalid input -> SPL) -----
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be an integer >= 1.');
		}

		// Do not trim: the worker token is an opaque credential.
		if ($workerToken === '') {
			throw new \InvalidArgumentException('Worker token cannot be empty.');
		}

		// -- 2. Resolve the CLI entrypoint (fail fast on impossible setup) -
		$entrypoint = $this->resolveEntrypoint();

		// -- 3. Submit the OS-aware detached worker process ----------------
		if (\PHP_OS_FAMILY === 'Windows') {
			return $this->launchWindows($entrypoint, $jobId, $workerToken);
		}

		return $this->launchUnix($entrypoint, $jobId, $workerToken);
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Resolve the configured CLI entrypoint to an existing file path.
	 *
	 * Relative entrypoints are resolved against the application root
	 * (CITOMNI_APP_PATH via App::getAppRoot()). Absolute entrypoints are used
	 * as-is. A non-existent entrypoint is treated as an impossible launch setup.
	 *
	 * @return string Absolute, existing path to the CitOmni CLI entrypoint.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the app root is unavailable or the entrypoint does not exist.
	 */
	private function resolveEntrypoint(): string {
		$entrypoint = $this->cliEntrypoint;

		if (!$this->isAbsolutePath($entrypoint)) {
			$appRoot = \rtrim($this->app->getAppRoot(), '/\\');

			if ($appRoot === '') {
				throw new JobRunnerException(
					'Cannot resolve relative "jobrunner.cli_entrypoint": application root is unavailable.',
					0,
					null,
					'app_root_unavailable'
				);
			}

			$entrypoint = $appRoot . \DIRECTORY_SEPARATOR . \ltrim($entrypoint, '/\\');
		}

		if (!\is_file($entrypoint)) {
			throw new JobRunnerException(
				'Configured CitOmni CLI entrypoint does not exist.',
				0,
				null,
				'cli_entrypoint_not_found'
			);
		}

		return $entrypoint;
	}

	/**
	 * Submit a detached worker on Unix-like systems.
	 *
	 * Backgrounds the worker with stdout/stderr redirected to /dev/null and
	 * returns without waiting. The foreground shell that backgrounds the worker
	 * returns immediately, so exec() does not block on the worker itself.
	 *
	 * @param string $entrypoint  Absolute path to the CLI entrypoint.
	 * @param int    $jobId       Validated job id.
	 * @param string $workerToken Raw worker token (escaped for the shell, never logged).
	 * @return bool True if the background command was submitted; false otherwise.
	 */
	private function launchUnix(string $entrypoint, int $jobId, string $workerToken): bool {
		$command =
			\escapeshellarg($this->phpBinary) . ' '
			. \escapeshellarg($entrypoint) . ' '
			. 'job:run '
			. \escapeshellarg((string)$jobId)
			. ' --token=' . \escapeshellarg($workerToken)
			. ' > /dev/null 2>&1 &';

		$output   = [];
		$exitCode = 0;

		// exec() returns false only when the command could not be executed.
		// With the trailing '&', the foreground shell returns immediately.
		$lastLine = \exec($command, $output, $exitCode);

		if ($lastLine === false) {
			return false;
		}

		return $exitCode === 0;
	}

	/**
	 * Submit a detached worker on Windows.
	 *
	 * Uses `start "" /B` so the worker runs without a new window and without
	 * blocking the caller. The first quoted token after `start` is the (empty)
	 * window title; the actual program follows.
	 *
	 * Notes:
	 * - popen() runs the command through `cmd.exe /c`. Because the command
	 *   begins with `start` (not a quote), cmd's quote-stripping heuristic is
	 *   not triggered, so the escaped argument quotes are preserved.
	 *
	 * @param string $entrypoint  Absolute path to the CLI entrypoint.
	 * @param int    $jobId       Validated job id.
	 * @param string $workerToken Raw worker token (escaped for the shell, never logged).
	 * @return bool True if the detached command was submitted; false otherwise.
	 */
	private function launchWindows(string $entrypoint, int $jobId, string $workerToken): bool {
		$command =
			'start "" /B '
			. \escapeshellarg($this->phpBinary) . ' '
			. \escapeshellarg($entrypoint) . ' '
			. 'job:run '
			. \escapeshellarg((string)$jobId)
			. ' --token=' . \escapeshellarg($workerToken)
			. ' > NUL 2>&1';

		$handle = \popen($command, 'r');

		if ($handle === false) {
			return false;
		}

		$exitCode = \pclose($handle);

		return $exitCode === 0;
	}

	/**
	 * Determine whether a path is absolute on the current platform.
	 *
	 * Recognizes Unix roots ("/..."), Windows UNC / rooted paths ("\\..."),
	 * and Windows drive paths ("C:\..." or "C:/...").
	 *
	 * @param string $path Path to inspect.
	 * @return bool True if the path is absolute.
	 */
	private function isAbsolutePath(string $path): bool {
		if ($path === '') {
			return false;
		}

		if ($path[0] === '/' || $path[0] === '\\') {
			return true;
		}

		if (\strlen($path) >= 3 && \ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/')) {
			return true;
		}

		return false;
	}
}
