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

use CitOmni\JobRunner\Operation\WorkTrustedQueue;
use CitOmni\Kernel\Command\BaseCommand;

/**
 * WorkJobsCommand: Work trusted queued jobs from the terminal.
 *
 * Owns only the CLI boundary and delegates the persistent supervisor workflow to
 * WorkTrustedQueue. Unexpected runtime failures are intentionally left to the
 * global CLI error handler.
 *
 * Behavior:
 * - Emits one startup status line for an interactive/manual worker launch.
 * - Runs the trusted queue supervisor until the process is externally stopped.
 * - Returns success only if the supervisor ever returns normally.
 */
final class WorkJobsCommand extends BaseCommand {

	/**
	 * Start the trusted queue supervisor.
	 *
	 * @return int Process exit code.
	 */
	protected function execute(): int {
		$this->info('Trusted JobRunner worker started.');

		(new WorkTrustedQueue($this->app))->execute();

		return self::SUCCESS;
	}
}
