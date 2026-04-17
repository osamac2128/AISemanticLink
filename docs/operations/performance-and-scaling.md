# Performance and Scaling

> Docs home: `docs/index.md`

## Entity Pipeline Tuning

### Batch Size Management

The `BatchSizeManager` dynamically adjusts batch sizes to maintain optimal throughput without overloading the API or PHP process:

| Parameter | Value | Source |
|-----------|-------|--------|
| Default batch size | 50 | `Config::BATCH_SIZE` |
| Minimum batch size | 5 | `BatchSizeManager::MIN_BATCH` |
| Maximum batch size | 50 | `BatchSizeManager::MAX_BATCH` |
| Target processing time | 5.0s | `BatchSizeManager::TARGET_TIME` |
| Adjustment increment | 5 | `BatchSizeManager::ADJUSTMENT_INCREMENT` |
| Max concurrent batches | 3 | `Config::MAX_CONCURRENT_BATCHES` |

### Dynamic Adjustment Logic

`BatchSizeManager` maintains a rolling average of per-item processing times (last 10 samples) and adjusts batch size after each batch completes:

```
if average_time_per_item * current_batch_size < 2.0s:
    batch_size = min(50, current + 5)    # Increase by 5
elif average_time_per_item * current_batch_size > 10.0s:
    batch_size = max(5, current - 5)     # Decrease by 5
else:
    batch_size = current                 # Keep steady
```

The adjustment thresholds:
- **Increase**: projected batch time < 2.0s (fast API responses, room for more)
- **Decrease**: projected batch time > 10.0s (slow responses or rate limiting)
- **Steady**: between 2.0s and 10.0s (healthy range)

### Action Scheduler Concurrency

Keep Action Scheduler concurrency ≤ `MAX_CONCURRENT_BATCHES` (3). Exceeding this causes:
- Rate limit hits on the OpenRouter API
- Increased memory pressure in PHP workers
- Diminishing returns due to API queueing

Monitor in **WP Admin → Tools → Scheduled Actions** → filter by `vibe_ai_process_batch`.

### Tuning Recommendations

| Site Size | Posts | Recommended Batch Size | Concurrency |
|-----------|-------|----------------------|-------------|
| Small | < 100 | 10-20 (dynamic) | 1-2 |
| Medium | 100-1000 | 20-50 (dynamic) | 2-3 |
| Large | > 1000 | Let BatchSizeManager auto-adjust | 3 |

## KB Pipeline Tuning

### Chunk Configuration

| Parameter | Value | Impact |
|-----------|-------|--------|
| Chunk size | 450 tokens | `Config::KB_CHUNK_TOKENS_TARGET` — affects embedding cost and search granularity |
| Chunk overlap | 60 tokens | `Config::KB_CHUNK_OVERLAP_TOKENS` — prevents boundary loss between chunks |
| Min chunk tokens | 50 | `Config::KB_MIN_CHUNK_TOKENS` — very small chunks are merged or dropped |
| Max chunk tokens | 800 | `Config::KB_MAX_CHUNK_TOKENS` — oversized chunks are split |
| Embedding batch size | 25 | `Config::KB_BATCH_SIZE_CHUNKS` — chunks per embedding API call |

**Trade-offs:**
- Larger chunks → fewer embeddings (lower cost) but less precise search
- Smaller chunks → more embeddings (higher cost) but better search granularity
- Higher overlap → more redundant content but prevents information loss at boundaries

### Search Performance

| Parameter | Value | Impact |
|-----------|-------|--------|
| Default top_k | 8 | `Config::KB_TOP_K_DEFAULT` — results per query |
| Max top_k | 50 | `KBController::MAX_TOP_K` |
| Max scan vectors | 5000 | `Config::KB_MAX_SCAN_VECTORS` — limits brute-force cosine scan |
| Max query length | 2000 chars | `KBController::MAX_QUERY_LENGTH` |

### Search Optimization Tips

- Use `post_type` filter to narrow search to specific content types
- Use `post_ids` filter when searching within a known document set
- Use `exclude_ids` to remove irrelevant documents from results
- Reduce `top_k` when only a few results are needed
- For sites with > 5000 vectors, `KB_MAX_SCAN_VECTORS` truncates results — consider increasing or using an external vector store

## Capacity Planning

### Vector Volume Estimates

Approximate resource usage by site size:

| Site Size | Posts | Estimated Chunks | Estimated Vectors | Vector Storage |
|-----------|-------|-----------------|-------------------|---------------|
| Small | 100 | ~200 | ~200 | ~1.2 MB |
| Medium | 500 | ~1,000 | ~1,000 | ~6 MB |
| Large | 1,000 | ~2,500 | ~2,500 | ~15 MB |
| Enterprise | 5,000 | ~12,500 | ~12,500 | ~75 MB |
| Very Large | 10,000+ | ~25,000+ | ~25,000+ | ~150 MB+ |

These estimates assume:
- ~500 vectors per 100 posts (varies by content length and post type)
- Average 2-3 chunks per post
- 1536-dimension embeddings (text-embedding-3-small)
- LONGBLOB vector storage: ~6 KB per vector (1536 dims × 4 bytes + overhead)

### Table Growth Rates

| Table | Growth Rate | Monitor For |
|-------|-------------|-------------|
| `wp_ai_entities` | Low — bounded by unique entities | Merge operations reducing count |
| `wp_ai_mentions` | Medium — one per entity-post pair | High-content sites with many cross-references |
| `wp_ai_aliases` | Low — one per alternate name | Alias explosion from ambiguous entities |
| `wp_ai_kb_docs` | Low — one per indexed post | Excluded documents accumulating |
| `wp_ai_kb_chunks` | Medium — 2-5 per post | Large posts generating many chunks |
| `wp_ai_kb_vectors` | Medium — one per chunk | Primary storage growth driver |

### Memory Requirements

| Posts | Recommended PHP Memory | Notes |
|-------|----------------------|-------|
| < 500 | 128 MB | Default WordPress allocation |
| 500-1000 | 256 MB | Standard production setup |
| 1000-5000 | 256-512 MB | Large content sites |
| > 5000 | 512 MB+ | Enterprise deployments, consider external vector store |

## Cost Controls

### Content Truncation

Entity extraction input is truncated to 20,000 characters in `EntityExtractor::prepare_content()`:

```php
// EntityExtractor.php:370
if (mb_strlen($content) > 20000) {
    $content = mb_substr($content, 0, 20000);
}
```

This caps per-post extraction cost regardless of actual content length.

### Circuit Breaker

The `AIClient` implements a circuit breaker pattern to prevent cascading API failures:

| Parameter | Value | Source |
|-----------|-------|--------|
| Failure threshold | 5 consecutive failures | `AIClient::CIRCUIT_FAILURE_THRESHOLD` |
| Cooldown period | 5 minutes (300s) | `AIClient::CIRCUIT_COOLDOWN_SECONDS` |
| State storage | `vibe_ai_openrouter_circuit` option | `AIClient::CIRCUIT_OPTION` |

When the circuit breaker opens:
1. All API calls immediately fail with a clear error message
2. The `open_until` timestamp is stored in `wp_options`
3. After cooldown expires, the next call attempt resets the breaker

### Batch Embedding

KB embedding operations batch 25 chunks per API call (`Config::KB_BATCH_SIZE_CHUNKS`), reducing per-call overhead and improving throughput.

### Cost Reduction Strategies

1. **Targeted reindexing** — use `POST /kb/docs/{post_id}/reindex` for single posts instead of full reindex
2. **Exclude irrelevant content** — use `POST /kb/docs/exclude` to skip low-value post types
3. **Monitor confidence thresholds** — entities below `CONFIDENCE_LOW` (0.40) are rejected before storage
4. **Schedule during off-peak** — run full pipeline during low-traffic periods

## Database Performance

### Table Design

All tables use InnoDB with proper indexes:

| Table | Primary Key | Unique Keys | Indexes |
|-------|-------------|-------------|---------|
| `ai_entities` | `id` | `idx_slug` | `idx_type`, `idx_status`, `idx_mention_count` |
| `ai_mentions` | `id` | `idx_entity_post` | `idx_post_id`, `idx_confidence` |
| `ai_aliases` | `id` | `idx_alias_slug` | `idx_canonical` |
| `ai_kb_docs` | `id` | `idx_post_id` | `idx_status`, `idx_post_type`, `idx_content_hash`, `idx_last_indexed_at` |
| `ai_kb_chunks` | `id` | `idx_doc_anchor` | `idx_doc_id`, `idx_chunk_hash`, `idx_doc_chunk_index` |
| `ai_kb_vectors` | `id` | `idx_chunk_id` | `idx_model` |

Foreign key constraints enforce referential integrity with `ON DELETE CASCADE`:
- `mentions.entity_id` → `entities.id`
- `mentions.post_id` → `posts.ID`
- `aliases.canonical_id` → `entities.id`
- `kb_docs.post_id` → `posts.ID`
- `kb_chunks.doc_id` → `kb_docs.id`
- `kb_vectors.chunk_id` → `kb_chunks.id`

### Vector Similarity Search

KB search uses brute-force cosine similarity computed in MySQL:

```sql
-- Simplified: cosine similarity via dot product of normalized vectors
SELECT chunk_id, dot_product / (norm_a * norm_b) AS score
FROM wp_ai_kb_vectors
ORDER BY score DESC
LIMIT top_k
```

`KB_MAX_SCAN_VECTORS` (5000) prevents full table scans on large deployments by limiting the number of vectors evaluated per query.

### Performance Monitoring

For large deployments, monitor:

- **Slow query log** on `wp_ai_kb_vectors` — vector similarity scans are CPU-intensive
- **Table sizes** — `ai_kb_vectors` with LONGBLOB storage grows proportionally to vector count
- **Query latency** — KB search `query_time_ms` from logs
- **Memory usage** — PHP process memory during batch operations

### External Vector Store Considerations

For deployments exceeding 100,000 vectors, consider migrating to a dedicated vector database:

| Option | Integration | Notes |
|--------|-------------|-------|
| **pgvector** (PostgreSQL) | Custom VectorRepository | Best for sites already using PostgreSQL |
| **Qdrant** | HTTP API from PHP | Purpose-built vector search, supports filtering |
| **Pinecone** | HTTP API from PHP | Managed service, no infrastructure management |
| **Weaviate** | HTTP API from PHP | Hybrid search (keyword + vector) |

The `VectorRepository` abstraction in `includes/Repositories/KB/VectorRepository.php` can be extended to support alternative backends while keeping the rest of the KB pipeline unchanged.
