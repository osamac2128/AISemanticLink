# PRD: AI Knowledge Base Layer for AI Entity Index (OpenRouter-only)

## Product name

AI Entity Index: Knowledge Base (RAG) Module

## Background and current state

AI Entity Index currently extracts and normalizes named entities, stores canonical entities and mentions, and injects Schema.org JSON-LD into the site for AI/LLM discoverability.

It uses:

- OpenRouter as a model-agnostic gateway.
- Action Scheduler with a PipelineManager that handles batching, throttling, retries.
- Existing DB tables: wp_ai_entities, wp_ai_mentions, wp_ai_aliases, plus schema cache meta keys.
- Existing REST API base: /wp-json/vibe-ai/v1/ with endpoints for status, pipeline control, entities, logs.
- Existing security posture: endpoints require manage_options, API keys are not stored or logged.

## Objective

Add a retrieval-first AI Knowledge Base so WordPress content can be used reliably for RAG:

- High quality chunking
- Embeddings via OpenRouter
- Vector storage (start with MySQL-based store for MVP, adapter-ready for external stores later)
- Retrieval endpoints that return top chunks with stable citations
- Admin UI to index, monitor, and test retrieval

## Success metrics

### Retrieval quality

Test queries return relevant chunks in top 5 at least 80% of the time for a seeded test set.

### Operational safety

No noticeable slowdown in wp-admin on save_post.

Background queue processes reliably under rate limits.

### Seamless UX

Admin can: enable KB, index content, run "Test Search", click citation and land on exact section.

## Non-goals

- No "chat UI" in this module (retrieval only). A separate addon can generate answers.
- No public, anonymous search endpoint by default.
- No external vector DB requirement in v1 (optional adapter comes later).

## Users and key use cases

### Personas

- **Site Admin (primary)**: config, indexing, monitoring, testing retrieval.
- **Developer (secondary)**: uses endpoints in a chatbot, n8n, Zapier, custom agent.

### Core user stories

1. As an admin, I can choose which post types and taxonomies are included.
2. As an admin, I can exclude a page from KB indexing.
3. As an admin, I can reindex everything or just changed content.
4. As a developer, I can call /search and receive ranked chunks with citations.
5. As an admin, I can test queries and inspect why a chunk was returned.

## Functional requirements

### A) Content ingestion and normalization

**Requirements**

- Extract clean text from Gutenberg blocks and rendered content.
- Normalize into a consistent "document" representation:
  - doc_id, post_id, title, url, post_type, taxonomies, author, published_at, updated_at
  - content_clean (plain text) and content_hash
- On save_post, schedule KB reindex for that post if KB is enabled.

**Notes on integration**

Reuse your Phase 1 "Preparation" style approach that already collects post IDs and strips HTML.

### B) Chunking (AI-friendly, citation-ready)

**Requirements**

- Chunk by structure:
  - Prefer H2/H3 boundaries
  - Preserve heading path in metadata
- Token-aware chunk sizing:
  - Defaults: target 450 tokens, overlap 60 tokens
- Stable chunk anchors:
  - Anchor stored per chunk, deterministic from (post_id + heading path + chunk_index + content_hash short)
- Store chunk_text, heading_path, start_offset, end_offset, token_estimate.

**Acceptance criteria**

- Same content produces same chunk anchors.
- Minor edits only reindex affected chunks.

### C) Embeddings via OpenRouter (mandatory)

**Requirements**

- Extend existing AIClient (OpenRouter) to support embeddings calls, not just chat completions. The OpenRouter key is already required via wp-config.
- Admin-configurable embedding model ID (string).
- Batch embeddings in jobs (ex: 10 to 50 chunks per job depending on throttling).
- Store:
  - provider = openrouter
  - model, dims (if provided), created_at
  - vector payload (binary or JSON)

**Failure and retry behavior**

- Handle rate limit errors with exponential backoff, aligned with your existing pattern (E001 rate limit).
- If embedding response is invalid, log and mark chunk as failed, allow manual retry.

### D) Vector storage and retrieval

**v1 storage (MySQL)**

- Implement a "VectorStoreAdapter" with a default MySQL store.
- Store vectors in LONGTEXT (JSON array) or BLOB (packed floats).
- Similarity search method v1:
  - brute force cosine similarity for small sets
  - add caching and narrowing filters (post_type, taxonomy)
- Performance guardrails:
  - cap on scanned vectors per query (configurable)
  - require filters for large sites

**v2 storage (optional later)**

- Add adapters for:
  - pgvector
  - Qdrant
  - Pinecone
- PRD says "adapter ready", not mandatory in initial release.

### E) Retrieval endpoints (RAG API)

Base remains /wp-json/vibe-ai/v1/ and we add a KB namespace under it to keep the plugin cohesive with your existing controller pattern.

**New endpoints**

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/kb/search` | POST | Query search with filters |
| `/kb/docs` | GET | Paginated list of indexed documents |
| `/kb/docs/{post_id}` | GET | Full doc metadata + chunk list |
| `/kb/reindex` | POST | Admin-only trigger for reindexing |
| `/kb/status` | GET | Index size, last run, failures, queue health |

**POST /kb/search**

Input:
- query (string)
- top_k (default 8)
- filters: post_type, taxonomy terms, date ranges, excluded_post_ids

Output:
- list of chunks with:
  - post_id, title, url, anchor
  - heading_path
  - chunk_text
  - score
  - chunk_id

**Auth model**

Default: same as existing endpoints, manage_options.
Optional mode (later): signed token for server-to-server usage.

### F) AI publishing outputs (to make it "AI-first", not just RAG)

These are lightweight and help a lot in practice.

**/llms.txt generator**

- Site-level curated list:
  - About, key categories, "start here" docs
  - optional "full list" mode
- Admin UI to reorder and pin pages.

**AI sitemap**

- Separate XML or JSON sitemap of KB-included pages only.

**"Change feed"**

- JSON feed of recently updated KB docs:
  - post_id, url, updated_at, content_hash

**Stable citations**

- Chunk anchors must resolve to a stable on-page location (or a dedicated "chunk viewer" route in wp-admin).

### G) Admin UI (React) integration

You already have a React app shell with dashboard, entity manager, settings, activity log.

Add a new sidebar section: **Knowledge Base**

**Screens**

1. **KB Overview**
   - total docs indexed
   - total chunks
   - failed chunks
   - last indexing run
   - "Reindex" CTA

2. **Documents**
   - table: title, post type, updated, status, chunks, last indexed
   - quick actions: exclude, reindex, view chunks

3. **Test Search**
   - query box
   - results list with scores
   - click opens source page at anchor
   - "show metadata" toggle

4. **KB Settings**
   - enable KB
   - include post types (default from vibe_ai_post_types filter style)
   - chunk size and overlap
   - embedding model id
   - batch sizes and concurrency
   - safety controls (PII flagging, block indexing if flagged)

5. **KB Logs**
   - filter logs by job type: chunking, embedding, upsert, search

## System design and implementation plan

### 1) New pipeline module (reusing your patterns)

Current entity pipeline phases are 1-6.

Add KB phases as a parallel track that runs per post.

**Proposed KB phases**

| Phase | Name | Description |
|-------|------|-------------|
| KB1 | Document Build | normalize text, compute hash |
| KB2 | Chunk Build | generate chunks, anchors |
| KB3 | Embed Chunks | OpenRouter embeddings |
| KB4 | Upsert Index | store vectors + metadata |
| KB5 | Cleanup | remove stale chunks for edited posts |

**Job classes (new)**

- Jobs/KB/DocumentBuildJob.php
- Jobs/KB/ChunkBuildJob.php
- Jobs/KB/EmbedChunksJob.php
- Jobs/KB/IndexUpsertJob.php
- Jobs/KB/CleanupJob.php

**Pipeline control**

- Extend PipelineManager to schedule KB jobs alongside existing jobs.
- Respect the same throttling concepts and retry semantics you already have.

### 2) Database schema (new tables)

Keep existing tables intact.

**Add:**

**wp_ai_kb_docs**
- id (pk)
- post_id (unique)
- post_type
- title
- url
- content_hash
- chunk_count
- status: indexed | pending | error | excluded
- last_indexed_at
- updated_at

**wp_ai_kb_chunks**
- id (pk)
- doc_id (fk)
- chunk_index
- anchor (unique per doc)
- heading_path_json
- chunk_text (longtext)
- chunk_hash
- token_estimate
- created_at

**wp_ai_kb_vectors**
- chunk_id (unique fk)
- provider (openrouter)
- model
- dims (nullable)
- vector_payload (longtext or blob)
- created_at

**New post meta keys**

Mirror your existing schema cache approach.

- _vibe_ai_kb_enabled
- _vibe_ai_kb_indexed_at
- _vibe_ai_kb_version

### 3) Settings and configuration

Continue the pattern of required wp-config constants for secrets.

**Required**
- VIBE_AI_OPENROUTER_KEY
- VIBE_AI_ENCRYPTION_KEY

**New configurable constants (or settings stored via WP options)**

- KB_EMBEDDING_MODEL (string, default set in settings UI)
- KB_CHUNK_TOKENS_TARGET (int)
- KB_CHUNK_OVERLAP_TOKENS (int)
- KB_TOP_K_DEFAULT (int)
- KB_MAX_SCAN_VECTORS (int, for MySQL store)
- KB_BATCH_SIZE_CHUNKS (int)

You already have a constants block pattern in Config.php.

## Security and privacy

- KB endpoints are admin-only by default, matching your existing pattern.
- Optional later: allow a server token for public retrieval.
- PII detection:
  - flag chunks that contain emails, phone numbers, IDs
  - configurable: "index anyway" vs "block indexing"
- Never log keys or full embedding payloads.
- Log durations like you do today.

## Performance requirements

- Indexing runs exclusively in Action Scheduler jobs.
- Batch sizes adapt using the same "dynamic batch sizing" mindset (you already do it for posts).
- Retrieval endpoint response time targets:
  - MySQL store MVP: under 800ms for small sites (up to a few thousand chunks)
  - If chunks exceed threshold, require filters or recommend external adapter.

## Observability and logs

Reuse your existing logging location and levels.

Add log event types:
- kb_document_built
- kb_chunk_built
- kb_embed_batch_started
- kb_embed_batch_completed
- kb_index_upsert_completed
- kb_search_performed

## Rollout plan

### Phase 1 (MVP)

- New tables
- KB indexing pipeline (doc → chunk → embed → store)
- /kb/search endpoint
- Admin "Test Search" UI
- Per-post include/exclude toggle

### Phase 2 (AI publishing extras)

- /llms.txt
- AI sitemap
- Change feed
- Better citation UX (chunk viewer)

### Phase 3 (Scale)

- External vector store adapter
- Optional reranking (still through OpenRouter)

## Acceptance criteria checklist

- [ ] Enable KB and index 100 pages. No WP admin slowdown.
- [ ] "Test Search" returns relevant chunks with stable anchor links.
- [ ] Editing a page reindexes only that page's chunks.
- [ ] OpenRouter rate limits cause retries, not pipeline failure.
- [ ] Logs clearly show embedding batches and failures.
- [ ] Security defaults match existing behavior.
