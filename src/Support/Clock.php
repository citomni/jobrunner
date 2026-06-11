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

namespace CitOmni\JobRunner\Support;

use CitOmni\Kernel\App;

/**
 * Clock: Single application-time source for jobrunner persistence timestamps.
 *
 * Every jobrunner writer (logger, context, worker operation) needs the same
 * wall-clock string when it stores a DATETIME(6) value. Clock is that one
 * definition: it resolves the application timezone (locale.timezone) once and
 * emits microsecond-precision timestamps, so writers cannot drift apart or
 * silently diverge to UTC.
 *
 * Behavior:
 * - Resolves and caches the timezone at construction; never re-reads cfg.
 * - now() returns 'Y-m-d H:i:s.u' (DATETIME(6)-compatible) in that timezone.
 *
 * Notes:
 * - Support layer: app-aware, instantiated explicitly (not a service).
 * - Stateless aside from the cached timezone; safe to construct per worker or
 *   to share a single instance across collaborators.
 * - Holds no App reference after construction; it only needs the timezone.
 *
 * Typical usage:
 *   $clock = new Clock($this->app);
 *   $now   = $clock->now(); // '2026-06-12 14:30:05.123456'
 */
final class Clock {

	/** DATETIME(6) wall-clock format shared across jobrunner tables. */
	private const FORMAT = 'Y-m-d H:i:s.u';

	private readonly \DateTimeZone $timezone;



	/**
	 * @param App $app Application instance, read once for locale.timezone.
	 * @throws \Exception When locale.timezone is not a valid timezone id.
	 */
	public function __construct(App $app) {
		$tz = (string)($app->cfg->locale->timezone ?? \date_default_timezone_get());
		$this->timezone = new \DateTimeZone($tz);
	}


	/**
	 * Current wall-clock time in the application timezone.
	 *
	 * @return string Timestamp formatted as 'Y-m-d H:i:s.u' (DATETIME(6)).
	 */
	public function now(): string {
		return (new \DateTimeImmutable('now', $this->timezone))->format(self::FORMAT);
	}

}
