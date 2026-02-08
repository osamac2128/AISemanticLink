# Performance and Scaling

> Docs home: `docs/index.md`

## Entity pipeline tuning

- Batch size defaults to `50` with dynamic adaptation window `5-50`.
- `BatchSizeManager` increases/decreases by `5` based on observed processing time.
- Keep Action Scheduler workers healthy before increasing throughput.

## KB pipeline tuning

- Chunk sizing settings impact token volume, embedding costs, and search quality.
- `top_k` and scan limits influence search latency.
- Use narrow filters (`post_type`, `post_ids`) for faster search queries.

## Cost controls

- Entity extraction input is truncated in extractor path (20k chars).
- Client-side request constraints and retries cap worst-case request loops.
- Prefer targeted reindexing over frequent full reindex runs.

## Database considerations

- Ensure plugin indexes exist after upgrades.
- Monitor table growth:
  - `*_mentions` for high-content sites
  - `*_kb_chunks` and `*_kb_vectors` for large KB deployments
- Validate storage engine and charset compatibility with FK definitions.
