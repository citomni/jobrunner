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

use CitOmni\JobRunner\Repository\JobRepository;
use CitOmni\JobRunner\Support\Clock;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * RunTrustedJob: Claim and run one prepared trusted queued job.
 *
 * This is the trusted child-worker boundary. A supervisor prepares an ephemeral
 * handoff token hash while the job remains queued, then starts a fresh PHP
 * process with the raw token. This Operation hashes that token and atomically
 * performs the trusted queued -> running ownership transition before delegating
 * to the shared execution lifecycle.
 *
 * Behavior:
 * - Claims only claim_mode='trusted' through claimTrustedQueued().
 * - Requires the prepared handoff hash to match.
 * - Returns CLAIM_FAILED without distinguishing stale token, cancellation,
 *   already-claimed state, or a missing job.
 * - Delegates successful claims to ExecuteRunningJob.
 *
 * Notes:
 * - The handoff token is opaque and is never trimmed, logged, or returned.
 * - A second child cannot execute the same job after the first child wins the
 *   queued -> running transition.
 * - No SQL here. All persistence goes through JobRepository.
 *
 * @see \CitOmni\JobRunner\Operation\ExecuteRunningJob
 * @see \CitOmni\JobRunner\Repository\JobRepository
 */
final class RunTrustedJob extends BaseOperation {

	public const RESULT_SUCCEEDED    = ExecuteRunningJob::RESULT_SUCCEEDED;
	public const RESULT_CANCELLED    = ExecuteRunningJob::RESULT_CANCELLED;
	public const RESULT_FAILED       = ExecuteRunningJob::RESULT_FAILED;
	public const RESULT_CLAIM_FAILED = 'claim_failed';

	/**
	 * Run one prepared trusted queued job.
	 *
	 * @param int    $jobId        Job id to run. Must be >= 1.
	 * @param string $handoffToken Raw ephemeral handoff token from the supervisor.
	 * @return array{
	 *     status:string,
	 *     job_id:int,
	 *     job_uuid?:string,
	 *     job_type?:string,
	 *     error_class?:?string,
	 *     error_reason_code?:?string,
	 *     error_message?:?string
	 * } Domain-shaped worker result.
	 * @throws \InvalidArgumentException When job id or handoff token is invalid.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When execution reaches
	 *         an unexpected terminal-transition state.
	 */
	public function execute(int $jobId, string $handoffToken): array {

		// -- 1. Validate operation input ----------------------------------
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}
		if ($handoffToken === '') {
			throw new \InvalidArgumentException('Handoff token cannot be empty.');
		}

		$clock = new Clock($this->app);
		$repo  = new JobRepository($this->app);

		// -- 2. Claim the trusted queued job ------------------------------
		$handoffTokenHash = \hash('sha256', $handoffToken);

		if (!$repo->claimTrustedQueued($jobId, $handoffTokenHash, $clock->now())) {
			return [
				'status'            => self::RESULT_CLAIM_FAILED,
				'job_id'            => $jobId,
				'error_reason_code' => 'claim_failed',
			];
		}

		// -- 3. Execute the shared already-running lifecycle --------------
		return (new ExecuteRunningJob($this->app))->execute($jobId);
	}

}
