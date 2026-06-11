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
 * Job lifecycle status.
 *
 * Behavior:
 * - Values are persisted as strings in the database.
 * - Terminal statuses represent jobs that should not be claimed again.
 * - Active statuses represent jobs that may block a matching lock key.
 *
 * Notes:
 * - Keep values stable. Renaming persisted enum values is a migration, not a refactor.
 */
enum JobStatus: string {

	case QUEUED = 'queued';
	case RUNNING = 'running';
	case SUCCEEDED = 'succeeded';
	case FAILED = 'failed';
	case CANCEL_REQUESTED = 'cancel_requested';
	case CANCELLED = 'cancelled';


	/**
	 * Check whether the status is terminal.
	 *
	 * @return bool True when no further worker execution is expected.
	 */
	public function isTerminal(): bool {
		return match ($this) {
			self::SUCCEEDED, self::FAILED, self::CANCELLED => true,
			default => false,
		};
	}


	/**
	 * Check whether the status is active.
	 *
	 * @return bool True when the job should count as active for lock-key checks.
	 */
	public function isActive(): bool {
		return match ($this) {
			self::QUEUED, self::RUNNING, self::CANCEL_REQUESTED => true,
			default => false,
		};
	}


	/**
	 * Return persisted active status values.
	 *
	 * @return list<string> Active status values.
	 */
	public static function activeValues(): array {
		return [
			self::QUEUED->value,
			self::RUNNING->value,
			self::CANCEL_REQUESTED->value,
		];
	}


	/**
	 * Return persisted terminal status values.
	 *
	 * @return list<string> Terminal status values.
	 */
	public static function terminalValues(): array {
		return [
			self::SUCCEEDED->value,
			self::FAILED->value,
			self::CANCELLED->value,
		];
	}

}
