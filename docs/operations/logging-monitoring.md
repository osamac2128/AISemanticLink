# Logging and Monitoring

> Docs home: `docs/index.md`

## Log storage

- Location: `wp-content/uploads/vibe-ai-logs/`
- Pattern: `YYYY-MM-DD.log`
- Levels: `debug`, `info`, `warning`, `error`

## Access paths

- Entity logs endpoint: `GET /wp-json/vibe-ai/v1/logs`
- KB logs endpoint: `GET /wp-json/vibe-ai/v1/kb/logs`
- Direct filesystem log inspection on host

## What to monitor

- Pipeline status transitions (`idle/running/failed/completed`).
- Retry bursts and rate-limit errors from AI requests.
- Queue growth and stale scheduled actions.
- KB search latency and vector counts over time.

## Useful status endpoints

- Entity: `GET /wp-json/vibe-ai/v1/status`
- KB: `GET /wp-json/vibe-ai/v1/kb/status`
