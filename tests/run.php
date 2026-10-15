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

namespace CitOmni\JobRunner\Tests;

/*
 * Run every suite under tests/, each in its own PHP process: first the isolated
 * suites (tests/<suite>/run.php), then the database suites (tests/<suite>/database.php).
 *
 * Suites replace framework classes with class_alias doubles and install their
 * own error handlers, so they never share a process. Each suite's output is
 * passed through unchanged under a "== <suite>/<script>" heading, which is also
 * the path for running that suite alone. The last line, under "== total", adds
 * up the suites' totals.
 *
 * Usage:
 *   php tests/run.php
 *   php tests/error-handler/run.php    (one suite alone)
 *
 * Notes:
 * - Suites run with the same PHP binary and php.ini as this script, and with -n
 *   when this script runs with -n. -d settings are not passed on.
 * - Environment variables such as CITOMNI_TEST_PARALLEL and CITOMNI_TEST_PASSWORD
 *   reach the suites, because child processes inherit the environment.
 * - Database suites need the test database server. Nothing is skipped when it is
 *   down; those suites fail.
 * - A suite counts as one failure when it exits non-zero with no failed checks,
 *   or when its last line is not a summary (for example after a fatal error).
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Isolated suites first, then database suites; each group in alphabetical order.
$suites = [];
foreach (['run.php', 'database.php'] as $name) {
	$found = \glob(__DIR__ . '/*/' . $name) ?: [];
	\sort($found);
	\array_push($suites, ...$found);
}

if ($suites === []) {
	\fwrite(\STDERR, "FAIL no suites found in tests/*/run.php or tests/*/database.php\n");
	exit(1);
}

// Same php.ini as this process: the loaded file, the default lookup, or none at all.
$iniArgs = match (true) {
	\php_ini_loaded_file() !== false   => ['-c', \php_ini_loaded_file()],
	\php_ini_scanned_files() !== false => [],
	default                            => ['-n'],
};

$passed  = 0;
$failed  = 0;
$skipped = 0;

foreach ($suites as $i => $script) {
	$suite = \basename(\dirname($script)) . '/' . \basename($script);
	\fwrite(\STDOUT, ($i > 0 ? "\n" : '') . "== {$suite}\n");

	// Stdout is piped so the summary line can be read; stderr goes straight through.
	// It is passed as a fresh php://stderr, not as STDERR: proc_open() first seeks a
	// stream to the position PHP recorded for it, 0 for an unused STDERR, which would
	// rewind out.txt in `php tests/run.php > out.txt 2>&1`.
	$proc = \proc_open([\PHP_BINARY, ...$iniArgs, $script], [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['file', 'php://stderr', 'w'],
	], $pipes);
	if (!\is_resource($proc)) {
		throw new \RuntimeException('Cannot start suite: ' . $suite);
	}
	\fclose($pipes[0]);

	$last = '';
	while (($line = \fgets($pipes[1])) !== false) {
		\fwrite(\STDOUT, $line);
		if (\trim($line) !== '') {
			$last = \rtrim($line, "\r\n");
		}
	}
	\fclose($pipes[1]);
	$exit = \proc_close($proc);

	if (\preg_match('/^(\d+) passed, (\d+) failed(?:, (\d+) skipped)?$/', $last, $m) !== 1) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$suite} - exit code {$exit} and no summary line\n");
		continue;
	}

	$passed  += (int)$m[1];
	$failed  += (int)$m[2];
	$skipped += (int)($m[3] ?? 0);

	if ($exit !== 0 && (int)$m[2] === 0) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$suite} - exit code {$exit} although no check failed\n");
	}
}

\fwrite(\STDOUT, "\n== total (" . \count($suites) . (\count($suites) === 1 ? ' suite)' : ' suites)') . "\n");
\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
exit($failed === 0 ? 0 : 1);
