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

namespace CitOmni\JobRunner\Boot;

/**
 * JobRunner package registry.
 *
 * Declares citomni/jobrunner contributions to the host app:
 * - MAP_COMMON shared service bindings
 * - MAP_HTTP / MAP_CLI mode-specific service bindings
 * - CFG_COMMON shared package defaults
 * - CFG_HTTP / CFG_CLI mode-specific package defaults
 * - ROUTES_HTTP package-owned HTTP routes
 * - COMMANDS_CLI package-owned CLI commands
 *
 * The App boot process merges these definitions into the final runtime.
 *
 * Notes:
 * - Keep this registry intentionally empty until the corresponding runtime code exists.
 * - Add defaults ad hoc, when a service, command, route, or config key has a real consumer.
 * - Routes must remain in ROUTES_HTTP and must never be nested in CFG_HTTP.
 * - Operations, repositories, controllers, and commands are not service-map entries.
 */
final class Registry {

	/**
	 * Shared service map for citomni/jobrunner.
	 *
	 * Keys are resolved as $this->app->{id}.
	 * Values must be FQCN or ['class' => ..., 'options' => ...].
	 *
	 * Keep this map limited to transport-neutral singleton services.
	 */
	public const MAP_COMMON = [

		'jobLogger' => \CitOmni\JobRunner\Service\JobLogger::class,
		'jobRegistry' => \CitOmni\JobRunner\Service\JobRegistry::class,
		'jobLauncher' => \CitOmni\JobRunner\Service\JobLauncher::class,

	];


	/**
	 * Shared cfg overlay for citomni/jobrunner.
	 *
	 * Merged before the current mode-specific cfg overlay.
	 *
	 * Notes:
	 * - These are package defaults only.
	 * - Host apps may override any key at app level.
	 * - Keep transport-neutral jobrunner settings here.
	 * - Do not add config keys before the consuming code exists.
	 */
	public const CFG_COMMON = [
	
		'jobrunner' => [
			'tables' => [
				'jobs' => 'jobrun_jobs',
				'logs' => 'jobrun_logs',
			],
			'handlers' => [
			],			
			'php_binary'     => 'php',
			'cli_entrypoint' => 'bin/citomni',
			'log_chunk_max_bytes' => 16384,
			'trusted_worker_idle_sleep_seconds' => 3,
		],
	
	];


	/**
	 * HTTP service map for citomni/jobrunner.
	 *
	 * This package currently does not expose HTTP-only singleton services by default.
	 * Shared services belong in MAP_COMMON.
	 */
	public const MAP_HTTP = [
	];


	/**
	 * HTTP cfg overlay for citomni/jobrunner.
	 *
	 * Keep minimal unless the package grows HTTP-only defaults.
	 * Shared jobrunner defaults belong in CFG_COMMON.
	 */
	public const CFG_HTTP = [
	];


	/**
	 * HTTP route map for citomni/jobrunner.
	 *
	 * This package does not expose package-owned HTTP routes by default.
	 * Consumer packages and apps may build their own status UI and endpoints.
	 */
	public const ROUTES_HTTP = [
	];


	/**
	 * CLI service map for citomni/jobrunner.
	 *
	 * This package currently does not expose CLI-only singleton services by default.
	 * Shared services belong in MAP_COMMON.
	 */
	public const MAP_CLI = [
	];


	/**
	 * CLI cfg overlay for citomni/jobrunner.
	 *
	 * Keep minimal unless the package grows CLI-only defaults.
	 * Shared jobrunner defaults belong in CFG_COMMON.
	 */
	public const CFG_CLI = [
	];


	/**
	 * CLI command map for citomni/jobrunner.
	 *
	 * Registers package-owned CLI commands for discovery by the CitOmni
	 * command runner. Commands here should stay thin transport adapters and
	 * delegate job lifecycle work to operations and repositories.
	 */
	public const COMMANDS_CLI = [
		'job:run' => [
			'command'     => \CitOmni\JobRunner\Command\RunJobCommand::class,
			'description' => 'Run one queued CitOmni JobRunner job.',
		],
		'job:run-trusted' => [
			'command'     => \CitOmni\JobRunner\Command\RunTrustedJobCommand::class,
			'description' => 'Run one prepared trusted CitOmni JobRunner job.',
		],
		'job:work' => [
			'command'     => \CitOmni\JobRunner\Command\WorkJobsCommand::class,
			'description' => 'Work queued trusted CitOmni JobRunner jobs.',
		],
	];

}
