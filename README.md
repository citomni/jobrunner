# CitOmni JobRunner

DB-backed long-running job execution for CitOmni.

`citomni/jobrunner` is a small provider package for explicit long-running jobs with durable state, registered handlers, structured logs, progress, heartbeat, cancellation, and terminal result/error persistence.

JobRunner supports two execution ownership models:

```text
TOKEN / PUSH
StartJob
  -> enqueue token-mode job
  -> launch fresh detached job:run child
  -> child claims queued job with worker token
  -> execute registered handler
  -> persist terminal state

TRUSTED / PULL
EnqueueTrustedJob
  -> enqueue trusted-mode job
  -> persistent job:work supervisor discovers it
  -> prepare ephemeral handoff capability
  -> launch fresh job:run-trusted child
  -> child claims queued job with handoff capability
  -> execute registered handler
  -> persist terminal state
```

Both modes execute the actual application handler in a fresh PHP process.

The package is deliberately scoped. It is not a generic message queue, scheduler, retry framework, distributed worker farm, arbitrary command runner, or general daemon framework.

---

## Highlights

- Runs application-defined long jobs outside request handling.
- Supports token/push execution through `StartJob`.
- Supports trusted/pull execution through `EnqueueTrustedJob` and `job:work`.
- Executes every actual job handler in a fresh PHP process.
- Uses atomic, mode-specific worker claims.
- Preserves opaque capability-based worker ownership in both execution modes.
- Exposes status, progress, logs, result, and errors through `GetJobStatus`.
- Supports cooperative cancellation through `CancelJob`.
- Supports active lock keys to prevent duplicate active work.
- Persists jobs and logs through repository-owned SQL.
- Uses CitOmni provider registration and explicit configuration.

---

## Documentation

Start here:

- [JobRunner usage guide](https://github.com/citomni/docs/blob/main/how-to/jobrunner-usage.md)
- [JobRunner execution ownership architecture](https://github.com/citomni/docs/blob/main/concepts/JobRunner-execution-ownership.md)
- [End-to-end smoke test](https://github.com/citomni/docs/blob/main/how-to/jobrunner-end-to-end-smoke-test.md)

Long-form documentation lives in the separate [`citomni/docs`](https://github.com/citomni/docs) repository.

This README stays focused on package purpose, installation, runtime shape, and the main public surface.

---

## Requirements

- PHP 8.5+
- Composer
- A CitOmni application using `citomni/kernel`
- CitOmni CLI mode for worker execution
- A database connection supplied through `citomni/infrastructure`

`citomni/jobrunner` must be installed as a Composer dependency. Do not copy package source into an application.

---

## Installation

```bash
composer require citomni/jobrunner
```

Install the database schema from:

```text
sql/citomni_jobrunner.sql
```

The schema creates:

```text
jobrun_jobs
jobrun_logs
```

---

## What this package provides

### Durable lifecycle

JobRunner persists:

- Job identity and type.
- Lifecycle status.
- Execution claim mode.
- Optional payload.
- Optional active lock key.
- Progress and heartbeat.
- Result data.
- Error metadata.
- Job logs.
- Execution attempts.
- Lifecycle timestamps.

Current statuses:

```text
queued
running
cancel_requested
succeeded
failed
cancelled
```

Current claim modes:

```text
token
trusted
```

### Execution

JobRunner provides:

- Detached token/push worker launch.
- Persistent trusted queue supervision.
- Fresh trusted child-process launch.
- Atomic token-mode claim.
- Atomic trusted-mode claim.
- Shared post-claim handler execution.
- Deterministic terminal state handling.

### Observability

JobRunner provides:

- Structured job logs.
- stdout/stderr-oriented log streams.
- Step key, label, index, and total.
- Heartbeat timestamps.
- Incremental log polling through `afterSeq` / `last_log_seq`.
- Persisted result and error data.

### Cancellation

JobRunner supports cooperative cancellation:

```text
queued           -> cancelled
running          -> cancel_requested
cancel_requested -> cancelled
```

Handlers observe cancellation through `JobContext::isCancellationRequested()`.

### Provider integration

The package contributes:

```text
MAP_COMMON
  -> jobLogger
  -> jobRegistry
  -> jobLauncher

CFG_COMMON
  -> table names
  -> handler map
  -> PHP binary
  -> CLI entrypoint
  -> log chunk limit
  -> trusted worker idle sleep

COMMANDS_CLI
  -> job:run
  -> job:run-trusted
  -> job:work
```

---

## Execution modes

### Token / push

Use token/push when the producer should immediately launch the dedicated worker and that child will inherit the intended execution environment.

Public operation:

```php
\CitOmni\JobRunner\Operation\StartJob
```

Example:

```php
$result = (new \CitOmni\JobRunner\Operation\StartJob($this->app))->execute(
	'commerce.import_products',
	[
		'source_key' => 'supplier-a',
	],
	'commerce.import_products:supplier-a',
	'Import supplier A products'
);
```

Runtime:

```text
producer
  -> StartJob
  -> token-mode queued row
  -> fresh detached job:run child
  -> atomic token claim
  -> shared execution lifecycle
  -> terminal state
```

`StartJob` mints a cryptographically random worker token, persists only its SHA-256 hash, and passes the raw token only to the fixed worker command.

Possible `StartJob` result statuses:

```text
started
already_active
launch_failed
```

### Trusted / pull

Use trusted/pull when queue production and final execution must be separated and a pre-established trusted supervisor should own dispatch.

Public operation:

```php
\CitOmni\JobRunner\Operation\EnqueueTrustedJob
```

Example:

```php
$result = (new \CitOmni\JobRunner\Operation\EnqueueTrustedJob($this->app))->execute(
	'deploy.publish_app',
	[
		'app_id' => 42,
		'environment' => 'production',
	],
	'deploy.publish_app:42:production',
	'Publish application'
);
```

Runtime:

```text
producer
  -> EnqueueTrustedJob
  -> trusted-mode queued row

job:work
  -> find oldest trusted queued job
  -> mint ephemeral handoff capability
  -> persist/replace capability hash while row remains queued
  -> launch fresh job:run-trusted child synchronously
  -> wait for child
  -> immediately inspect queue again

job:run-trusted
  -> atomic trusted claim
  -> shared execution lifecycle
  -> terminal state
```

Possible `EnqueueTrustedJob` result statuses:

```text
queued
already_active
```

A trusted job may remain queued while no trusted supervisor is running. There is no implicit fallback to token/push execution.

---

## Persistent trusted supervisor

Start the trusted supervisor with:

```bash
php bin/citomni job:work
```

The supervisor:

- Selects the oldest `queued` job with `claim_mode = trusted`.
- Uses `created_at`, then `id`, for deterministic ordering.
- Creates an ephemeral handoff token for the selected job.
- Persists only the SHA-256 hash of that capability.
- Keeps the row in `queued` state during dispatch preparation.
- Starts one fresh `job:run-trusted` child.
- Waits for the child to exit.
- Checks for the next job immediately.
- Sleeps only when the trusted queue is empty.

Default idle sleep:

```text
3 seconds
```

configured through:

```text
jobrunner.trusted_worker_idle_sleep_seconds
```

The intended V1 operating model is one trusted supervisor processing one trusted child at a time.

The supervisor does not execute application handlers in-process.

---

## Fresh process per job

Both execution modes preserve fresh-process isolation.

Token/push:

```text
StartJob
-> fresh job:run process
-> handler
-> process exits
```

Trusted/pull:

```text
persistent job:work supervisor
-> fresh job:run-trusted process
-> handler
-> process exits
```

The persistent supervisor therefore does not retain application handler state between jobs.

Each actual job receives a fresh:

- PHP runtime.
- CitOmni `App`.
- Service graph.
- `JobRegistry`.
- Handler instance.

---

## Worker ownership

Both modes require the concrete child process to prove ownership before handler execution.

### Token-mode claim

The child must match:

```text
job id
status = queued
claim_mode = token
worker_token_hash = SHA-256(raw worker token)
```

### Trusted-mode claim

The child must match:

```text
job id
status = queued
claim_mode = trusted
worker_token_hash = SHA-256(raw handoff token)
```

Only the successful claim changes:

```text
queued -> running
```

and records:

```text
started_at
heartbeat_at
attempts + 1
```

This keeps dispatch attempts separate from actual execution attempts.

For the full ownership and race model, see the [execution ownership architecture](https://github.com/citomni/docs/blob/main/concepts/JobRunner-execution-ownership.md).

---

## Shared execution lifecycle

After a successful claim, both worker types converge on the same execution path.

Conceptually:

```text
RunQueuedJob
  -> token claim
  -> ExecuteRunningJob

RunTrustedJob
  -> trusted claim
  -> ExecuteRunningJob
```

The shared execution lifecycle owns:

- Job loading.
- Payload decoding.
- Handler lookup.
- `JobContext`.
- Handler execution.
- Cancellation resolution.
- Result encoding.
- Failure persistence.
- Terminal transitions.
- Terminal logging.

Token/push and trusted/pull therefore differ in ownership acquisition, not handler semantics.

---

## Public operations

### `StartJob`

Creates a token-mode queued job and launches its detached worker.

Use when immediate push execution is appropriate.

### `EnqueueTrustedJob`

Creates a trusted-mode queued job without launching a worker.

Use when a separately running `job:work` supervisor should dispatch the job.

### `GetJobStatus`

Reads one job, a bounded log slice, result data, and error data.

Use `last_log_seq` as the next incremental polling cursor.

### `CancelJob`

Cancels queued jobs immediately or requests cooperative cancellation for running jobs.

---

## Internal and operational CLI commands

### `job:run`

Internal token/push worker entrypoint:

```text
php bin/citomni job:run <job-id> --token=<worker-token>
```

Normal application code should use `StartJob`, not construct this command.

### `job:run-trusted`

Internal trusted child entrypoint:

```text
php bin/citomni job:run-trusted <job-id> --token=<handoff-token>
```

Normal application code should not construct this command. `job:work` owns trusted dispatch.

### `job:work`

Operational trusted supervisor:

```text
php bin/citomni job:work
```

Run it under the process identity and environment intended to execute trusted jobs.

JobRunner does not switch operating-system users or install/manage host services.

---

## Registering job handlers

Register explicit job types in CitOmni config:

```php
<?php
declare(strict_types=1);

return [
	'jobrunner' => [
		'handlers' => [
			'commerce.import_products' => \App\JobRunner\ImportProductsJobHandler::class,
		],
	],
];
```

Handlers must implement:

```php
\CitOmni\JobRunner\Contract\JobHandlerInterface
```

Example:

```php
<?php
declare(strict_types=1);

namespace App\JobRunner;

use CitOmni\JobRunner\Contract\JobHandlerInterface;
use CitOmni\JobRunner\Value\JobContext;

final class ImportProductsJobHandler implements JobHandlerInterface {
	public function run(JobContext $context): array {
		$payload = $context->payload();

		$sourceKey = (string)($payload['source_key'] ?? '');
		if ($sourceKey === '') {
			throw new \InvalidArgumentException('Payload source_key is required.');
		}

		$context->info('Import started.', [
			'source_key' => $sourceKey,
		]);

		$context->setStep('import', 'Importing products.', 1, 1);

		if ($context->isCancellationRequested()) {
			return [
				'cancelled' => true,
			];
		}

		// Perform the application workflow here.
		// Persistence remains in repositories.

		return [
			'cancelled' => false,
			'source_key' => $sourceKey,
		];
	}
}
```

Handlers are currently instantiated without constructor arguments.

Use:

```php
$context->app();
```

when the handler needs application services, repositories, or config.

---

## `JobContext`

Handlers use `JobContext` for job-aware execution.

Identity and payload:

```php
$context->app();
$context->jobId();
$context->jobUuid();
$context->jobType();
$context->payload();
```

Logging:

```php
$context->info('Message.');
$context->warning('Message.');
$context->error('Message.');
$context->debug('Message.');

$context->stdout('Process output.');
$context->stderr('Process warning.');
```

Progress:

```php
$context->setStep(
	'import',
	'Importing products.',
	2,
	5
);
```

Heartbeat:

```php
$context->heartbeat();
```

Cancellation:

```php
if ($context->isCancellationRequested()) {
	return [
		'cancelled' => true,
	];
}
```

Cancellation is cooperative. Check between bounded work units.

---

## Active locks

A job may use a logical lock key to prevent duplicate active work.

Active statuses:

```text
queued
running
cancel_requested
```

Terminal statuses:

```text
succeeded
failed
cancelled
```

Example:

```text
job type: deploy.publish_app
lock key: deploy.publish_app:42:production
```

The generated `active_lock_key` and its unique index provide the authoritative duplicate guard.

The application-level pre-check exists as a friendly fast path, not as the final race guard.

Both execution modes share the same active-lock behavior.

---

## Status and incremental logs

Use:

```php
\CitOmni\JobRunner\Operation\GetJobStatus
```

Example:

```php
$status = (new \CitOmni\JobRunner\Operation\GetJobStatus($this->app))->execute(
	$jobId,
	$afterSeq,
	200
);
```

For incremental polling:

```text
next afterSeq = last_log_seq
```

Do not add one manually.

The underlying query already uses:

```text
seq > afterSeq
```

The public status read model includes lifecycle state, progress, timestamps, logs, result, and errors without exposing worker ownership material.

---

## Cancellation

Use:

```php
\CitOmni\JobRunner\Operation\CancelJob
```

Current behavior:

```text
queued           -> cancelled
running          -> cancel_requested
cancel_requested -> already_cancel_requested
succeeded        -> already_terminal
failed           -> already_terminal
cancelled        -> already_terminal
missing          -> not_found
```

A running job is not hard-killed by `CancelJob`.

The handler cooperates by checking:

```php
$context->isCancellationRequested()
```

between meaningful work units.

---

## Database model

`jobrun_jobs` contains lifecycle and ownership state.

Important fields include:

```text
job_uuid
job_type
status
claim_mode
lock_key
payload_json
result_json
error_class
error_reason_code
error_message
current_step_key
current_step_label
step_index
step_total
worker_token_hash
attempts
created_at
queued_at
started_at
heartbeat_at
finished_at
updated_at
active_lock_key
```

The database enforces claim mode values:

```text
token
trusted
```

and token-mode jobs must carry a worker-token hash.

Trusted jobs are enqueued without one and receive a temporary handoff hash during trusted dispatch preparation.

`jobrun_logs` provides deterministic per-job sequence ordering.

---

## Runtime configuration

Default package configuration includes:

```php
'jobrunner' => [
	'tables' => [
		'jobs' => 'jobrun_jobs',
		'logs' => 'jobrun_logs',
	],
	'handlers' => [
	],
	'php_binary' => 'php',
	'cli_entrypoint' => 'bin/citomni',
	'log_chunk_max_bytes' => 16384,
	'trusted_worker_idle_sleep_seconds' => 3,
],
```

Applications normally override handler registration and only change launcher/runtime settings when their environment requires it.

---

## Security guidance

### Registered intent, not arbitrary commands

Expose bounded registered job types.

Good:

```text
deploy.publish_app
commerce.import_products
reports.build_monthly_summary
```

Do not make JobRunner a generic transport for arbitrary command strings.

### Keep payloads narrow

Prefer intent:

```php
[
	'app_id' => 42,
	'environment' => 'production',
]
```

over arbitrary execution details.

Handlers should resolve authoritative hosts, paths, credentials, and options from application-owned data.

### Do not persist secrets in JobRunner

Do not place raw secrets in:

```text
payload_json
result_json
job logs
error messages
titles
lock keys
```

Prefer stable references or identifiers and resolve the real credential inside the intended execution context.

### Treat trusted queue writers as privileged action producers

Trusted/pull separates queue production from execution, but a caller that can enqueue a trusted registered job can request the action represented by that job type.

Authentication, authorization, and payload validation remain application responsibilities.

---

## What this package owns

`citomni/jobrunner` owns:

- Generic job lifecycle.
- Claim mode persistence.
- Worker ownership gates.
- Trusted queue dispatch protocol.
- Worker process launch mechanics for fixed internal worker commands.
- Handler lookup.
- `JobContext`.
- Job logs.
- Progress and heartbeat persistence.
- Cancellation request state.
- Result/error persistence.
- Terminal transitions.

---

## What this package does not own

JobRunner does not own:

- Application workflow semantics.
- HTTP authentication or authorization.
- Application-specific routes or UI.
- Arbitrary shell execution.
- Credential storage.
- Operating-system user switching.
- Operating-system service/scheduler installation.
- Scheduled job creation.
- Automatic retry policy.
- Multiple queue backends.
- Distributed worker coordination.
- Parallel trusted worker pools.
- Domain-specific persistence.

The host environment decides how `job:work` is started and kept alive.

Consumer packages decide what registered jobs actually do.

---

## Operational notes

### Token/push

A successful `StartJob` normally launches the fresh worker immediately.

If the environment cannot submit the detached process, JobRunner returns or surfaces launch failure. It does not silently run the job inline.

### Trusted/pull

A trusted job may remain queued until `job:work` is available.

This is normal trusted/pull behavior.

The supervisor should run in the execution environment intended for trusted jobs.

### One trusted supervisor

The current V1 model is one trusted supervisor and one trusted child at a time.

The atomic child claim still protects against duplicate execution ownership, but deliberately running several supervisors is outside the intended V1 operating model.

### Failed trusted child

A handler failure may cause `job:run-trusted` to exit non-zero after correctly persisting the job as `failed`.

`job:work` continues to later jobs.

### Cleanup and retention

Cleanup and retention policy remain outside the core lifecycle.

Applications may define retention rules appropriate to their operational needs.

---

## Troubleshooting

### Token job stays queued

Check:

```text
jobrunner.php_binary
jobrunner.cli_entrypoint
job:run command registration
process launch permissions
```

Token/push expects the dedicated child to be submitted immediately.

### Trusted job stays queued

First verify:

```text
php bin/citomni job:work
```

is running.

Then check:

```text
database access
job:run-trusted command registration
jobrunner.php_binary
jobrunner.cli_entrypoint
handler registration
claim_mode = trusted
```

Do not manually mark the row `running`. The child must win the guarded claim.

### Job fails immediately

Inspect through `GetJobStatus`:

```text
error.class
error.reason_code
error.message
logs
```

Common causes include invalid payload, missing handler, handler contract mismatch, handler exception, or non-encodable result.

### Cancellation is slow

The handler is probably not checking:

```php
$context->isCancellationRequested();
```

frequently enough.

### Incremental logs repeat or skip

Use:

```text
next afterSeq = last_log_seq
```

exactly as returned.

---

## Smoke testing

For the existing app-local end-to-end smoke-test guide, see:

[CitOmni JobRunner - End-to-End Smoke Test](https://github.com/citomni/docs/blob/main/how-to/jobrunner-end-to-end-smoke-test.md)

For current practical usage of both execution modes, see:

[CitOmni JobRunner - Usage, Execution Modes, Handlers, Status, and Cancellation](https://github.com/citomni/docs/blob/main/how-to/jobrunner-usage.md)

For the detailed trusted/pull ownership model, capability handoff, fencing, race handling, and fresh-process rationale, see:

[JobRunner Execution Ownership Architecture](https://github.com/citomni/docs/blob/main/concepts/JobRunner-execution-ownership.md)

---

## Architecture rules

`citomni/jobrunner` follows normal CitOmni boundaries:

- Controllers own HTTP transport.
- Commands own CLI transport.
- Operations own orchestration.
- Repositories own SQL and persistence.
- Services provide reusable App-aware runtime tools.
- Job handlers own application/package workflow.

In particular:

- Keep SQL in repositories.
- Keep transport shaping in controllers and commands.
- Keep queue/execution orchestration in operations.
- Keep process launch mechanics in `JobLauncher`.
- Do not expose arbitrary command execution.
- Do not put raw credentials in JobRunner persistence.
- Do not add generic queue machinery without a concrete requirement.

---

## Performance notes

- Service resolution uses explicit CitOmni maps.
- Trusted idle polling performs a small indexed lookup and sleeps when no work exists.
- Busy trusted queues are drained without an intentional idle delay between jobs.
- Fresh PHP process startup is deliberately retained for per-job runtime isolation.
- No distributed queue infrastructure is required for the current model.
- Production should use normal PHP/Composer optimization appropriate to the application.

---

## Contributing

- PHP 8.5+
- PSR-1 / PSR-4
- Tabs for indentation
- K&R brace style
- PHPDoc and inline comments in English
- Keep ownership boundaries explicit
- Keep SQL in repositories
- Keep transport in controllers/commands
- Keep orchestration in operations
- Avoid speculative queue, retry, or parallel-worker machinery

Shared conventions:

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
