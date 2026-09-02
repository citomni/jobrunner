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
use CitOmni\JobRunner\Repository\JobRepository;
use CitOmni\JobRunner\Support\Clock;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * WorkTrustedQueue: Supervise the trusted JobRunner queue sequentially.
 *
 * The supervisor never executes application handlers in-process. It prepares one
 * queued trusted job at a time, launches a fresh synchronous CLI child, waits for
 * that child to exit, and then checks the queue again immediately.
 *
 * Behavior:
 * - Selects the oldest queued trusted job through JobRepository.
 * - Mints a fresh 256-bit handoff token and stores only its SHA-256 hash while
 *   the job remains queued.
 * - Launches `job:run-trusted` synchronously through JobLauncher. The child owns
 *   the guarded queued -> running claim and the shared execution lifecycle.
 * - Checks the queue immediately after each child exits; sleep is used only when
 *   the trusted queue is empty or when a failed child leaves the same job queued.
 * - A failed prepare is treated as a benign race (for example cancellation) and
 *   causes an immediate queue re-check without launching a child.
 *
 * Notes:
 * - V1 assumes one intended trusted supervisor. Repository fencing still keeps
 *   duplicate supervisors from producing duplicate handler execution.
 * - Child exit code is not job success. Handlers may fail and correctly persist
 *   a terminal FAILED row while the child exits non-zero; the supervisor should
 *   continue to the next queued job.
 * - Launcher setup failures bubble and stop the supervisor. The job remains
 *   queued so a later correctly configured supervisor can redispatch it.
 * - No SQL and no transport output here.
 */
final class WorkTrustedQueue extends BaseOperation {

	/**
	 * Run the trusted queue supervisor until the process is externally stopped.
	 *
	 * @return void
	 * @throws \UnexpectedValueException When the configured idle sleep is invalid.
	 */
	public function execute(): void {
		$idleSleepSeconds = (int)$this->app->cfg->jobrunner->trusted_worker_idle_sleep_seconds;
		if ($idleSleepSeconds < 1) {
			throw new \UnexpectedValueException('jobrunner.trusted_worker_idle_sleep_seconds must be >= 1.');
		}

		$repo  = new JobRepository($this->app);
		$clock = new Clock($this->app);

		while (true) {
			$jobId = $repo->findNextTrustedQueuedId();

			if ($jobId === null) {
				\sleep($idleSleepSeconds);
				continue;
			}

			$handoffToken     = \bin2hex(\random_bytes(32));
			$handoffTokenHash = \hash('sha256', $handoffToken);

			if (!$repo->prepareTrustedDispatch($jobId, $handoffTokenHash, $clock->now())) {
				$handoffToken = '';
				continue;
			}

			try {
				$exitCode = $this->app->jobLauncher->runTrusted($jobId, $handoffToken);
			} finally {
				$handoffToken = '';
			}

			// A normal handler failure produces a non-zero child exit but leaves
			// the job terminal, so continue immediately. Only back off when the
			// child failed before taking ownership and the row is still queued;
			// otherwise a persistent child/bootstrap problem could hot-loop.
			if ($exitCode !== 0 && $repo->getStatus($jobId) === JobStatus::QUEUED->value) {
				\sleep($idleSleepSeconds);
			}
		}
	}
}
