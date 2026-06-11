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

namespace CitOmni\JobRunner\Command;

use CitOmni\JobRunner\Operation\RunQueuedJob;
use CitOmni\Kernel\Command\BaseCommand;

/**
 * RunJobCommand: Run one queued JobRunner job from the terminal.
 *
 * This is the internal CLI worker entrypoint started detached by JobLauncher:
 *   php bin/citomni job:run <job-id> --token=<token>
 *
 * It is a pure CLI adapter. It parses the job id and the raw worker token,
 * delegates the entire worker flow to the RunQueuedJob operation, and maps the
 * returned domain status to concise terminal output and a deterministic exit
 * code.
 *
 * Behavior:
 * - Validates command-level usage that the parser cannot express:
 *   1) job id must be an integer >= 1
 *   2) the worker token must be non-empty
 * - Delegates the worker flow to RunQueuedJob::execute(); does not claim,
 *   resolve handlers, build JobContext, write SQL, or run workflow logic.
 * - Maps the operation status discriminator to output + exit code:
 *   1) succeeded -> stdout, SUCCESS
 *   2) cancelled -> stdout, SUCCESS (clean, intended outcome, not an error)
 *   3) claim_failed -> stderr, FAILURE (does not reveal id vs token mismatch)
 *   4) failed -> stderr, FAILURE (prints error class, reason code, message)
 *
 * Notes:
 * - RunQueuedJob already catches handler failures and returns a domain status,
 *   so this command never duplicates worker-boundary handling and never
 *   catches \Throwable. Terminal-transition failures are unexpected and are
 *   allowed to bubble to the CLI error handler.
 * - The raw worker token is never printed and never logged. It is forwarded
 *   verbatim to RunQueuedJob, which hashes it internally for the claim.
 */
final class RunJobCommand extends BaseCommand {


	// ----------------------------------------------------------------
	// Command interface
	// ----------------------------------------------------------------

	/**
	 * Declare the command interface.
	 *
	 * @return array<string,array<string,mixed>> Command signature.
	 */
	protected function signature(): array {
		return [
			'arguments' => [
				'job-id' => [
					'description' => 'Numeric id of the queued job to run',
					'required'    => true,
					'type'        => 'int',
				],
			],
			'options' => [
				'token' => [
					'type'        => 'string',
					'description' => 'Raw worker token issued at job creation',
					'required'    => true,
				],
			],
		];
	}


	/**
	 * Execute the command.
	 *
	 * @return int Process exit code (SUCCESS, FAILURE, or USAGE).
	 */
	protected function execute(): int {
		$jobId = $this->argInt('job-id');
		$token = $this->getString('token');

		// -- 1. Command-level usage validation not handled by the parser --
		// The parser enforces type (int) and presence (required), but not the
		// >= 1 bound nor a non-empty token value (e.g. --token=).
		if ($jobId < 1) {
			$this->error('Job id must be an integer >= 1.');
			$this->stderr('Usage: job:run <job-id> --token=<token>');
			return self::USAGE;
		}
		if ($token === '') {
			$this->error('Option --token must not be empty.');
			$this->stderr('Usage: job:run <job-id> --token=<token>');
			return self::USAGE;
		}

		// -- 2. Delegate the worker flow to the operation -----------------
		// RunQueuedJob owns claim, dispatch, handler execution, result/error
		// persistence, and the worker-boundary Throwable handling.
		$result = (new RunQueuedJob($this->app))->execute($jobId, $token);

		// -- 3. Map the domain result to output + exit code ---------------
		// No default arm: an unknown status means RunQueuedJob broke its own
		// four-status contract, which must fail loudly via the CLI handler.
		$status = (string)($result['status'] ?? '');

		return match ($status) {
			RunQueuedJob::RESULT_SUCCEEDED    => $this->reportSucceeded($result),
			RunQueuedJob::RESULT_CANCELLED    => $this->reportCancelled($result),
			RunQueuedJob::RESULT_CLAIM_FAILED => $this->reportClaimFailed($result),
			RunQueuedJob::RESULT_FAILED       => $this->reportFailed($result),
		};
	}







	// ----------------------------------------------------------------
	// Result reporting
	// ----------------------------------------------------------------

	/**
	 * Report a successful run.
	 *
	 * @param array<string,mixed> $result RunQueuedJob success result.
	 * @return int Process exit code.
	 */
	private function reportSucceeded(array $result): int {
		$this->success('Job succeeded.');
		$this->printJobIdentity($result);

		return self::SUCCESS;
	}


	/**
	 * Report a cooperatively cancelled run.
	 *
	 * Cancellation is a clean, intended outcome and is not treated as a failure.
	 *
	 * @param array<string,mixed> $result RunQueuedJob cancellation result.
	 * @return int Process exit code.
	 */
	private function reportCancelled(array $result): int {
		$this->info('Job cancelled.');
		$this->printJobIdentity($result);

		return self::SUCCESS;
	}


	/**
	 * Report a failed claim.
	 *
	 * Does not reveal whether the job id or the worker token was wrong; both a
	 * non-existent/already-claimed job and an invalid token resolve to the same
	 * generic message.
	 *
	 * @param array<string,mixed> $result RunQueuedJob claim-failed result.
	 * @return int Process exit code.
	 */
	private function reportClaimFailed(array $result): int {
		$this->error('Job could not be claimed.');

		$jobId = isset($result['job_id']) ? (int)$result['job_id'] : 0;
		if ($jobId > 0) {
			$this->stderr('  job id: ' . $jobId);
		}

		return self::FAILURE;
	}


	/**
	 * Report a failed run.
	 *
	 * @param array<string,mixed> $result RunQueuedJob failure result.
	 * @return int Process exit code.
	 */
	private function reportFailed(array $result): int {
		$this->error('Job failed.');

		$jobId      = isset($result['job_id']) ? (int)$result['job_id'] : 0;
		$jobType    = isset($result['job_type']) ? (string)$result['job_type'] : '';
		$errorClass = isset($result['error_class']) ? (string)$result['error_class'] : '';
		$reasonCode = isset($result['error_reason_code']) ? (string)$result['error_reason_code'] : '';
		$message    = isset($result['error_message']) ? (string)$result['error_message'] : '';

		if ($jobId > 0) {
			$this->stderr('  job id: ' . $jobId);
		}
		if ($jobType !== '') {
			$this->stderr('  type: ' . $jobType);
		}
		if ($errorClass !== '') {
			$this->stderr('  error class: ' . $errorClass);
		}
		if ($reasonCode !== '') {
			$this->stderr('  reason: ' . $reasonCode);
		}
		if ($message !== '') {
			$this->stderr('  message: ' . $message);
		}

		return self::FAILURE;
	}


	/**
	 * Print job identity lines for non-error outcomes (stdout).
	 *
	 * @param array<string,mixed> $result RunQueuedJob result.
	 * @return void
	 */
	private function printJobIdentity(array $result): void {
		$jobId   = isset($result['job_id']) ? (int)$result['job_id'] : 0;
		$jobUuid = isset($result['job_uuid']) ? (string)$result['job_uuid'] : '';
		$jobType = isset($result['job_type']) ? (string)$result['job_type'] : '';

		if ($jobId > 0) {
			$this->stdout('  job id: ' . $jobId);
		}
		if ($jobUuid !== '') {
			$this->stdout('  uuid: ' . $jobUuid);
		}
		if ($jobType !== '') {
			$this->stdout('  type: ' . $jobType);
		}
	}

}
