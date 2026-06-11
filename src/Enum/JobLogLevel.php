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
 * Job log level.
 *
 * Behavior:
 * - Values are persisted as strings in the database.
 * - Levels are intentionally small and boring.
 *
 * Notes:
 * - This is not a PSR logger replacement.
 * - The jobrunner log is a job progress log, not an application-wide log sink.
 */
enum JobLogLevel: string {

	case INFO = 'info';
	case WARNING = 'warning';
	case ERROR = 'error';
	case DEBUG = 'debug';

}
