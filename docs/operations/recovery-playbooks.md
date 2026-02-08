# Recovery Playbooks

> Docs home: `docs/index.md`

## Pipeline stuck as running

1. Check status endpoints for phase and progress.
2. Check Action Scheduler queue for pending/failed jobs.
3. Stop via REST:
   - `POST /wp-json/vibe-ai/v1/pipeline/stop`
   - `POST /wp-json/vibe-ai/v1/kb/stop`
4. Re-run with smaller scope (`post_types` or single-doc reindex).

## 409 on pipeline start

- Cause: status still marked running.
- Actions:
  - confirm no active jobs in scheduler
  - stop pipeline endpoint
  - verify status returns `idle`
  - start again

## Rate-limit or API instability

- Symptoms: repeated retries, `RateLimitException`, high failure counts.
- Actions:
  - reduce batch sizes
  - pause concurrent operations
  - retry after cooldown
  - inspect network/API status

## Schema drift after entity changes

- Verify propagation jobs are scheduled/executed.
- Inspect `vibe_ai_propagating_ids` and recent logs.
- Trigger reprocessing for affected content if needed.

## KB index drift

- Run `GET /kb/status` and compare docs/chunks/vectors counts.
- Reindex single docs first (`POST /kb/docs/{post_id}/reindex`).
- Use full `POST /kb/reindex` only when broad inconsistency exists.
