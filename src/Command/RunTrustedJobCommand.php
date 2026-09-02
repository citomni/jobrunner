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

use CitOmni\JobRunner\Operation\RunTrustedJob;
use CitOmni\Kernel\Command\BaseCommand;

/**
 * RunTrustedJobCommand: Run one prepared trusted JobRunner job.
 *
 * Internal CLI entrypoint for a fresh trusted child process. It accepts the job
 * id plus the raw ephemeral handoff token prepared by the trusted supervisor,
 * delegates claim and execution to RunTrustedJob, and maps the domain result to
 * deterministic CLI output and exit codes.
 *
 * Behavior:
 * - Validates job id >= 1 and a non-empty --token value.
 * - Does not claim, execute handlers, or write SQL directly.
 * - Reports claim failure generically without revealing why the ownership gate
 *   did not apply.
 *
 * Notes:
 * - This command is separate from job:run so the existing token/push worker
 *   contract remains explicit and unchanged.
 * - The raw handoff token is never printed or logged.
 */
final class RunTrustedJobCommand extends BaseCommand {

	/**
	 * Declare the command interface.
	 *
	 * @return array<string,array<string,mixed>> Command signature.
	 */
	protected function signature(): array {
		return [
			'arguments' => [
				'job-id' => [
					'description' => 'Numeric id of the prepared trusted job to run',
					'required'    => true,
					'type'        => 'int',
				],
			],
			'options' => [
				'token' => [
					'type'        => 'string',
					'description' => 'Raw ephemeral trusted handoff token',
					'required'    => true,
				],
			],
		];
	}

	/**
	 * Execute the command.
	 *
	 * @return int Process exit code.
	 */
	protected function execute(): int {
		$jobId = $this->argInt('job-id');
		$token = $this->getString('token');

		if ($jobId < 1) {
			$this->error('Job id must be an integer >= 1.');
			$this->stderr('Usage: job:run-trusted <job-id> --token=<token>');
			return self::USAGE;
		}
		if ($token === '') {
			$this->error('Option --token must not be empty.');
			$this->stderr('Usage: job:run-trusted <job-id> --token=<token>');
			return self::USAGE;
		}

		$result = (new RunTrustedJob($this->app))->execute($jobId, $token);
		$status = (string)($result['status'] ?? '');

		return match ($status) {
			RunTrustedJob::RESULT_SUCCEEDED    => $this->reportSucceeded($result),
			RunTrustedJob::RESULT_CANCELLED    => $this->reportCancelled($result),
			RunTrustedJob::RESULT_CLAIM_FAILED => $this->reportClaimFailed($result),
			RunTrustedJob::RESULT_FAILED       => $this->reportFailed($result),
		};
	}

	/**
	 * Report a successful run.
	 *
	 * @param array<string,mixed> $result Worker result.
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
	 * @param array<string,mixed> $result Worker result.
	 * @return int Process exit code.
	 */
	private function reportCancelled(array $result): int {
		$this->info('Job cancelled.');
		$this->printJobIdentity($result);

		return self::SUCCESS;
	}

	/**
	 * Report a failed trusted claim.
	 *
	 * @param array<string,mixed> $result Worker result.
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
	 * @param array<string,mixed> $result Worker result.
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
	 * Print job identity for non-error outcomes.
	 *
	 * @param array<string,mixed> $result Worker result.
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
