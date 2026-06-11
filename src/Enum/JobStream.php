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
 * Job log stream.
 *
 * Behavior:
 * - NONE is used for normal job messages.
 * - STDOUT and STDERR are used for captured process output excerpts.
 *
 * Notes:
 * - Empty string is deliberate because most log entries are not process streams.
 */
enum JobStream: string {

	case NONE = '';
	case STDOUT = 'stdout';
	case STDERR = 'stderr';

}
