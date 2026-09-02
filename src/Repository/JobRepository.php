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
namespace CitOmni\JobRunner\Repository;

use CitOmni\JobRunner\Enum\ClaimMode;
use CitOmni\JobRunner\Enum\JobStatus;
use CitOmni\Kernel\Repository\BaseRepository;

/**
 * JobRepository: Persistence access for the jobrunner job + log tables.
 *
 * Owns all SQL and datastore IO for the two jobrunner tables. Returns stable
 * array shapes and scalar persistence results. Performs no transport work,
 * orchestration, mail dispatch, application-event logging, or user-facing text
 * composition. Workflow decisions (what status a job should move to, when to
 * cancel, retention policy) belong to Operations, not here.
 *
 * Behavior:
 * - Table names are resolved once from cfg.jobrunner.tables and cached in init().
 * - State transitions are expressed as guarded, single-row writes; callers read
 *   the boolean return to learn whether the guarded transition actually applied.
 * - Worker ownership uses guarded queued -> running transitions keyed on the
 *   persisted claim mode and worker token hash. Token workers receive their hash
 *   at enqueue; trusted child workers receive an ephemeral handoff hash while the
 *   row remains queued and claim it immediately before execution.
 * - JSON columns (payload/result/context) are returned as raw strings. Decoding
 *   and shape decisions belong to the calling Operation.
 *
 * Notes:
 * - Repositories are instantiated explicitly, not service-map registered.
 * - Timestamps are supplied by the caller as MySQL DATETIME(6) literals
 *   (format 'Y-m-d H:i:s.u'). The repository does not read the clock, mirroring
 *   the "caller controls now" convention used elsewhere in CitOmni.
 * - Guarded transition methods return whether the database reported a changed
 *   row. Callers should pass fresh timestamps for writes where the boolean
 *   result matters.
 * - Token hashing is the caller's concern. The repository stores and compares
 *   opaque worker_token_hash strings; the raw token never reaches this layer.
 * - The active-lock uniqueness guarantee is enforced by the generated
 *   active_lock_key column + unique index. findActiveByLockKey() is only a
 *   friendly pre-check; createQueuedJob() may still raise a DbQueryException on a
 *   concurrent duplicate, which is allowed to bubble.
 */
final class JobRepository extends BaseRepository {

	/** Job columns returned by the read methods (worker_token_hash + generated column deliberately omitted). */
	private const JOB_COLUMNS =
		'id, job_uuid, job_type, status, claim_mode, lock_key, title, '
		. 'payload_json, result_json, '
		. 'error_class, error_reason_code, error_message, '
		. 'current_step_key, current_step_label, step_index, step_total, '
		. 'attempts, '
		. 'created_at, queued_at, started_at, heartbeat_at, finished_at, updated_at';

	/** Log columns returned by the log read methods. */
	private const LOG_COLUMNS =
		'id, job_id, seq, created_at, level, stream, step_key, message, context_json';

	private string $tableJobs;
	private string $tableLogs;


	// ----------------------------------------------------------------
	// Setup
	// ----------------------------------------------------------------

	/**
	 * Resolve and cache table names from the package cfg baseline.
	 *
	 * Called automatically by BaseRepository after construction.
	 *
	 * Notes:
	 * - cfg.jobrunner.tables.{jobs,logs} is guaranteed by the package Registry
	 *   baseline (CFG_COMMON), so no defensive isset() is needed here.
	 *
	 * @return void
	 */
	protected function init(): void {
		$tables = $this->app->cfg->jobrunner->tables;

		$this->tableJobs = (string)$tables->jobs;
		$this->tableLogs = (string)$tables->logs;
	}


	// ----------------------------------------------------------------
	// Job creation
	// ----------------------------------------------------------------

	/**
	 * Create one queued job row and return the generated id.
	 *
	 * New jobs always enter the lifecycle as queued. Later status transitions are
	 * handled by the guarded transition methods on this repository, so the caller
	 * does not get to choose the starting status.
	 *
	 * Notes:
	 * - A concurrent active job for the same lock_key violates the unique
	 *   active_lock_key index and surfaces as a DbQueryException, which is allowed
	 *   to bubble. Callers that want a friendly path should pre-check with
	 *   findActiveByLockKey().
	 * - claim_mode is an explicit persisted ownership discriminator. Token jobs
	 *   carry their worker hash from enqueue; trusted jobs are created with a null
	 *   hash and receive an ephemeral handoff hash later while still queued.
	 *
	 * @param array{job_uuid:string,job_type:string,claim_mode:string,worker_token_hash:?string,lock_key:?string,title:?string,payload_json:?string,created_at:string,queued_at:string,updated_at:string} $data Row payload.
	 * @return int New job id.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On insert/constraint failure.
	 */
	public function createQueuedJob(array $data): int {
		return $this->app->db->insert($this->tableJobs, [
			'job_uuid'          => $data['job_uuid'],
			'job_type'          => $data['job_type'],
			'status'            => JobStatus::QUEUED->value,
			'claim_mode'        => $data['claim_mode'],
			'lock_key'          => $data['lock_key'] ?? null,
			'title'             => $data['title'] ?? null,
			'payload_json'      => $data['payload_json'] ?? null,
			'worker_token_hash' => $data['worker_token_hash'],
			'created_at'        => $data['created_at'],
			'queued_at'         => $data['queued_at'],
			'updated_at'        => $data['updated_at'],
		]);
	}


	// ----------------------------------------------------------------
	// Worker lifecycle
	// ----------------------------------------------------------------

	/**
	 * Atomically claim a queued job for a worker.
	 *
	 * This is the ownership gate. Exactly one caller can transition a given job
	 * out of 'queued', and only when it presents the matching worker token hash.
	 *
	 * Behavior:
	 * - Single conditional UPDATE: queued -> running, sets started_at/heartbeat_at,
	 *   increments attempts, bumps updated_at.
	 * - Guarded on id, status='queued', claim_mode='token', and
	 *   worker_token_hash. Trusted jobs are invisible to this claim path.
	 *
	 * @param int    $jobId           Job id.
	 * @param string $workerTokenHash sha256 hex of the worker token.
	 * @param string $now             MySQL DATETIME(6) literal.
	 * @return bool True when this caller won the claim.
	 * @throws \InvalidArgumentException When job id or token hash is invalid.
	 */
	public function claimQueued(int $jobId, string $workerTokenHash, string $now): bool {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}
		if ($workerTokenHash === '') {
			throw new \InvalidArgumentException('Worker token hash cannot be empty.');
		}

		$affected = $this->app->db->execute(
			'UPDATE ' . $this->tableJobs . '
			 SET status = ?, started_at = ?, heartbeat_at = ?, attempts = attempts + 1, updated_at = ?
			 WHERE id = ? AND status = ? AND claim_mode = ? AND worker_token_hash = ?',
			[
				JobStatus::RUNNING->value,
				$now,
				$now,
				$now,
				$jobId,
				JobStatus::QUEUED->value,
				ClaimMode::TOKEN->value,
				$workerTokenHash,
			]
		);

		return $affected === 1;
	}


	// ----------------------------------------------------------------
	// Trusted (pull) dispatch and claim
	// ----------------------------------------------------------------

	/**
	 * Find the id of the oldest queued trusted job, if any.
	 *
	 * Deterministic queue order uses created_at first and id as the tie-breaker
	 * when multiple rows share the same microsecond timestamp.
	 *
	 * Behavior:
	 * - Considers only queued trusted-mode jobs. Token-mode jobs are invisible.
	 * - A queued trusted row may already carry a stale handoff hash after a
	 *   supervisor crash. It remains eligible so a later dispatch can replace the
	 *   hash and fence out any child holding the old token.
	 *
	 * @return int|null Oldest queued trusted job id, or null when none exists.
	 */
	public function findNextTrustedQueuedId(): ?int {
		$value = $this->app->db->fetchValue(
			'SELECT id
			 FROM ' . $this->tableJobs . '
			 WHERE status = ? AND claim_mode = ?
			 ORDER BY created_at ASC, id ASC
			 LIMIT 1',
			[JobStatus::QUEUED->value, ClaimMode::TRUSTED->value]
		);

		return $value === null ? null : (int)$value;
	}

	/**
	 * Prepare one queued trusted job for handoff to a fresh child worker.
	 *
	 * This method deliberately does NOT claim or start the job. It only stores the
	 * hash of the ephemeral handoff token while the row remains queued. The child
	 * process must later win claimTrustedQueued() before it may execute the handler.
	 *
	 * Behavior:
	 * - Guarded on id, status='queued', and claim_mode='trusted'.
	 * - Replaces any existing trusted handoff hash. This makes redispatch after a
	 *   supervisor crash safe: the newest hash fences out a child holding an older
	 *   token before either can transition the row to running.
	 * - Bumps updated_at only. started_at, heartbeat_at, and attempts keep their
	 *   execution semantics and are changed only by the child claim.
	 *
	 * @param int    $jobId            Trusted queued job id.
	 * @param string $handoffTokenHash SHA-256 hex of the ephemeral handoff token.
	 * @param string $now              MySQL DATETIME(6) literal.
	 * @return bool True when the handoff hash was stored on an eligible row.
	 * @throws \InvalidArgumentException When job id or handoff token hash is invalid.
	 */
	public function prepareTrustedDispatch(int $jobId, string $handoffTokenHash, string $now): bool {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}
		if ($handoffTokenHash === '') {
			throw new \InvalidArgumentException('Handoff token hash cannot be empty.');
		}

		$affected = $this->app->db->update(
			$this->tableJobs,
			[
				'worker_token_hash' => $handoffTokenHash,
				'updated_at'        => $now,
			],
			'id = ? AND status = ? AND claim_mode = ?',
			[$jobId, JobStatus::QUEUED->value, ClaimMode::TRUSTED->value]
		);

		return $affected === 1;
	}

	/**
	 * Atomically claim a queued trusted job for its prepared child worker.
	 *
	 * This is the trusted ownership gate. The fresh child hashes the raw handoff
	 * token it received from the supervisor and may execute the job only if this
	 * guarded queued -> running transition succeeds.
	 *
	 * Behavior:
	 * - Single conditional UPDATE: queued -> running, sets started_at/heartbeat_at,
	 *   increments attempts, and bumps updated_at.
	 * - Guarded on id, status='queued', claim_mode='trusted', and the prepared
	 *   handoff hash. Cancellation competes with this transition on status='queued'.
	 * - A second child with the same or an older token cannot claim a job after the
	 *   first child has moved it out of queued or a newer dispatch replaced the hash.
	 *
	 * @param int    $jobId            Job id.
	 * @param string $handoffTokenHash SHA-256 hex of the handoff token.
	 * @param string $now              MySQL DATETIME(6) literal.
	 * @return bool True when this child won the claim.
	 * @throws \InvalidArgumentException When job id or handoff token hash is invalid.
	 */
	public function claimTrustedQueued(int $jobId, string $handoffTokenHash, string $now): bool {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}
		if ($handoffTokenHash === '') {
			throw new \InvalidArgumentException('Handoff token hash cannot be empty.');
		}

		$affected = $this->app->db->execute(
			'UPDATE ' . $this->tableJobs . '
			 SET status = ?, started_at = ?, heartbeat_at = ?, attempts = attempts + 1, updated_at = ?
			 WHERE id = ? AND status = ? AND claim_mode = ? AND worker_token_hash = ?',
			[
				JobStatus::RUNNING->value,
				$now,
				$now,
				$now,
				$jobId,
				JobStatus::QUEUED->value,
				ClaimMode::TRUSTED->value,
				$handoffTokenHash,
			]
		);

		return $affected === 1;
	}

	/**
	 * Refresh the heartbeat for a running job.
	 *
	 * @param int    $jobId Job id.
	 * @param string $now   MySQL DATETIME(6) literal.
	 * @return bool True when a running job was touched.
	 * @throws \InvalidArgumentException When job id is invalid.
	 */
	public function touchHeartbeat(int $jobId, string $now): bool {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$affected = $this->app->db->update(
			$this->tableJobs,
			[
				'heartbeat_at' => $now,
				'updated_at'   => $now,
			],
			'id = ? AND status = ?',
			[$jobId, JobStatus::RUNNING->value]
		);

		return $affected > 0;
	}

	/**
	 * Update step progress for a running job.
	 *
	 * Passing null for the step fields clears them; the caller controls the shape.
	 *
	 * @param int     $jobId     Job id.
	 * @param ?string $stepKey   Machine step key, or null.
	 * @param ?string $stepLabel Human step label, or null.
	 * @param ?int    $stepIndex 1-based current step index, or null.
	 * @param ?int    $stepTotal Total step count, or null.
	 * @param string  $now       MySQL DATETIME(6) literal.
	 * @return bool True when a running job was updated.
	 * @throws \InvalidArgumentException When job id is invalid.
	 */
	public function updateStep(int $jobId, ?string $stepKey, ?string $stepLabel, ?int $stepIndex, ?int $stepTotal, string $now): bool {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$affected = $this->app->db->update(
			$this->tableJobs,
			[
				'current_step_key'   => $stepKey,
				'current_step_label' => $stepLabel,
				'step_index'         => $stepIndex,
				'step_total'         => $stepTotal,
				'updated_at'         => $now,
			],
			'id = ? AND status = ?',
			[$jobId, JobStatus::RUNNING->value]
		);

		return $affected > 0;
	}


	// ----------------------------------------------------------------
	// Terminal transitions
	// ----------------------------------------------------------------

	/**
	 * Mark a running job succeeded and store its result.
	 *
	 * Behavior:
	 * - Transitions only from running. A cancel request that lands before this
	 *   guarded write wins over success and must be handled by the caller.
	 *
	 * @param int     $jobId      Job id.
	 * @param ?string $resultJson Domain result as a JSON string, or null.
	 * @param string  $now        MySQL DATETIME(6) literal.
	 * @return bool True when the job transitioned to succeeded.
	 * @throws \InvalidArgumentException When job id is invalid.
	 */
	public function markSucceeded(int $jobId, ?string $resultJson, string $now): bool {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$affected = $this->app->db->update(
			$this->tableJobs,
			[
				'status'       => JobStatus::SUCCEEDED->value,
				'result_json'  => $resultJson,
				'heartbeat_at' => $now,
				'finished_at'  => $now,
				'updated_at'   => $now,
			],
			'id = ? AND status = ?',
			[$jobId, JobStatus::RUNNING->value]
		);

		return $affected > 0;
	}

	/**
	 * Mark a job failed and store error metadata.
	 *
	 * Behavior:
	 * - Transitions from any active status, covering both launcher failure (from
	 *   queued, before a worker ever ran) and handler failure (from running).
	 *
	 * @param int     $jobId           Job id.
	 * @param ?string $errorClass      Fully-qualified throwable class, or null.
	 * @param ?string $errorReasonCode Domain reason code, or null.
	 * @param ?string $errorMessage    Error message, or null.
	 * @param string  $now             MySQL DATETIME(6) literal.
	 * @return bool True when the job transitioned to failed.
	 * @throws \InvalidArgumentException When job id is invalid.
	 */
	public function markFailed(int $jobId, ?string $errorClass, ?string $errorReasonCode, ?string $errorMessage, string $now): bool {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$from = JobStatus::activeValues();

		$affected = $this->app->db->update(
			$this->tableJobs,
			[
				'status'            => JobStatus::FAILED->value,
				'error_class'       => $errorClass,
				'error_reason_code' => $errorReasonCode,
				'error_message'     => $errorMessage,
				'finished_at'       => $now,
				'updated_at'        => $now,
			],
			'id = ? AND status IN (' . $this->placeholders($from) . ')',
			\array_merge([$jobId], $from)
		);

		return $affected > 0;
	}

	/**
	 * Mark a job cancelled.
	 *
	 * Behavior:
	 * - Transitions queued -> cancelled for jobs that never started, so the cancel
	 *   competes atomically with claimQueued() on the same status guard.
	 * - Transitions cancel_requested -> cancelled for cooperative worker shutdown.
	 * - Does NOT transition running -> cancelled. A running job is owned by a live
	 *   worker; it must first move through cancel_requested so the worker stops
	 *   cooperatively. Terminal-cancelling underneath a running worker is treated
	 *   as corrupt state, not a supported transition.
	 *
	 * @param int    $jobId Job id.
	 * @param string $now   MySQL DATETIME(6) literal.
	 * @return bool True when the job transitioned to cancelled.
	 * @throws \InvalidArgumentException When job id is invalid.
	 */
	public function markCancelled(int $jobId, string $now): bool {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$from = [JobStatus::QUEUED->value, JobStatus::CANCEL_REQUESTED->value];

		$affected = $this->app->db->update(
			$this->tableJobs,
			[
				'status'      => JobStatus::CANCELLED->value,
				'finished_at' => $now,
				'updated_at'  => $now,
			],
			'id = ? AND status IN (' . $this->placeholders($from) . ')',
			\array_merge([$jobId], $from)
		);

		return $affected > 0;
	}


	// ----------------------------------------------------------------
	// Cancellation request
	// ----------------------------------------------------------------

	/**
	 * Request cooperative cancellation of a running job.
	 *
	 * Behavior:
	 * - Transitions running -> cancel_requested only.
	 * - Queued jobs are NOT routed through cancel_requested, because no worker
	 *   has claimed them yet; cancel them directly with markCancelled(). Allowing
	 *   queued -> cancel_requested could strand a job in cancel_requested that no
	 *   worker ever claims and finalizes.
	 * - Already-requested or terminal jobs are no-ops and return false.
	 *
	 * @param int    $jobId Job id.
	 * @param string $now   MySQL DATETIME(6) literal.
	 * @return bool True when cancellation was newly requested.
	 * @throws \InvalidArgumentException When job id is invalid.
	 */
	public function requestCancel(int $jobId, string $now): bool {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$affected = $this->app->db->update(
			$this->tableJobs,
			[
				'status'     => JobStatus::CANCEL_REQUESTED->value,
				'updated_at' => $now,
			],
			'id = ? AND status = ?',
			[$jobId, JobStatus::RUNNING->value]
		);

		return $affected > 0;
	}


	// ----------------------------------------------------------------
	// Reads
	// ----------------------------------------------------------------

	/**
	 * Find one job by id.
	 *
	 * @param int $jobId Job id.
	 * @return array<string,mixed>|null Normalized job row, or null when missing.
	 * @throws \InvalidArgumentException When job id is invalid.
	 */
	public function findById(int $jobId): ?array {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$row = $this->app->db->fetchRow(
			'SELECT ' . self::JOB_COLUMNS . '
			 FROM ' . $this->tableJobs . '
			 WHERE id = ?
			 LIMIT 1',
			[$jobId]
		);

		return $row === null ? null : $this->normalizeJobRow($row);
	}

	/**
	 * Find one job by its public uuid.
	 *
	 * Intended for status endpoints that key on the non-enumerable job_uuid
	 * rather than the internal id.
	 *
	 * @param string $jobUuid Job uuid.
	 * @return array<string,mixed>|null Normalized job row, or null when missing.
	 * @throws \InvalidArgumentException When uuid is empty.
	 */
	public function findByUuid(string $jobUuid): ?array {
		$jobUuid = \trim($jobUuid);

		if ($jobUuid === '') {
			throw new \InvalidArgumentException('Job uuid cannot be empty.');
		}

		$row = $this->app->db->fetchRow(
			'SELECT ' . self::JOB_COLUMNS . '
			 FROM ' . $this->tableJobs . '
			 WHERE job_uuid = ?
			 LIMIT 1',
			[$jobUuid]
		);

		return $row === null ? null : $this->normalizeJobRow($row);
	}

	/**
	 * Find the single active job holding a given lock key, if any.
	 *
	 * Behavior:
	 * - Queries the generated active_lock_key column, which is populated only for
	 *   active jobs (queued, running, cancel_requested) and is unique. At most one
	 *   row can match.
	 *
	 * @param string $lockKey Lock key.
	 * @return array<string,mixed>|null Normalized job row, or null when no active job holds the lock.
	 * @throws \InvalidArgumentException When lock key is empty.
	 */
	public function findActiveByLockKey(string $lockKey): ?array {
		$lockKey = \trim($lockKey);

		if ($lockKey === '') {
			throw new \InvalidArgumentException('Lock key cannot be empty.');
		}

		$row = $this->app->db->fetchRow(
			'SELECT ' . self::JOB_COLUMNS . '
			 FROM ' . $this->tableJobs . '
			 WHERE active_lock_key = ?
			 LIMIT 1',
			[$lockKey]
		);

		return $row === null ? null : $this->normalizeJobRow($row);
	}

	/**
	 * Read the current status string for a job.
	 *
	 * Cheap lookup intended for cooperative cancellation checks between steps.
	 *
	 * @param int $jobId Job id.
	 * @return string|null Status string, or null when the job does not exist.
	 * @throws \InvalidArgumentException When job id is invalid.
	 */
	public function getStatus(int $jobId): ?string {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$value = $this->app->db->fetchValue(
			'SELECT status FROM ' . $this->tableJobs . ' WHERE id = ? LIMIT 1',
			[$jobId]
		);

		return $value === null ? null : (string)$value;
	}


	// ----------------------------------------------------------------
	// Logs
	// ----------------------------------------------------------------

	/**
	 * Append one log row and return the new log id.
	 *
	 * Behavior:
	 * - seq is assigned per job as MAX(seq)+1 within the INSERT...SELECT, so the
	 *   sequence stays gap-tolerant and deterministically ordered. The
	 *   uniqueness of (job_id, seq) is the safety net.
	 *
	 * Notes:
	 * - Assumes a single writer per job (the owning worker). Concurrent writers to
	 *   the same job_id can collide on seq and surface a DbQueryException, which is
	 *   allowed to bubble.
	 *
	 * @param int     $jobId       Job id.
	 * @param string  $level       Log level string (JobLogLevel value).
	 * @param string  $stream      Stream string (JobStream value); '' for general messages.
	 * @param ?string $stepKey     Owning step key, or null.
	 * @param string  $message     Log message.
	 * @param ?string $contextJson Context as a JSON string, or null.
	 * @param string  $now         MySQL DATETIME(6) literal.
	 * @return int New log row id.
	 * @throws \InvalidArgumentException When job id is invalid.
	 */
	public function appendLog(int $jobId, string $level, string $stream, ?string $stepKey, string $message, ?string $contextJson, string $now): int {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}

		$this->app->db->execute(
			'INSERT INTO ' . $this->tableLogs . '
			 (job_id, seq, created_at, level, stream, step_key, message, context_json)
			 SELECT ?, COALESCE(MAX(seq), 0) + 1, ?, ?, ?, ?, ?, ?
			 FROM ' . $this->tableLogs . '
			 WHERE job_id = ?',
			[$jobId, $now, $level, $stream, $stepKey, $message, $contextJson, $jobId]
		);

		return $this->app->db->lastInsertId();
	}

	/**
	 * Fetch the most recent log rows for a job, oldest-first.
	 *
	 * Behavior:
	 * - Selects the newest $limit rows by seq, then returns them in ascending seq
	 *   order for natural top-to-bottom display.
	 *
	 * @param int $jobId Job id.
	 * @param int $limit Maximum rows to return (>= 1).
	 * @return list<array<string,mixed>> Normalized log rows in ascending seq order.
	 * @throws \InvalidArgumentException When job id or limit is invalid.
	 */
	public function fetchRecentLogs(int $jobId, int $limit): array {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}
		if ($limit < 1) {
			throw new \InvalidArgumentException('Limit must be >= 1.');
		}

		$rows = $this->app->db->fetchAll(
			'SELECT ' . self::LOG_COLUMNS . '
			 FROM ' . $this->tableLogs . '
			 WHERE job_id = ?
			 ORDER BY seq DESC
			 LIMIT ' . $limit,
			[$jobId]
		);

		$rows = \array_reverse($rows);

		$out = [];
		foreach ($rows as $row) {
			$out[] = $this->normalizeLogRow($row);
		}

		return $out;
	}

	/**
	 * Fetch log rows after a given seq, ascending.
	 *
	 * Intended for incremental UI polling: the client passes the highest seq it
	 * already holds and receives only newer rows.
	 *
	 * @param int $jobId    Job id.
	 * @param int $afterSeq Exclusive lower bound seq (>= 0; use 0 for "from start").
	 * @param int $limit    Maximum rows to return (>= 1).
	 * @return list<array<string,mixed>> Normalized log rows in ascending seq order.
	 * @throws \InvalidArgumentException When job id, after-seq, or limit is invalid.
	 */
	public function fetchLogsAfterSeq(int $jobId, int $afterSeq, int $limit): array {
		if ($jobId < 1) {
			throw new \InvalidArgumentException('Job id must be >= 1.');
		}
		if ($afterSeq < 0) {
			throw new \InvalidArgumentException('After seq must be >= 0.');
		}
		if ($limit < 1) {
			throw new \InvalidArgumentException('Limit must be >= 1.');
		}

		$rows = $this->app->db->fetchAll(
			'SELECT ' . self::LOG_COLUMNS . '
			 FROM ' . $this->tableLogs . '
			 WHERE job_id = ? AND seq > ?
			 ORDER BY seq ASC
			 LIMIT ' . $limit,
			[$jobId, $afterSeq]
		);

		$out = [];
		foreach ($rows as $row) {
			$out[] = $this->normalizeLogRow($row);
		}

		return $out;
	}


	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Build a positional placeholder list for an IN (...) clause.
	 *
	 * @param array<int,string> $values Bound values; only the count is used here.
	 * @return string Comma-separated '?' placeholders.
	 */
	private function placeholders(array $values): string {
		return \implode(', ', \array_fill(0, \count($values), '?'));
	}

	/**
	 * Normalize a raw job row into a stable typed shape.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 * @return array<string,mixed> Normalized job row.
	 */
	private function normalizeJobRow(array $row): array {
		return [
			'id'                 => (int)$row['id'],
			'job_uuid'           => (string)$row['job_uuid'],
			'job_type'           => (string)$row['job_type'],
			'status'             => (string)$row['status'],
			'claim_mode'         => (string)$row['claim_mode'],
			'lock_key'           => $row['lock_key'] !== null ? (string)$row['lock_key'] : null,
			'title'              => $row['title'] !== null ? (string)$row['title'] : null,
			'payload_json'       => $row['payload_json'] !== null ? (string)$row['payload_json'] : null,
			'result_json'        => $row['result_json'] !== null ? (string)$row['result_json'] : null,
			'error_class'        => $row['error_class'] !== null ? (string)$row['error_class'] : null,
			'error_reason_code'  => $row['error_reason_code'] !== null ? (string)$row['error_reason_code'] : null,
			'error_message'      => $row['error_message'] !== null ? (string)$row['error_message'] : null,
			'current_step_key'   => $row['current_step_key'] !== null ? (string)$row['current_step_key'] : null,
			'current_step_label' => $row['current_step_label'] !== null ? (string)$row['current_step_label'] : null,
			'step_index'         => $row['step_index'] !== null ? (int)$row['step_index'] : null,
			'step_total'         => $row['step_total'] !== null ? (int)$row['step_total'] : null,
			'attempts'           => (int)$row['attempts'],
			'created_at'         => (string)$row['created_at'],
			'queued_at'          => $row['queued_at'] !== null ? (string)$row['queued_at'] : null,
			'started_at'         => $row['started_at'] !== null ? (string)$row['started_at'] : null,
			'heartbeat_at'       => $row['heartbeat_at'] !== null ? (string)$row['heartbeat_at'] : null,
			'finished_at'        => $row['finished_at'] !== null ? (string)$row['finished_at'] : null,
			'updated_at'         => (string)$row['updated_at'],
		];
	}

	/**
	 * Normalize a raw log row into a stable typed shape.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 * @return array<string,mixed> Normalized log row.
	 */
	private function normalizeLogRow(array $row): array {
		return [
			'id'           => (int)$row['id'],
			'job_id'       => (int)$row['job_id'],
			'seq'          => (int)$row['seq'],
			'created_at'   => (string)$row['created_at'],
			'level'        => (string)$row['level'],
			'stream'       => (string)$row['stream'],
			'step_key'     => $row['step_key'] !== null ? (string)$row['step_key'] : null,
			'message'      => (string)$row['message'],
			'context_json' => $row['context_json'] !== null ? (string)$row['context_json'] : null,
		];
	}
}
