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
namespace CitOmni\JobRunner\Enum;

/**
 * ClaimMode: Worker ownership model for a JobRunner job.
 *
 * Persisted in jobrun_jobs.claim_mode so token/push and trusted/pull workers
 * remain hard-disjoint at the claim layer.
 *
 * Behavior:
 * - TOKEN jobs receive a worker-token hash when they are enqueued and are
 *   claimed only by the existing token worker path.
 * - TRUSTED jobs are enqueued without a worker-token hash. A trusted supervisor
 *   later prepares an ephemeral handoff hash while the row remains queued, and
 *   the fresh child worker must present the matching token to claim execution.
 *
 * Notes:
 * - Claim mode is fixed at enqueue and never changes during the job lifecycle.
 * - worker_token_hash stores an execution capability hash in both modes; only
 *   the time at which that capability is minted differs.
 */
enum ClaimMode: string {

	case TOKEN   = 'token';
	case TRUSTED = 'trusted';

}
