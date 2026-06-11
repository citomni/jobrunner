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

namespace CitOmni\JobRunner\Value;

use CitOmni\JobRunner\Enum\JobStatus;
use CitOmni\JobRunner\Repository\JobRepository;
use CitOmni\JobRunner\Support\Clock;
use CitOmni\Kernel\App;

/**
 * JobContext: Per-job runtime facade handed to a job handler.
 *
 * JobContext is the single surface a handler uses while it runs. It carries the
 * job's immutable metadata and exposes the runner's during-execution
 * capabilities - logging, step progress, heartbeat, and the cooperative
 * cancellation check - without letting the handler touch SQL, status
 * transitions, or transport. The worker operation (RunQueuedJob) constructs one
 * instance per job and passes it to JobHandlerInterface::run().
 *
 * Responsibilities exposed (mirrors the handler contract):
 * - Metadata: jobId(), jobUuid(), jobType(), payload(), app().
 * - Logging: info(), warning(), error(), debug(), stdout(), stderr().
 * - Step progress: setStep().
 * - Liveness: heartbeat().
 * - Cancellation: isCancellationRequested().
 *
 * Behavior:
 * - Logging is auto-bound to this job id and to the current step key, so a
 *   handler never repeats the job id and per-step logs are tagged for free.
 * - Persistence calls (step/heartbeat/cancellation) are delegated to the
 *   injected JobRepository; this class contains no SQL.
 * - setStep() and heartbeat() are intentionally orthogonal, mirroring the
 *   repository: advancing a step does NOT refresh the heartbeat. Long steps
 *   must call heartbeat() periodically or the read-model may flag is_stale.
 *
 * Notes:
 * - Not a service: instantiated explicitly by the worker, never via the
 *   service map.
 * - The logger is reached through the service map ($this->app->jobLogger); the
 *   repository is injected because repositories are not service-map singletons.
 * - Timestamps come from the injected Clock, so JobContext, JobLogger, and the
 *   worker operation can share the same app-time DATETIME(6) convention.
 *
 * Typical usage (inside a handler):
 *   $context->setStep('clone', 'Cloning repository', 1, 4);
 *   $context->info('Starting clone');
 *   if ($context->isCancellationRequested()) {
 *       // Stop before starting the next step.
 *       return [];
 *   }
 *   $context->heartbeat();
 */
final class JobContext {

	private readonly App $app;

	private readonly JobRepository $jobRepository;

	private readonly int $jobId;

	private readonly string $jobUuid;

	private readonly string $jobType;

	private readonly array $payload;

	private readonly Clock $clock;

	private ?string $currentStepKey = null;

	private ?string $currentStepLabel = null;

	private ?int $currentStepIndex = null;

	private ?int $currentStepTotal = null;



	/**
	 * @param App           $app           Application instance.
	 * @param JobRepository $jobRepository Repository the worker already owns.
	 * @param Clock         $clock         Shared time source for timestamps.
	 * @param int           $jobId         Persisted job id (>= 1).
	 * @param string        $jobUuid       Public job uuid (non-empty).
	 * @param string        $jobType       Registered job type (non-empty).
	 * @param array         $payload       Decoded payload (from payload_json).
	 * @throws \InvalidArgumentException When id/uuid/type are invalid.
	 */
	public function __construct(App $app, JobRepository $jobRepository, Clock $clock, int $jobId, string $jobUuid, string $jobType, array $payload) {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$jobUuid = \trim($jobUuid);
		if ($jobUuid === '') {
			throw new \InvalidArgumentException('Job uuid cannot be empty.');
		}

		$jobType = \trim($jobType);
		if ($jobType === '') {
			throw new \InvalidArgumentException('Job type cannot be empty.');
		}

		$this->app           = $app;
		$this->jobRepository = $jobRepository;
		$this->clock         = $clock;
		$this->jobId         = $jobId;
		$this->jobUuid       = $jobUuid;
		$this->jobType       = $jobType;
		$this->payload       = $payload;
	}





	// ----------------------------------------------------------------
	// Metadata
	// ----------------------------------------------------------------

	/**
	 * @return App The application instance, for handlers that need wider access.
	 */
	public function app(): App {
		return $this->app;
	}


	/**
	 * @return int Persisted job id.
	 */
	public function jobId(): int {
		return $this->jobId;
	}


	/**
	 * @return string Public job uuid.
	 */
	public function jobUuid(): string {
		return $this->jobUuid;
	}


	/**
	 * @return string Registered job type.
	 */
	public function jobType(): string {
		return $this->jobType;
	}


	/**
	 * @return array Decoded job payload (the deserialized payload_json).
	 */
	public function payload(): array {
		return $this->payload;
	}






	// ----------------------------------------------------------------
	// Logging (auto-bound to this job and the current step)
	// ----------------------------------------------------------------

	/**
	 * Log an informational message under the current step.
	 *
	 * @param string $message Message text.
	 * @param array  $context Optional structured context.
	 * @return int Id of the last inserted log row.
	 */
	public function info(string $message, array $context = []): int {
		return $this->app->jobLogger->info($this->jobId, $message, $this->currentStepKey, $context);
	}


	/**
	 * Log a warning message under the current step.
	 *
	 * @param string $message Message text.
	 * @param array  $context Optional structured context.
	 * @return int Id of the last inserted log row.
	 */
	public function warning(string $message, array $context = []): int {
		return $this->app->jobLogger->warning($this->jobId, $message, $this->currentStepKey, $context);
	}


	/**
	 * Log an error message under the current step.
	 *
	 * @param string $message Message text.
	 * @param array  $context Optional structured context.
	 * @return int Id of the last inserted log row.
	 */
	public function error(string $message, array $context = []): int {
		return $this->app->jobLogger->error($this->jobId, $message, $this->currentStepKey, $context);
	}


	/**
	 * Log a debug message under the current step.
	 *
	 * @param string $message Message text.
	 * @param array  $context Optional structured context.
	 * @return int Id of the last inserted log row.
	 */
	public function debug(string $message, array $context = []): int {
		return $this->app->jobLogger->debug($this->jobId, $message, $this->currentStepKey, $context);
	}


	/**
	 * Record captured stdout output under the current step.
	 *
	 * @param string $message Raw stdout text.
	 * @return int Id of the last inserted log row.
	 */
	public function stdout(string $message): int {
		return $this->app->jobLogger->stdout($this->jobId, $message, $this->currentStepKey);
	}


	/**
	 * Record captured stderr output under the current step.
	 *
	 * @param string $message Raw stderr text.
	 * @return int Id of the last inserted log row.
	 */
	public function stderr(string $message): int {
		return $this->app->jobLogger->stderr($this->jobId, $message, $this->currentStepKey);
	}






	// ----------------------------------------------------------------
	// Progress, liveness, cancellation
	// ----------------------------------------------------------------

	/**
	 * Advance the current step and persist it.
	 *
	 * Updates the in-memory step key (so subsequent log calls are tagged) and
	 * writes the step to the job row. Does NOT refresh the heartbeat; call
	 * heartbeat() separately during long-running steps.
	 *
	 * @param string|null $key   Machine step key (e.g. "composer_update").
	 * @param string|null $label Human-readable step label.
	 * @param int|null    $index 1-based step index, when known.
	 * @param int|null    $total Total step count, when known.
	 * @return bool True when the job row was updated (false when no longer running).
	 */
	public function setStep(?string $key, ?string $label = null, ?int $index = null, ?int $total = null): bool {
		$this->currentStepKey   = $key;
		$this->currentStepLabel = $label;
		$this->currentStepIndex = $index;
		$this->currentStepTotal = $total;

		return $this->jobRepository->updateStep($this->jobId, $key, $label, $index, $total, $this->clock->now());
	}


	/**
	 * Refresh the job heartbeat to signal liveness.
	 *
	 * Should be called periodically inside long steps so the read-model does
	 * not flag the job as stale.
	 *
	 * @return bool True when the heartbeat was updated (false when no longer running).
	 */
	public function heartbeat(): bool {
		return $this->jobRepository->touchHeartbeat($this->jobId, $this->clock->now());
	}


	/**
	 * Whether a cancellation has been requested for this job.
	 *
	 * Reads the current status from the datastore on every call (one SELECT);
	 * intended for cooperative checks at step boundaries, not tight loops.
	 *
	 * @return bool True when the job status is cancel_requested.
	 */
	public function isCancellationRequested(): bool {
		return $this->jobRepository->getStatus($this->jobId) === JobStatus::CANCEL_REQUESTED->value;
	}


}
