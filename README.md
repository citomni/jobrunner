# CitOmni JobRunner

DB-backed long-running job execution for CitOmni.

`citomni/jobrunner` is a small CitOmni provider package for explicit long-running jobs that are started from HTTP/UI, persisted in the database, executed by a separate CLI worker, and observed through status, progress, logs, heartbeat, errors, and result data.

The package is deliberately scoped. It is not a generic queue system, not a daemon, not a scheduler, and not a web-based shell for arbitrary commands. Applications start registered and validated job types; the actual workflow lives in PHP job handlers.

Typical use cases include DevKit workflows, imports, exports, deploy steps, rebuilds, report generation, and other explicit administration or developer tasks that are too slow, risky, or verbose to run inside a browser request.

---

## Highlights

- Runs long application-defined jobs outside the HTTP request lifecycle.
- Starts jobs from HTTP, CLI, or other app code through `StartJob`.
- Executes handlers through the CitOmni CLI worker command.
- Exposes job status, progress, logs, results, and errors through `GetJobStatus`.
- Supports cooperative cancellation through `CancelJob`.
- Persists jobs and logs in MySQL using explicit repository-owned SQL.
- Supports active-job locks to prevent duplicate jobs where needed.
- Uses CitOmni's provider model; no custom discovery or framework magic. The rabbit stays in the hat.

---

## Documentation

Start here:

* [JobRunner usage guide](https://github.com/citomni/docs/blob/main/how-to/jobrunner-usage.md)
* [End-to-end smoke test](https://github.com/citomni/docs/blob/main/how-to/jobrunner-end-to-end-smoke-test.md)

Long-form documentation lives in the separate [`citomni/docs`](https://github.com/citomni/docs) repository. This package README stays focused on installation, runtime shape, and the public surface. It is a README, not a furniture warehouse.

---

## Requirements

* PHP 8.2+
* Composer
* CitOmni application using `citomni/kernel`
* CitOmni CLI mode for worker execution
* A database connection supplied through `citomni/infrastructure`

`citomni/jobrunner` must be installed as a Composer dependency. Do not copy package source into an application.

---

## Installation

```bash
composer require citomni/jobrunner
```

Install the database schema from the package:

```text
sql/citomni_jobrunner.sql
```

The schema creates the JobRunner job and log tables used by the repository layer.

---

## What this package provides

### Job lifecycle

* Job creation through `StartJob`
* DB-backed queued/running/cancel_requested/succeeded/failed/cancelled status tracking
* Atomic worker claiming
* Worker token validation
* Heartbeat timestamps
* Step key, label, index, and total tracking
* Final result persistence
* Error summary persistence
* Cooperative cancellation through `CancelJob`
* Status/read model through `GetJobStatus`

### CLI worker execution

* CLI command for running one queued job
* Detached worker launch support
* Handler dispatch by registered job type
* Deterministic worker boundary error handling

### Logging and progress

* Job-level logs
* Structured log levels and streams
* stdout/stderr-oriented log streams where relevant
* Incremental log polling via `afterSeq` / `last_log_seq`
* Bounded log retrieval for UI polling

### Provider integration

* Service registration through `MAP_COMMON`
* Default configuration through `CFG_COMMON`
* CLI command registration through `COMMANDS_CLI`
* No package-specific runtime config discovery
* No parallel merge system

---

## What this package owns

`citomni/jobrunner` owns the generic lifecycle of long-running jobs.

That includes:

* The job record
* Job status
* Job payload persistence
* Job result persistence
* Job error state
* Job log persistence
* Worker token hash
* Worker claim state
* Current step state
* Heartbeat timestamps
* Cancellation request state
* Handler lookup by registered job type
* CLI worker command

This ownership model keeps the runner useful across packages without turning it into the package that knows what every job actually does.

---

## What this package does not own

`citomni/jobrunner` is intentionally not a universal queue, shell, or workflow framework.

It does **not** own:

* DevKit Create App workflow logic
* Commerce import/export logic
* Deploy workflow logic
* Composer-specific workflow logic
* Git-specific workflow logic
* Application-specific business logic
* Arbitrary command execution from HTTP
* Permanent daemon workers
* Multiple queue backends
* Scheduled jobs
* Automatic retry policies
* Distributed worker orchestration
* A polished generic administration UI

This package makes long-running workflows observable and safe to execute outside HTTP requests. It should not become a background-processing theme park with surprise rides.

---

## Runtime model

A typical flow:

```text
HTTP Controller / UI
  -> validates input
  -> creates job through StartJob
  -> starts detached CLI worker
  -> returns job id / status URL

CLI Worker
  -> claims job
  -> resolves registered handler
  -> runs workflow
  -> writes status, logs, steps, heartbeat, and result

HTTP Status UI
  -> polls GetJobStatus
  -> displays progress, logs, error, or result
  -> may request cancellation through CancelJob
```

`citomni/jobrunner` knows how to run a registered job. It does not know what creating an app, importing products, or publishing a deploy means.

Examples:

```text
citomni/devkit
  -> devkit.create_app

citomni/commerce
  -> commerce.import_products
  -> commerce.export_feed
```

---

## Public operations

The package exposes the main runtime surface as transport-agnostic operations.

### `StartJob`

Creates a queued job and launches the worker.

Use it from controllers, commands, or app/package orchestration code after input has been validated by the adapter layer.

### `GetJobStatus`

Reads one job and a bounded log slice for polling UIs.

Typical UI behavior:

```text
GET job status
render status, progress, and logs
store last_log_seq
next poll requests logs after last_log_seq
```

### `CancelJob`

Requests cooperative cancellation.

Implemented behavior:

```text
queued           -> cancelled
running          -> cancel_requested
cancel_requested -> already_cancel_requested
succeeded        -> already_terminal
failed           -> already_terminal
cancelled        -> already_terminal
missing          -> not_found
```

A running job is not killed directly. The handler must check `JobContext::isCancellationRequested()` between meaningful steps and return cleanly. The worker then finalizes the job as `cancelled`.

---

## Registering job handlers

Applications and consumer packages register explicit job types in CitOmni config.

Example app config overlay:

```php
<?php
declare(strict_types=1);

return [
	'jobrunner' => [
		'handlers' => [
			'app.example_import' => \App\JobRunner\ExampleImportJobHandler::class,
		],
	],
];
```

Handlers must implement `JobHandlerInterface`:

```php
<?php
declare(strict_types=1);

namespace App\JobRunner;

use CitOmni\JobRunner\Contract\JobHandlerInterface;
use CitOmni\JobRunner\Value\JobContext;

final class ExampleImportJobHandler implements JobHandlerInterface {
	public function run(JobContext $context): array {
		$context->info('Import started.');

		// Do bounded work here. Repositories still own SQL.

		if ($context->isCancellationRequested()) {
			$context->warning('Import noticed cancellation request.');

			return [
				'cancelled' => true,
			];
		}

		return [
			'cancelled' => false,
			'imported'  => 123,
		];
	}
}
```

Job handlers own the package/application workflow. They should not parse HTTP requests, render responses, or become generic command runners.

---

## Starting jobs from HTTP/UI

The HTTP controller should validate input, create a known job type, and return immediately with a job id or status URL.

The UI should then poll status through app-owned HTTP endpoints backed by `GetJobStatus`.

Recommended app-facing endpoint shape:

```text
POST /admin/jobs/example-import       -> StartJob
GET  /admin/jobs/{id}                 -> GetJobStatus
POST /admin/jobs/{id}/cancel          -> CancelJob
```

`citomni/jobrunner` does not need to own a public generic UI. Applications decide which job types are exposed and who may start or cancel them.

---

## Cancellation

Cancellation is cooperative.

A cancel request changes the persisted job state. It does not forcibly terminate the PHP worker process or any child process. Handlers should check cancellation between meaningful units of work:

```php
if ($context->isCancellationRequested()) {
	$context->warning('Cancellation requested. Stopping after current step.');

	return [
		'cancelled' => true,
	];
}
```

This keeps cancellation predictable and avoids leaving partially written domain state behind. Fewer ghosts in the machine. Also fewer ghosts in the database.

---

## Logs, progress, and results

Handlers should write useful progress information through `JobContext`.

Recommended practices:

* Log meaningful lifecycle events.
* Keep log messages safe; do not write secrets.
* Update steps when the UI benefits from progress display.
* Return a small domain-shaped result array.
* Keep large artifacts outside `result_json` and store references instead.

Polling UIs should request logs incrementally and use `last_log_seq` as the cursor for the next poll.

---

## Database model

`citomni/jobrunner` uses two package-owned tables:

* `jobrun_jobs`
* `jobrun_logs`

The initial schema is supplied in:

```text
sql/citomni_jobrunner.sql
```

The schema includes status, payload, result, error state, worker claim data, heartbeat timestamps, progress fields, logs, and an active-lock mechanism for preventing duplicate active jobs with the same lock key.

---

## Active locks

A job may be created with a lock key to prevent duplicate active work.

Typical use:

```text
job type: devkit.create_app
lock key: devkit.create_app:example-app
```

While a job with the same lock key is active, another matching job should not be started. Once the job reaches a terminal status, the lock becomes available again.

Active statuses are:

```text
queued
running
cancel_requested
```

Terminal statuses are:

```text
succeeded
failed
cancelled
```

---

## Smoke testing

For a reproducible app-local end-to-end smoke test, see:

[CitOmni JobRunner - End-to-End Smoke Test](https://github.com/citomni/docs/blob/main/how-to/jobrunner-end-to-end-smoke-test.md)

The guide verifies the full runtime path from app-local CLI command to `StartJob`, detached CLI worker, registered handler, `JobContext`, persisted logs, heartbeat, steps, result data, active locks, parallel unlocked jobs, `GetJobStatus`, incremental polling, and `CancelJob`.

The smoke handler used by the guide is deliberately app-local. It is not part of the public `citomni/jobrunner` runtime API.

---

## Operational notes

### Long-running jobs

Long-running workflows should not run synchronously in HTTP requests. The HTTP layer should create a job, start the CLI worker, and return immediately.

### Registered job types

Jobs should be started by registered job type, not by raw command input. The runner is a workflow launcher, not a browser-accessible terminal wearing a nice shirt.

### Failure handling

Failed jobs should be visible, inspectable, and boring to diagnose. Boring is a feature.

The worker boundary records failure details on the job. Unrecoverable runtime failures should follow the normal CitOmni error handling model.

### Secrets

Do not place secrets in payloads, logs, result arrays, exception messages, or UI-visible context.

### Cleanup and retention

Cleanup/retention policy is intentionally separate from the core lifecycle. Applications can choose retention rules based on their operational needs.

---

## Performance notes

* Services are resolved through explicit service maps rather than scanning.
* Production should use optimized Composer autoloading.
* OPcache should be enabled in production.
* Runtime behavior should remain explicit and deterministic.
* The runner should prefer simple DB-backed state over external moving parts.

Composer example:

```json
{
	"config": {
		"optimize-autoloader": true,
		"classmap-authoritative": true,
		"apcu-autoloader": true
	}
}
```

Then run:

```bash
composer dump-autoload -o
```

---

## Architecture rules

`citomni/jobrunner` follows the normal CitOmni layer boundaries:

* Controllers own HTTP transport concerns.
* Commands own CLI transport concerns.
* Operations own orchestration.
* Repositories own SQL and persistence.
* Services provide explicit reusable runtime capabilities.
* Job handlers own the package/application workflow being executed.

In particular:

* Do not put SQL in handlers, controllers, commands, operations, or services.
* Do not expose arbitrary command execution from HTTP.
* Do not register untrusted user input as a job type.
* Do not turn JobRunner into a generic queue unless a concrete CitOmni package need proves it.

---

## Contributing

* PHP 8.2+
* PSR-4
* Tabs for indentation
* K&R brace style
* PHPDoc and inline comments in English
* Keep ownership boundaries sharp
* Keep SQL in repositories, transport in controllers/commands, orchestration in operations
* Do not introduce generic queue features without a concrete CitOmni package need
* Do not add arbitrary command execution from HTTP

---

## Coding and documentation conventions

All CitOmni projects follow the shared conventions documented here:

[CitOmni Coding and Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni JobRunner** is open-source under the **MIT License**.
See [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**. Usage of the name or logo must follow the policy in [NOTICE](NOTICE). Do not imply endorsement or affiliation without prior written permission.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.
You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.
Do not register or use "citomni" (or confusingly similar terms) in company names, domains, social handles, or top-level vendor/package names.
For details, see [NOTICE](NOTICE) and [TRADEMARKS.md](TRADEMARKS.md).

---

## Author

Developed by Lars Grove Mortensen © 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.
