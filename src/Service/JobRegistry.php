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

use CitOmni\JobRunner\Contract\JobHandlerInterface;
use CitOmni\JobRunner\Exception\JobRunnerException;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Service\BaseService;

/**
 * JobRegistry: Resolve configured job_type strings to handler instances.
 *
 * Reads the handler map from package-owned cfg (jobrunner.handlers), validates
 * its shape once, and resolves a job type to a concrete JobHandlerInterface on
 * demand. It does not start jobs, run jobs, touch the database, or know any
 * domain-specific (DevKit/Commerce/Deploy) logic.
 *
 * Behavior:
 * - Loads and shape-validates the handler map in init() (cheap, no IO/reflection).
 * - Resolves a job type to a handler class-string from the configured map.
 * - Verifies the class exists and implements JobHandlerInterface before use.
 * - Instantiates handlers with a no-argument constructor.
 * - Caches resolved handler instances per job type within this service instance.
 *
 * Notes:
 * - No SQL. No repositories. No launcher. No worker-flow.
 * - Handler construction takes no arguments: handlers receive the App instance,
 *   payload, logger, step/heartbeat helpers, and cancellation checks through the
 *   JobContext passed to JobHandlerInterface::run() at run-time, so nothing is
 *   needed at construction.
 * - Relies on the CFG_COMMON baseline shipping jobrunner.handlers as a map
 *   (default []), per the package cfg-ownership rule.
 *
 * Typical usage:
 *   if ($this->app->jobRegistry->has($jobType)) {
 *       $handler = $this->app->jobRegistry->get($jobType);
 *       $result  = $handler->run($context);
 *   }
 */
final class JobRegistry extends BaseService {

	/**
	 * Configured handler map: job_type (string) => handler class-string.
	 *
	 * Top-level shape is validated in init(); individual entry validity
	 * (non-empty string, class exists, contract, instantiable) is enforced
	 * lazily in get().
	 *
	 * @var array<string, mixed>
	 */
	private array $handlers = [];


	/**
	 * Resolved handler instances, keyed by job type.
	 *
	 * @var array<string, JobHandlerInterface>
	 */
	private array $handlerCache = [];


	/**
	 * Load and shape-validate the configured handler map once.
	 *
	 * Behavior:
	 * - Reads jobrunner.handlers (package-owned cfg, baseline guaranteed).
	 * - Normalizes a Cfg node to a plain array via Cfg::toArray().
	 * - Rejects anything that is not a job-type-keyed map.
	 *
	 * Notes:
	 * - No reflection, IO, or class loading happens here; entry resolution is
	 *   deferred to get().
	 *
	 * @return void
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When jobrunner.handlers is not a map (reasonCode: invalid_handler_map).
	 */
	protected function init(): void {
		// jobrunner + jobrunner.handlers are guaranteed by the package CFG_COMMON baseline.
		$node = $this->app->cfg->jobrunner->handlers;

		if ($node instanceof Cfg) {
			$node = $node->toArray();
		}

		if (!\is_array($node)) {
			throw new JobRunnerException(
				'Config jobrunner.handlers must be a map of job type to handler class.',
				0,
				null,
				'invalid_handler_map'
			);
		}

		// A handler map must be keyed by job-type strings; a non-empty list is invalid config.
		if ($node !== [] && \array_is_list($node)) {
			throw new JobRunnerException(
				'Config jobrunner.handlers must be keyed by job type, not a list.',
				0,
				null,
				'invalid_handler_map'
			);
		}

		foreach ($node as $jobType => $_handlerClass) {
			if (!\is_string($jobType) || $jobType === '' || \trim($jobType) !== $jobType) {
				throw new JobRunnerException(
					'Config jobrunner.handlers must use non-empty, trimmed job type keys.',
					0,
					null,
					'invalid_handler_map'
				);
			}
		}

		$this->handlers = $node;
	}







	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Check whether a handler is registered for the given job type.
	 *
	 * Behavior:
	 * - Reports key presence in the configured handler map only.
	 * - Does not validate or instantiate the handler; that is get()'s contract.
	 *
	 * @param  string $jobType Job type string (e.g. "devkit.create_app").
	 * @return bool True when a handler entry is configured for the job type.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException When job type is empty (reasonCode: empty_job_type).
	 */
	public function has(string $jobType): bool {
		$jobType = \trim($jobType);

		if ($jobType === '') {
			throw new JobRunnerException('Job type cannot be empty.', 0, null, 'empty_job_type');
		}

		return \array_key_exists($jobType, $this->handlers);
	}


	/**
	 * Resolve a job type to a concrete handler instance.
	 *
	 * Behavior:
	 * - Returns a cached instance when already resolved in this service instance.
	 * - Validates the configured entry, the class, and the contract before use.
	 * - Instantiates the handler with a no-argument constructor.
	 *
	 * @param  string $jobType Job type string (e.g. "devkit.create_app").
	 * @return JobHandlerInterface Resolved handler instance.
	 * @throws \CitOmni\JobRunner\Exception\JobRunnerException
	 *         empty_job_type             When the job type is empty.
	 *         handler_not_registered     When no entry exists for the job type.
	 *         invalid_handler_class      When the entry is not a non-empty class-string.
	 *         handler_class_missing      When the class does not exist.
	 *         handler_contract_mismatch  When the class does not implement JobHandlerInterface.
	 *         handler_instantiation_failed When the constructor throws.
	 */
	public function get(string $jobType): JobHandlerInterface {

		// -- 1. Validate job type -----------------------------------------
		$jobType = \trim($jobType);

		if ($jobType === '') {
			throw new JobRunnerException('Job type cannot be empty.', 0, null, 'empty_job_type');
		}

		if (isset($this->handlerCache[$jobType])) {
			return $this->handlerCache[$jobType];
		}

		// -- 2. Resolve configured entry ----------------------------------
		if (!\array_key_exists($jobType, $this->handlers)) {
			throw new JobRunnerException(
				\sprintf('No handler registered for job type "%s".', $jobType),
				0,
				null,
				'handler_not_registered'
			);
		}

		$class = $this->handlers[$jobType];

		if (!\is_string($class) || \trim($class) === '') {
			throw new JobRunnerException(
				\sprintf('Handler entry for job type "%s" must be a non-empty class-string.', $jobType),
				0,
				null,
				'invalid_handler_class'
			);
		}

		$class = \trim($class);

		// -- 3. Verify class and contract ---------------------------------
		if (!\class_exists($class)) {
			throw new JobRunnerException(
				\sprintf('Handler class "%s" for job type "%s" does not exist.', $class, $jobType),
				0,
				null,
				'handler_class_missing'
			);
		}

		// is_a(..., allow_string: true) verifies the contract at class level, so a
		// successful "new $class()" is guaranteed to be a JobHandlerInterface; no
		// redundant post-instantiation instanceof check is needed.
		if (!\is_a($class, JobHandlerInterface::class, true)) {
			throw new JobRunnerException(
				\sprintf('Handler class "%s" for job type "%s" must implement %s.', $class, $jobType, JobHandlerInterface::class),
				0,
				null,
				'handler_contract_mismatch'
			);
		}

		// -- 4. Instantiate (no-arg constructor) --------------------------
		// Domain exception contract: surface constructor failures as a JobRunnerException
		// with an explicit reason code while preserving the original throwable.
		try {
			$handler = new $class();
		} catch (\Throwable $e) {
			throw new JobRunnerException(
				\sprintf('Failed to instantiate handler "%s" for job type "%s": %s', $class, $jobType, $e->getMessage()),
				0,
				$e,
				'handler_instantiation_failed'
			);
		}

		return $this->handlerCache[$jobType] = $handler;
	}


	/**
	 * Return the configured handler map.
	 *
	 * Behavior:
	 * - Returns the raw configured map (job type => handler class-string).
	 * - Does not instantiate handlers and does not validate individual entries;
	 *   per-entry validation happens in get().
	 *
	 * @return array<string, mixed> Configured handler map.
	 */
	public function all(): array {
		return $this->handlers;
	}


}
