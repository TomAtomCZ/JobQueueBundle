# Changelog

## Unreleased (2.2.0)

### Fixed

- The job message handler no longer holds database transactions while the command runs. Output, status and result are
  written through DBAL in autocommit mode, so a connection dropped in the middle of a write can no longer leave a
  half-open transaction on the shared connection. Previously the worker crashed afterwards in the messenger retry with
  `There is already an active transaction` and the message was redelivered (and the command run again) every
  `redeliver_timeout`.
- The wait loop is throttled (100 ms sleep, database writes and the cancellation check at most once per
  `poll_interval_ms`) instead of a busy loop which reloaded the whole job, including its output, and rewrote the whole
  output on every iteration.
- A short database outage no longer kills the running command; the output is buffered and the write retried.
- A failing command is stored as a `failed` job and no longer makes the handler throw. Storing the result is retried;
  when it still fails, an `UnrecoverableMessageHandlingException` is thrown so the command is not run again by a retry.
- A message of a deleted job is rejected with `UnrecoverableMessageHandlingException` instead of a `TypeError`.
- Output params printed in several output chunks are all kept (previously only those of the last chunk), and a
  `OUTPUT PARAMS:` line split between two chunks is found.
- A job cancelled while it was finishing keeps the `cancelled` status.
- Output containing multi-byte characters can no longer fail to be stored on a strict MySQL (`Incorrect string value`)
  when a read or the output cap splits a character; output a working database rejects is replaced by a note and can
  no longer kill a healthy command or block storing the job result.
- A database error before the command starts no longer loses the message; a job deleted while running stops its
  command.
- Symfony Process no longer keeps the whole command output in a temporary file.

### Added

- Configuration `job_queue.processing`: `poll_interval_ms` (1000), `output_max_bytes` (4 MB, 0 = unlimited),
  `db_failure_tolerance` (30), `rerun_on_redelivery` (false).
- Integration tests with SQLite and real subprocesses; CI legs with DBAL 3 / ORM 2.

### Changed

- **Behaviour change:** a message redelivered for a job which is already `running` (its previous worker died) marks the
  job `failed` instead of running the command again. Set `rerun_on_redelivery: true` for the previous behaviour.
  Messages for `completed`, `failed` and `cancelled` jobs are ignored.
- **Behaviour change:** the stored job output is capped at `output_max_bytes` (4 MB by default, previously unlimited);
  a truncation marker is appended once.
- **Behaviour change:** output params are written once, when the job finishes (previously during the run), and the
  values of all chunks are kept (previously only those of the last chunk).
- **Behaviour change:** the stored output is valid UTF-8 - bytes which are not UTF-8 are replaced by `?`.
- **Behaviour change:** the `Job` entity is detached from the entity manager while the handler runs and is not
  refreshed afterwards. When the handler runs inside a unit of work (sync transport, direct invocation), a `Job`
  object loaded before is stale (still `planned`) and detached - re-load it with `find()` before using it, e.g. as
  `$parentJob` of `createCommandJob()`.
- The job is switched to `running` by an atomic claim before the command starts (previously right after it started).
- A job is cancelled by a conditional update (only while it is `running`), so a result stored by the handler in the
  meantime is no longer overwritten by the cancellation.
- `symfony/dependency-injection` 8 is declared as a conflict (the bundle loads `config/services.xml`).
- Recommended messenger setup for the job transport: `retry_strategy: { max_retries: 0 }`, `--keepalive` (or a higher
  `redeliver_timeout`) for jobs longer than an hour. See README.
