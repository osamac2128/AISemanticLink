# Phase 2 Implementation Plan: Knowledge Base (RAG) Module

## Overview

This plan implements the Knowledge Base module as a **parallel track** to the existing entity extraction pipeline. No existing functionality will be modified or broken.

## Architecture Integration

```
┌─────────────────────────────────────────────────────────────────┐
│                     EXISTING PIPELINE (Unchanged)               │
│  Phase 1-6: Entity Extraction → Dedup → Link → Index → Schema   │
└─────────────────────────────────────────────────────────────────┘
                              │
                    (Parallel, Independent)
                              │
┌─────────────────────────────────────────────────────────────────┐
│                     NEW KB PIPELINE (Added)                     │
│  KB1: Doc Build → KB2: Chunk → KB3: Embed → KB4: Store → KB5    │
└─────────────────────────────────────────────────────────────────┘
```

## Parallel Agent Assignments

### Agent 1: Database Schema & Configuration
**Files to create:**
- Update `includes/Activator.php` - Add new tables (preserve existing)
- Update `includes/Config.php` - Add KB constants

**New Tables:**
```sql
wp_ai_kb_docs (post_id UNIQUE, status, content_hash, chunk_count...)
wp_ai_kb_chunks (doc_id FK, anchor UNIQUE per doc, heading_path_json...)
wp_ai_kb_vectors (chunk_id UNIQUE FK, vector_payload LONGBLOB...)
```

**New Config Constants:**
```php
KB_ENABLED, KB_EMBEDDING_MODEL, KB_CHUNK_TOKENS_TARGET (450),
KB_CHUNK_OVERLAP_TOKENS (60), KB_TOP_K_DEFAULT (8),
KB_MAX_SCAN_VECTORS (5000), KB_BATCH_SIZE_CHUNKS (25)
```

---

### Agent 2: Core Services - Chunker & Token Estimator
**Files to create:**
- `includes/Services/KB/ContentNormalizer.php`
- `includes/Services/KB/Chunker.php`
- `includes/Services/KB/TokenEstimator.php`
- `includes/Services/KB/AnchorGenerator.php`

**ContentNormalizer:**
- Strip Gutenberg blocks to plain text
- Extract heading structure (H2/H3)
- Compute content_hash (SHA256)

**Chunker:**
- Split by H2/H3 boundaries preferentially
- Token-aware sizing (target 450, overlap 60)
- Preserve heading_path metadata
- Generate stable anchors

**TokenEstimator:**
- Simple word-based estimation (words * 1.3)
- Or tiktoken-compatible if available

**AnchorGenerator:**
- Deterministic anchor from: post_id + heading_path + chunk_index + hash_short
- URL-safe format

---

### Agent 3: Core Services - Embeddings & Vector Store
**Files to create:**
- `includes/Services/KB/EmbeddingClient.php`
- `includes/Services/KB/VectorStore/VectorStoreInterface.php`
- `includes/Services/KB/VectorStore/MySQLVectorStore.php`
- `includes/Services/KB/SimilaritySearch.php`

**EmbeddingClient:**
- Extend AIClient pattern for embeddings endpoint
- OpenRouter embeddings API call
- Batch support (multiple texts per request)
- Return dimensions + vector array

**VectorStoreInterface:**
```php
interface VectorStoreInterface {
    public function store(int $chunkId, array $vector, array $meta): void;
    public function delete(int $chunkId): void;
    public function search(array $queryVector, int $topK, array $filters): array;
    public function count(array $filters = []): int;
}
```

**MySQLVectorStore:**
- Store vectors as LONGBLOB (packed floats)
- Brute-force cosine similarity
- Filter by post_type, taxonomy before scan
- Cap scanned vectors (KB_MAX_SCAN_VECTORS)

**SimilaritySearch:**
- Cosine similarity calculation
- Result ranking and scoring
- Filter application

---

### Agent 4: KB Pipeline Jobs (5 Jobs)
**Files to create:**
- `includes/Jobs/KB/DocumentBuildJob.php`
- `includes/Jobs/KB/ChunkBuildJob.php`
- `includes/Jobs/KB/EmbedChunksJob.php`
- `includes/Jobs/KB/IndexUpsertJob.php`
- `includes/Jobs/KB/CleanupJob.php`

**DocumentBuildJob (KB1):**
- Collect posts by post_type filter
- Normalize content via ContentNormalizer
- Compute content_hash
- Insert/update wp_ai_kb_docs
- Schedule KB2 for each doc

**ChunkBuildJob (KB2):**
- Load doc from wp_ai_kb_docs
- Run Chunker to generate chunks
- Generate stable anchors
- Insert into wp_ai_kb_chunks
- Schedule KB3 batch

**EmbedChunksJob (KB3):**
- Batch chunks (25 per job default)
- Call EmbeddingClient
- Handle rate limits with backoff
- Store results temporarily
- Schedule KB4

**IndexUpsertJob (KB4):**
- Take embedded chunks
- Store in VectorStore
- Update doc status to 'indexed'
- Update chunk_count

**CleanupJob (KB5):**
- Compare old chunks vs new chunks by hash
- Delete stale chunks and vectors
- Update doc metadata

---

### Agent 5: KB Pipeline Manager
**Files to create:**
- `includes/Pipeline/KBPipelineManager.php`

**Features:**
- Separate from entity PipelineManager (no conflicts)
- Track KB-specific phases (KB1-KB5)
- Store state in separate options:
  - `vibe_ai_kb_pipeline_status`
  - `vibe_ai_kb_pipeline_phase`
  - `vibe_ai_kb_pipeline_progress`
- Methods:
  - `start(array $options)` - scope: all, post_type, post_id
  - `stop()`
  - `get_status()`
  - `schedule_post(int $post_id)` - for save_post hook

---

### Agent 6: KB Repositories
**Files to create:**
- `includes/Repositories/KB/DocumentRepository.php`
- `includes/Repositories/KB/ChunkRepository.php`
- `includes/Repositories/KB/VectorRepository.php`

**DocumentRepository:**
- CRUD for wp_ai_kb_docs
- `upsert_document()`, `get_by_post_id()`, `set_status()`
- `get_pending_documents()`, `get_stats()`

**ChunkRepository:**
- CRUD for wp_ai_kb_chunks
- `insert_chunks()`, `get_by_doc_id()`, `delete_by_doc_id()`
- `get_chunk_by_anchor()`

**VectorRepository:**
- CRUD for wp_ai_kb_vectors
- Wraps VectorStoreInterface
- `store_vector()`, `delete_vector()`, `search()`

---

### Agent 7: REST API - KB Endpoints
**Files to create:**
- `includes/REST/KBController.php`

**Endpoints (all under /wp-json/vibe-ai/v1/kb/):**

| Method | Endpoint | Handler |
|--------|----------|---------|
| POST | /kb/search | `search()` |
| GET | /kb/docs | `get_documents()` |
| GET | /kb/docs/{post_id} | `get_document()` |
| POST | /kb/docs/{post_id}/exclude | `exclude_document()` |
| POST | /kb/docs/{post_id}/include | `include_document()` |
| POST | /kb/reindex | `trigger_reindex()` |
| GET | /kb/status | `get_status()` |
| GET | /kb/chunks/{chunk_id} | `get_chunk()` |

**Search Response Schema:**
```json
{
  "results": [
    {
      "chunk_id": 123,
      "post_id": 456,
      "title": "Post Title",
      "url": "https://...",
      "anchor": "kb-chunk-abc123",
      "heading_path": ["Introduction", "Getting Started"],
      "chunk_text": "...",
      "score": 0.89
    }
  ],
  "total_scanned": 1500,
  "query_time_ms": 245
}
```

---

### Agent 8: React Admin UI - KB Components
**Files to create:**
- `admin/js/src/components/KnowledgeBase/index.jsx` (KB Overview)
- `admin/js/src/components/KnowledgeBase/KBOverview.jsx`
- `admin/js/src/components/KnowledgeBase/KBDocuments.jsx`
- `admin/js/src/components/KnowledgeBase/KBTestSearch.jsx`
- `admin/js/src/components/KnowledgeBase/KBSettings.jsx`
- `admin/js/src/components/KnowledgeBase/KBLogs.jsx`
- `admin/js/src/components/KnowledgeBase/ChunkViewer.jsx`
- `admin/js/src/hooks/useKB.js`

**KBOverview:**
- Stats cards: docs indexed, total chunks, failed, last run
- Pipeline status (if running)
- "Reindex All" CTA button
- Recent activity feed

**KBDocuments:**
- TanStack Table with columns: Title, Post Type, Status, Chunks, Last Indexed
- Actions: Exclude, Include, Reindex, View Chunks
- Filters: post_type, status
- Pagination

**KBTestSearch:**
- Query input box
- Top-K selector
- Filter panel (post_type, date range)
- Results list with:
  - Score badge
  - Heading path breadcrumb
  - Chunk text preview
  - "View Source" link (opens at anchor)
- Metadata toggle (shows full chunk details)

**KBSettings:**
- Enable/disable KB toggle
- Post types checkboxes
- Embedding model ID input
- Chunk size/overlap sliders
- Batch size input
- PII detection toggle
- Max scan vectors input

**KBLogs:**
- Filter by event type (document_built, chunk_built, embed_*, search)
- Date picker
- Paginated log entries

---

### Agent 9: WordPress Integration Hooks
**Files to update:**
- `includes/Plugin.php` - Add KB hooks (preserve existing)

**New hooks to register:**

```php
// On post save, schedule KB reindex if enabled
add_action('save_post', [$this, 'schedule_kb_reindex'], 20, 2);

// On post delete, remove from KB
add_action('before_delete_post', [$this, 'remove_from_kb']);

// On post trash, mark excluded
add_action('wp_trash_post', [$this, 'exclude_from_kb']);
```

**New filters:**
```php
// Filter post types for KB
apply_filters('vibe_ai_kb_post_types', ['post', 'page']);

// Filter content before chunking
apply_filters('vibe_ai_kb_content', $content, $post_id);

// Filter chunks before embedding
apply_filters('vibe_ai_kb_chunks', $chunks, $post_id);

// Modify search results
apply_filters('vibe_ai_kb_search_results', $results, $query);
```

---

### Agent 10: AI Publishing Outputs (Phase 2 items - scaffold only)
**Files to create:**
- `includes/Services/KB/LlmsTxtGenerator.php`
- `includes/Services/KB/AISitemapGenerator.php`
- `includes/Services/KB/ChangeFeedGenerator.php`

**LlmsTxtGenerator:**
- Generate /llms.txt with curated pages
- Admin-configurable order/pinning
- Optional "full list" mode

**AISitemapGenerator:**
- XML/JSON sitemap of KB-indexed pages only
- Register rewrite rule for /ai-sitemap.xml

**ChangeFeedGenerator:**
- JSON feed at /wp-json/vibe-ai/v1/kb/feed
- Recently updated docs with hashes

---

## File Structure (New Files Only)

```
ai-entity-index/
├── includes/
│   ├── Activator.php                    # UPDATE: Add KB tables
│   ├── Config.php                       # UPDATE: Add KB constants
│   ├── Plugin.php                       # UPDATE: Add KB hooks
│   │
│   ├── Pipeline/
│   │   └── KBPipelineManager.php        # NEW
│   │
│   ├── Jobs/KB/
│   │   ├── DocumentBuildJob.php         # NEW
│   │   ├── ChunkBuildJob.php            # NEW
│   │   ├── EmbedChunksJob.php           # NEW
│   │   ├── IndexUpsertJob.php           # NEW
│   │   └── CleanupJob.php               # NEW
│   │
│   ├── Repositories/KB/
│   │   ├── DocumentRepository.php       # NEW
│   │   ├── ChunkRepository.php          # NEW
│   │   └── VectorRepository.php         # NEW
│   │
│   ├── Services/KB/
│   │   ├── ContentNormalizer.php        # NEW
│   │   ├── Chunker.php                  # NEW
│   │   ├── TokenEstimator.php           # NEW
│   │   ├── AnchorGenerator.php          # NEW
│   │   ├── EmbeddingClient.php          # NEW
│   │   ├── SimilaritySearch.php         # NEW
│   │   ├── PIIDetector.php              # NEW
│   │   ├── LlmsTxtGenerator.php         # NEW (scaffold)
│   │   ├── AISitemapGenerator.php       # NEW (scaffold)
│   │   ├── ChangeFeedGenerator.php      # NEW (scaffold)
│   │   └── VectorStore/
│   │       ├── VectorStoreInterface.php # NEW
│   │       └── MySQLVectorStore.php     # NEW
│   │
│   └── REST/
│       └── KBController.php             # NEW
│
└── admin/js/src/
    ├── App.jsx                          # UPDATE: Add KB routes
    ├── components/
    │   ├── Layout/
    │   │   └── Sidebar.jsx              # UPDATE: Add KB nav item
    │   └── KnowledgeBase/
    │       ├── index.jsx                # NEW
    │       ├── KBOverview.jsx           # NEW
    │       ├── KBDocuments.jsx          # NEW
    │       ├── KBTestSearch.jsx         # NEW
    │       ├── KBSettings.jsx           # NEW
    │       ├── KBLogs.jsx               # NEW
    │       └── ChunkViewer.jsx          # NEW
    └── hooks/
        └── useKB.js                     # NEW
```

## Parallel Execution Plan

```
┌─────────────────────────────────────────────────────────────────┐
│                    PARALLEL WAVE 1                              │
│  (No dependencies, can run simultaneously)                      │
├─────────────────────────────────────────────────────────────────┤
│  Agent 1: Database & Config                                     │
│  Agent 2: Chunker & Token Services                              │
│  Agent 3: Embeddings & Vector Store                             │
│  Agent 6: KB Repositories                                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    PARALLEL WAVE 2                              │
│  (Depends on Wave 1 services being ready)                       │
├─────────────────────────────────────────────────────────────────┤
│  Agent 4: KB Pipeline Jobs (5 jobs)                             │
│  Agent 5: KB Pipeline Manager                                   │
│  Agent 7: REST API Endpoints                                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    PARALLEL WAVE 3                              │
│  (Depends on API being ready)                                   │
├─────────────────────────────────────────────────────────────────┤
│  Agent 8: React Admin UI (all KB components)                    │
│  Agent 9: WordPress Integration Hooks                           │
│  Agent 10: AI Publishing (scaffold)                             │
└─────────────────────────────────────────────────────────────────┘
```

## Risk Mitigation

### No Breaking Changes
- All new code in separate namespaces (KB/)
- New tables don't touch existing tables
- New options use `vibe_ai_kb_` prefix
- Existing pipeline phases unchanged
- Existing API endpoints unchanged

### Graceful Degradation
- KB disabled by default (opt-in)
- MySQL vector store works out-of-box
- PII detection is optional
- Filters for large sites prevent timeouts

### Error Handling
- Reuse existing error codes (E001-E005)
- Add KB-specific: E101 (chunk failed), E102 (embed failed)
- All failures logged, retryable via admin UI

## Estimated New Files: 27 files
## Estimated Lines of Code: ~8,000-10,000
