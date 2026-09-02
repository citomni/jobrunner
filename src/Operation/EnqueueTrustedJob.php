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

use CitOmni\JobRunner\Enum\ClaimMode;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * EnqueueTrustedJob: Create one trusted/pull job without launching a worker.
 *
 * Trusted jobs enter the queue without a worker capability. A trusted
 * supervisor later prepares an ephemeral handoff token and launches a fresh
 * child worker that must win the guarded queued -> running transition.
 *
 * Behavior:
 * - Delegates shared validation, lock handling, payload encoding, UUID creation,
 *   and persistence to EnqueueJob.
 * - Fixes claim mode to TRUSTED and worker_token_hash to null at enqueue.
 * - Does not launch a process and never creates or exposes a handoff token.
 */
final class EnqueueTrustedJob extends BaseOperation {

	public const RESULT_QUEUED         = EnqueueJob::RESULT_QUEUED;
	public const RESULT_ALREADY_ACTIVE = EnqueueJob::RESULT_ALREADY_ACTIVE;

	/**
	 * Enqueue one trusted job.
	 *
	 * @param string $jobType Registered job type.
	 * @param array $payload JSON-encodable payload.
	 * @param string|null $lockKey Optional active-job lock key.
	 * @param string|null $title Optional human-facing title.
	 * @return array{status:string,job_id:int,job_uuid:string,job_type:string,job_status?:string} Domain-shaped queue result.
	 */
	public function execute(string $jobType, array $payload = [], ?string $lockKey = null, ?string $title = null): array {
		return (new EnqueueJob($this->app))->execute(
			$jobType,
			ClaimMode::TRUSTED,
			null,
			$payload,
			$lockKey,
			$title
		);
	}
}
