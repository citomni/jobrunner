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
 * JobLauncher: Start fixed internal JobRunner CLI worker processes.
 *
 * This service owns only OS-aware process creation for JobRunner's fixed worker
 * entrypoints. It supports the existing detached token/push worker and the
 * synchronous fresh trusted child used by the persistent trusted supervisor.
 *
 * Fixed worker entrypoints:
 *   php bin/citomni job:run <job-id> --token=<token>
 *   php bin/citomni job:run-trusted <job-id> --token=<handoff-token>
 *
 * It does not claim jobs, resolve handlers, build JobContext, touch persistence,
 * decide job outcomes, or run workflow itself. Those responsibilities remain in
 * Commands, Operations, and JobRepository.
 *
 * Behavior:
 * - launch() submits the token/push `job:run` process detached from the caller and
 *   returns immediately. Its boolean result describes only OS submission, never
 *   the eventual job outcome.
 * - runTrusted() starts one fresh `job:run-trusted` child synchronously and blocks
 *   until that child exits. Its integer result is the child process exit code,
 *   not a JobRunner job status.
 * - Both paths validate the job id and opaque capability without trimming or
 *   otherwise modifying the token.
 * - Both paths resolve the configured CLI entrypoint against App::getAppRoot()
 *   when necessary and fail fast if the entrypoint cannot be used.
 * - Detached launch is implemented with the existing OS-specific shell commands:
 *   `start "" /B` on Windows and a backgrounded command on Unix-like systems.
 * - Trusted launch uses proc_open() with an argv array, avoiding shell parsing of
 *   the trusted child command and waiting for that exact fresh PHP process.
 *
 * Notes:
 * - This is deliberately not a general process runner. Command names and argument
 *   structure are fixed inside this service.
 * - Worker tokens and trusted handoff tokens are opaque execution capabilities.
 *   They are never logged, returned, printed, persisted by this service, or
 *   included in exception messages.
 * - A non-zero trusted child exit code is not automatically a launcher failure:
 *   the child may have correctly persisted a FAILED job or lost its guarded
 *   ownership claim. The supervisor decides what to do after inspecting the
 *   persisted job state.
 * - Worker stdout/stderr are intentionally discarded here. Persistent job output
 *   belongs in JobLogger, not process pipes.
 * - The trusted child is synchronous by design so a single `job:work` supervisor
 *   executes at most one trusted job at a time in V1.
 * - No SQL, transport handling, handler execution, user switching, scheduler
 *   integration, or arbitrary shell execution belongs in this service.
 *
 * Typical usage:
 *   $submitted = $this->app->jobLauncher->launch($jobId, $rawWorkerToken);
 *   $exitCode = $this->app->jobLauncher->runTrusted($jobId, $rawHandoffToken);
 */
final class JobLauncher extends BaseService {

	private string $phpBinary = 'php';
	private string $cliEntrypoint = 'bin/citomni';

	/**
	 * Initialize cheap, immutable launcher configuration.
	 *
	 * Behavior:
	 * - Reads package-owned `jobrunner.php_binary` and
	 *   `jobrunner.cli_entrypoint` defaults/overrides.
	 * - Validates both values once and fails fast when either is empty.
	 *
	 * Notes:
	 * - No filesystem or process I/O happens during service initialization.
	 *   Entrypoint resolution and existence checks are deferred until a worker is
	 *   actually launched.
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
	 * Submit a detached token/push worker for one queued job.
	 *
	 * Builds and submits the fixed `job:run` worker command for the given job id
	 * and raw worker token, then returns without waiting for job completion.
	 *
	 * Behavior:
	 * - Fails fast on invalid method input.
	 * - Resolves the configured CLI entrypoint and fails fast when launch setup is
	 *   impossible.
	 * - Uses the existing OS-specific detached launch path and discards process
	 *   stdout/stderr.
	 *
	 * Notes:
	 * - The boolean return reports process submission only. The child still has to
	 *   win the token-mode queued -> running claim and execute the handler.
	 * - The worker token is not trimmed and is never logged, printed, returned, or
	 *   included in an exception message.
	 *
	 * @param int    $jobId       Numeric id of the queued TOKEN-mode job. Must be >= 1.
	 * @param string $workerToken Raw worker token issued at enqueue. Must be non-empty.
	 * @return bool True when the detached launch command was submitted; false when the OS could not submit it.
	 * @throws \InvalidArgumentException When $jobId < 1 or $workerToken is empty.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When the CLI entrypoint cannot be resolved or does not exist.
	 */
	public function launch(int $jobId, string $workerToken): bool {
		$this->validateWorkerInput($jobId, $workerToken, 'Worker token');

		// Resolve immediately before launch so a stale/missing entrypoint fails
		// loudly at the process boundary rather than during service construction.
		$entrypoint = $this->resolveEntrypoint();

		if (\PHP_OS_FAMILY === 'Windows') {
			return $this->launchWindows($entrypoint, $jobId, $workerToken);
		}

		return $this->launchUnix($entrypoint, $jobId, $workerToken);
	}

	/**
	 * Run one trusted child worker synchronously and return its exit code.
	 *
	 * Starts the fixed `job:run-trusted` command in a fresh PHP process and blocks
	 * until that child exits. The trusted supervisor prepares the ephemeral handoff
	 * hash before calling this method; the child itself remains responsible for the
	 * guarded trusted queued -> running claim.
	 *
	 * Behavior:
	 * - Validates the job id and opaque handoff token without trimming the token.
	 * - Resolves the same configured PHP binary and CLI entrypoint used by the
	 *   detached token launcher.
	 * - Uses proc_open() with an argv array so the trusted child command does not
	 *   pass through shell command parsing.
	 * - Connects stdin/stdout/stderr to the platform null device because persistent
	 *   execution output belongs in JobLogger.
	 * - Waits for the fresh child to exit and returns its process exit code unchanged.
	 *
	 * Notes:
	 * - A non-zero exit code may be a normal, fully persisted JobRunner outcome
	 *   (for example a handler failure) or a failed ownership claim. It is not
	 *   automatically equivalent to process-launch failure.
	 * - Failure to create the child process is exceptional and raises
	 *   JobRunnerException with reason code `trusted_worker_launch_failed`.
	 * - This method does not inspect or mutate job state and does not retry.
	 *
	 * @param int    $jobId        Trusted queued job id. Must be >= 1.
	 * @param string $handoffToken Raw ephemeral handoff capability. Must be non-empty.
	 * @return int Exact child process exit code returned by proc_close().
	 * @throws \InvalidArgumentException When $jobId < 1 or $handoffToken is empty.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When launch setup is invalid or the child process cannot be submitted.
	 */
	public function runTrusted(int $jobId, string $handoffToken): int {
		$this->validateWorkerInput($jobId, $handoffToken, 'Handoff token');

		// Trusted children use the same configured executable/entrypoint as the
		// push path, but are started synchronously without a shell command string.
		$entrypoint = $this->resolveEntrypoint();
		$nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

		$command = [
			$this->phpBinary,
			$entrypoint,
			'job:run-trusted',
			(string)$jobId,
			'--token=' . $handoffToken,
		];
		$descriptors = [
			0 => ['file', $nullDevice, 'r'],
			1 => ['file', $nullDevice, 'w'],
			2 => ['file', $nullDevice, 'w'],
		];
		$pipes = [];

		$process = \proc_open($command, $descriptors, $pipes);

		if (!\is_resource($process)) {
			throw new JobRunnerException(
				'Trusted job worker process could not be launched.',
				0,
				null,
				'trusted_worker_launch_failed'
			);
		}

		return \proc_close($process);
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Validate common worker launch input.
	 *
	 * Tokens are opaque capabilities and therefore are deliberately not trimmed or
	 * normalized. Only the objectively invalid empty value is rejected.
	 *
	 * @param int    $jobId      Job id.
	 * @param string $token      Opaque worker capability.
	 * @param string $tokenLabel Non-secret label used only in validation text.
	 * @return void
	 * @throws \InvalidArgumentException When the job id is invalid or the token is empty.
	 */
	private function validateWorkerInput(int $jobId, string $token, string $tokenLabel): void {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be an integer >= 1.');
		}

		if ($token === '') {
			throw new \InvalidArgumentException($tokenLabel . ' cannot be empty.');
		}
	}

	/**
	 * Resolve the configured CLI entrypoint to an existing file path.
	 *
	 * Relative entrypoints are resolved against the application root
	 * (CITOMNI_APP_PATH via App::getAppRoot()). Absolute entrypoints are used
	 * unchanged. A missing application root or non-existent entrypoint is treated
	 * as impossible launch setup and fails fast before process creation.
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
	 * Submit the detached token worker on Unix-like systems.
	 *
	 * Backgrounds the fixed `job:run` worker with stdout/stderr redirected to
	 * /dev/null. The foreground shell returns after submitting the background
	 * process, so this method does not wait for the job worker to finish.
	 *
	 * @param string $entrypoint  Absolute path to the CLI entrypoint.
	 * @param int    $jobId       Validated job id.
	 * @param string $workerToken Raw worker token; escaped for the shell and never logged.
	 * @return bool True when the background command was submitted successfully.
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

		// With the trailing '&', the foreground shell exits after submitting the
		// worker; this does not wait for the actual job process to complete.
		$lastLine = \exec($command, $output, $exitCode);

		if ($lastLine === false) {
			return false;
		}

		return $exitCode === 0;
	}

	/**
	 * Submit the detached token worker on Windows.
	 *
	 * Uses `start "" /B` so the fixed `job:run` worker is submitted without a new
	 * window and without blocking the caller. The first quoted argument after
	 * `start` is the required empty window title; the PHP executable follows.
	 *
	 * Notes:
	 * - popen() executes through cmd.exe. Because the command begins with `start`
	 *   rather than a quoted executable path, cmd's leading-quote heuristic does
	 *   not consume the escaped PHP/entrypoint argument quotes.
	 *
	 * @param string $entrypoint  Absolute path to the CLI entrypoint.
	 * @param int    $jobId       Validated job id.
	 * @param string $workerToken Raw worker token; escaped for the shell and never logged.
	 * @return bool True when the detached command was submitted successfully.
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
	 * Recognizes Unix roots (`/...`), Windows rooted/UNC-style paths (`\\...`),
	 * and Windows drive paths (`C:\\...` or `C:/...`).
	 *
	 * @param string $path Path to inspect.
	 * @return bool True when the path is absolute.
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
