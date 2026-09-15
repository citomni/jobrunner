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

namespace CitOmni\JobRunner\Contract;

use CitOmni\JobRunner\Value\JobContext;

/**
 * JobHandlerInterface: Contract for the workflow behind a single job type.
 *
 * An implementation encapsulates the domain logic for one registered job type
 * (e.g. "devkit.create_app"). The jobrunner owns the generic lifecycle -
 * claiming, status transitions, heartbeat, result persistence, and the logging
 * plumbing - while a handler owns only "what actually happens" for its type.
 *
 * Handlers are resolved from config by job_type and invoked by the worker
 * operation. Everything a handler needs at runtime is supplied through the
 * JobContext: payload, logger, step/heartbeat helpers, cancellation check, and
 * (when required) the App instance. A handler must not read job rows, write
 * status, or touch persistence directly.
 *
 * Result contract:
 * - run() returns domain-shaped result data as an associative array.
 * - The array MUST be JSON-encodable; the runner persists it as result_json
 *   and marks the job succeeded.
 * - Return an empty array when there is no meaningful result payload.
 *
 * Failure contract:
 * - Signal failure by throwing. The worker boundary catches the throwable,
 *   records the error, and marks the job failed.
 * - Do not catch-and-swallow your own failures to fake success; fail fast.
 *
 * Cancellation contract:
 * - V1 cancellation is cooperative. Long, multi-step handlers should consult
 *   the context's cancellation check between steps before starting more work.
 * - Handlers should use the JobContext cancellation API rather than writing
 *   status directly or inventing their own terminal-state handling.
 *
 * Notes:
 * - Stateless by preference: one handler instance may be reused across jobs in
 *   a process, so per-job state belongs in locals or in the JobContext, never
 *   in instance properties.
 * - No transport concerns (no HTTP/CLI shaping) and no SQL belong here.
 *
 * Typical usage:
 *   final class CreateAppJobHandler implements JobHandlerInterface {
 *       public function run(JobContext $context): array {
 *           // ... do the work, log progress, honor cancellation ...
 *           return ['app_path' => $path];
 *       }
 *   }
 */
interface JobHandlerInterface {

	/**
	 * Run one job to completion.
	 *
	 * Implementations perform the full workflow for their job type and return
	 * the domain result on success. Failure is signalled by throwing; the
	 * runner's worker boundary is responsible for recording it.
	 *
	 * @param JobContext $context Per-job context: payload, logger, step and
	 *                            heartbeat helpers, cancellation check, and
	 *                            job metadata (id, uuid, type).
	 * @return array<string, mixed> Domain-shaped, JSON-encodable result data;
	 *                              empty array when there is no result payload.
	 * @throws \Throwable When the job fails; the runner records the error and
	 *                    marks the job failed.
	 */
	public function run(JobContext $context): array;

}
