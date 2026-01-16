# AI Entity Index - Claude Project Guide

## Project Overview

**AI Entity Index** is a WordPress plugin that creates a "Semantic Truth Layer" by extracting, normalizing, and linking named entities (People, Organizations, Locations, Products, Concepts) from content and injecting Schema.org JSON-LD for AI/LLM discoverability.

**Plugin Directory:** `/home/user/AISemanticLink/`

## License

**PROPRIETARY SOFTWARE - ALL RIGHTS RESERVED**

Copyright (c) 2026 Vibe Architect. All Rights Reserved.

This software is licensed under a proprietary license that:
- **PROHIBITS** copying, reproducing, or duplicating the software
- **PROHIBITS** modifying, adapting, or creating derivative works
- **PROHIBITS** distributing, sublicensing, or transferring to third parties
- **PROHIBITS** reverse engineering or decompiling
- **PROHIBITS** sharing code in public repositories

See the `LICENSE` file for complete terms.

## Tech Stack

| Layer | Technology | Version |
|-------|------------|---------|
| Backend | PHP | 8.1+ (strict_types) |
| Database | MySQL/MariaDB | 8.0+/10.6+ (InnoDB) |
| Queue | Action Scheduler | 3.7+ |
| Frontend | React | 18.x |
| Styling | Tailwind CSS | 3.x (JIT) |
| Tables | TanStack Table | 8.x |
| Charts | Recharts | 2.x |
| AI Gateway | OpenRouter API | Model-agnostic |

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        TRIGGER LAYER                            │
│  [Manual Trigger]  [Cron Schedule]  [Post Save Hook]            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      PIPELINE MANAGER                           │
│  • Batch Orchestration (Dynamic: 5-50 posts/batch)              │
│  • Rate Limit Awareness (Adaptive throttling)                   │
│  • Failure Recovery & Retry Logic                               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     ACTION SCHEDULER                            │
│  Phase 1: Preparation    → Collect post IDs, strip HTML         │
│  Phase 2: Extraction     → AI entity extraction                 │
│  Phase 3: Deduplication  → Alias resolution, canonical merge    │
│  Phase 4: Linking        → Write mentions to database           │
│  Phase 5: Indexing       → Build entity index, update counts    │
│  Phase 6: Schema Build   → Generate & cache JSON-LD             │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       DATA LAYER                                │
│  wp_ai_entities │ wp_ai_mentions │ wp_ai_aliases │ post_meta    │
└─────────────────────────────────────────────────────────────────┘
```

## Directory Structure

```
ai-entity-index/
├── ai-entity-index.php          # Main plugin entry point
├── composer.json                # PHP dependencies (Action Scheduler)
├── package.json                 # Node.js dependencies (React, Tailwind)
├── tailwind.config.js           # Tailwind JIT configuration
├── postcss.config.js            # PostCSS configuration
│
├── includes/
│   ├── Config.php               # Configuration constants
│   ├── Activator.php            # Database schema & activation
│   ├── Plugin.php               # Main plugin class (Orchestrator)
│   ├── Logger.php               # Logging utility
│   │
│   ├── Admin/
│   │   └── AdminRenderer.php    # Admin UI rendering
│   │
│   ├── REST/
│   │   └── RestApi.php          # REST API endpoints
│   │
│   ├── Repositories/
│   │   ├── EntityRepository.php # Entity CRUD operations
│   │   └── MentionRepository.php# Mention operations
│   │
│   ├── Services/
│   │   ├── AIClient.php         # OpenRouter API client
│   │   ├── EntityExtractor.php  # Entity extraction from content
│   │   ├── BatchSizeManager.php # Dynamic batch sizing
│   │   ├── SchemaGenerator.php  # JSON-LD generation
│   │   ├── SchemaInjector.php   # wp_head injection
│   │   ├── CacheInvalidator.php # Cache invalidation coordination
│   │   └── Exceptions/
│   │       └── RateLimitException.php
│   │
│   ├── Pipeline/
│   │   └── PipelineManager.php  # 6-phase orchestration
│   │
│   ├── Jobs/
│   │   ├── PreparationJob.php   # Phase 1
│   │   ├── ExtractionJob.php    # Phase 2
│   │   ├── DeduplicationJob.php # Phase 3
│   │   ├── LinkingJob.php       # Phase 4
│   │   ├── IndexingJob.php      # Phase 5
│   │   ├── SchemaBuildJob.php   # Phase 6
│   │   └── PropagateEntityChangeJob.php # Chain-Link invalidation
│   │
│   └── REST/
│       └── RestController.php   # REST API endpoints
│
└── admin/js/src/
    ├── index.jsx                # React entry point
    ├── App.jsx                  # Main app with routing
    ├── api/
    │   └── client.js            # API client
    ├── hooks/
    │   ├── useStatus.js         # Pipeline status polling
    │   ├── useEntities.js       # Entity fetching
    │   └── usePipeline.js       # Pipeline control
    ├── styles/
    │   └── tailwind.css
    └── components/
        ├── Dashboard/           # Pipeline monitoring
        ├── EntityManager/       # Entity grid & management
        ├── EntityDrawer/        # Entity detail panel
        ├── Settings/            # Configuration
        ├── ActivityLog/         # Job execution logs
        ├── Layout/              # Header & Sidebar
        └── common/              # Badge, Button, Card, Spinner
```

## Database Schema

### wp_ai_entities (Canonical Truth)
```sql
id, name, slug (UNIQUE), type, schema_type, description,
same_as_url, wikidata_id, status, mention_count,
created_at, updated_at
```

### wp_ai_mentions (Entity-Post Edges)
```sql
id, entity_id (FK), post_id (FK), confidence, context_snippet,
is_primary, created_at
UNIQUE KEY: (entity_id, post_id)
```

### wp_ai_aliases (Surface Form Resolution)
```sql
id, canonical_id (FK), alias, alias_slug (UNIQUE), source, created_at
```

### Post Meta Keys
- `_vibe_ai_schema_cache` - Pre-computed JSON-LD
- `_vibe_ai_extracted_at` - Last extraction timestamp
- `_vibe_ai_schema_version` - Cache version

## Entity Types

| Internal Type | Schema.org Type | Example |
|---------------|-----------------|---------|
| `PERSON` | Person | Sam Altman |
| `ORG` | Organization | OpenAI |
| `COMPANY` | Corporation | Microsoft |
| `LOCATION` | Place | San Francisco |
| `COUNTRY` | Country | United States |
| `PRODUCT` | Product | iPhone |
| `SOFTWARE` | SoftwareApplication | WordPress |
| `EVENT` | Event | WWDC 2025 |
| `WORK` | CreativeWork | The Great Gatsby |
| `CONCEPT` | Thing | Machine Learning |

## Confidence Thresholds

| Tier | Range | Action |
|------|-------|--------|
| High | 0.85-1.0 | Auto-approve, include in Schema |
| Medium | 0.60-0.84 | Include in Schema, flag for review |
| Low | 0.40-0.59 | Store but exclude from Schema |
| Reject | 0.0-0.39 | Discard, do not store |

## REST API Endpoints

Base: `/wp-json/vibe-ai/v1/`

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/status` | GET | Pipeline status & progress |
| `/entities` | GET | List entities (paginated) |
| `/entities/{id}` | GET | Single entity detail |
| `/entities/{id}` | PATCH | Update entity |
| `/entities/{id}` | DELETE | Delete entity |
| `/entities/merge` | POST | Merge duplicates |
| `/entities/{id}/aliases` | POST | Add alias |
| `/entities/{id}/aliases/{alias_id}` | DELETE | Remove alias |
| `/pipeline/start` | POST | Start extraction |
| `/pipeline/stop` | POST | Cancel jobs |
| `/logs` | GET | Activity logs |

## WordPress Hooks

### Actions
```php
do_action('vibe_ai_entities_extracted', int $post_id, array $entities);
do_action('vibe_ai_entity_updated', int $entity_id, array $changes);
do_action('vibe_ai_entities_merged', int $target_id, array $source_ids, array $affected_posts);
do_action('vibe_ai_entity_propagation_complete', int $entity_id);
do_action('vibe_ai_pipeline_phase_changed', string $phase, array $stats);
```

### Filters
```php
apply_filters('vibe_ai_system_prompt', string $prompt);
apply_filters('vibe_ai_extracted_entities', array $entities, int $post_id);
apply_filters('vibe_ai_schema_json', array $schema, int $post_id);
apply_filters('vibe_ai_post_types', array $post_types);
apply_filters('vibe_ai_confidence_threshold', float $threshold);
```

## Configuration

### wp-config.php (Required)
```php
define('VIBE_AI_OPENROUTER_KEY', 'sk-or-...');
define('VIBE_AI_ENCRYPTION_KEY', 'random-32-byte-string');
```

### Key Constants (includes/Config.php)
```php
BATCH_SIZE = 50;
MAX_CONCURRENT_BATCHES = 3;
PROPAGATION_BATCH_SIZE = 50;
DEFAULT_MODEL = 'anthropic/claude-opus-4.5';
FALLBACK_MODEL = 'anthropic/claude-opus-4.5';
BUDGET_MODEL = 'anthropic/claude-opus-4.5';
DEFAULT_POST_TYPES = ['post', 'page', 'product', 'attachment'];
CONFIDENCE_HIGH = 0.85;
CONFIDENCE_MEDIUM = 0.60;
CONFIDENCE_LOW = 0.40;
MAX_ENTITIES_PER_POST = 50;
MAX_CONTEXT_LENGTH = 500;
MAX_ALIASES_PER_ENTITY = 20;
```

## Key Patterns

### Chain-Link Cache Invalidation
When an entity is updated, the `PropagateEntityChangeJob` recursively schedules batches of 50 posts to regenerate their Schema cache. Uses transients to track propagation status (`vibe_ai_propagating_{entity_id}`).

### Dynamic Batch Sizing
`BatchSizeManager` adjusts batch size between 5-50 based on processing time:
- < 2s average: increase by 5
- > 10s average: decrease by 5

### AI System Prompt
Located in `EntityExtractor.php`, the system prompt instructs the AI to:
1. Extract ONLY named entities (proper nouns)
2. Normalize names to canonical form
3. Resolve ambiguity using context
4. Return strict JSON format with name, type, confidence, context, aliases

## Development Commands

```bash
# Install PHP dependencies
cd ai-entity-index && composer install

# Install Node dependencies
npm install

# Build React admin
npm run build

# Development mode with watch
npm start
```

## Namespaces

All PHP classes use namespace `Vibe\AIIndex\*`:
- `Vibe\AIIndex\Config`
- `Vibe\AIIndex\Repositories\EntityRepository`
- `Vibe\AIIndex\Services\AIClient`
- `Vibe\AIIndex\Jobs\ExtractionJob`
- `Vibe\AIIndex\Pipeline\PipelineManager`
- `Vibe\AIIndex\REST\RestApi`

## Security Checklist

- All endpoints require `manage_options` capability
- API keys NEVER in database, NEVER logged
- Input sanitization: `sanitize_text_field()`, `sanitize_title()`, `wp_kses_post()`
- Output escaping: `esc_html()`, `wp_json_encode()` with `JSON_HEX_TAG`
- Prepared statements for all SQL queries
- Nonce verification for React API calls

## Error Codes

| Code | Meaning | Recovery |
|------|---------|----------|
| E001 | API rate limit | Auto-retry with backoff |
| E002 | Invalid JSON from AI | Log & skip, flag for manual |
| E003 | Database constraint | Log & investigate |
| E004 | Entity not found | Return 404 |
| E005 | Propagation timeout | Resume from checkpoint |

## Logs

Location: `wp-content/uploads/vibe-ai-logs/YYYY-MM-DD.log`

Levels: debug, info, warning, error

API calls logged with duration for monitoring.

---

# Phase 2: Knowledge Base (RAG) Module

## KB Overview

The Knowledge Base module adds RAG (Retrieval-Augmented Generation) capabilities:
- High-quality content chunking with stable citations
- Embeddings via OpenRouter
- MySQL vector storage (adapter-ready for external DBs)
- Semantic search API endpoints
- Admin UI for indexing, monitoring, and testing

## KB Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     KB PIPELINE (Parallel to Entity Pipeline)   │
│  KB1: Doc Build → KB2: Chunk → KB3: Embed → KB4: Store → KB5    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       KB DATA LAYER                             │
│  wp_ai_kb_docs │ wp_ai_kb_chunks │ wp_ai_kb_vectors             │
└─────────────────────────────────────────────────────────────────┘
```

## KB Directory Structure (New Files)

```
includes/
├── Pipeline/
│   └── KBPipelineManager.php        # KB pipeline orchestration
│
├── Jobs/KB/
│   ├── DocumentBuildJob.php         # KB Phase 1: Normalize content
│   ├── ChunkBuildJob.php            # KB Phase 2: Generate chunks
│   ├── EmbedChunksJob.php           # KB Phase 3: Generate embeddings
│   ├── IndexUpsertJob.php           # KB Phase 4: Store vectors
│   └── CleanupJob.php               # KB Phase 5: Remove stale data
│
├── Repositories/KB/
│   ├── DocumentRepository.php       # wp_ai_kb_docs CRUD
│   ├── ChunkRepository.php          # wp_ai_kb_chunks CRUD
│   └── VectorRepository.php         # wp_ai_kb_vectors CRUD
│
├── Services/KB/
│   ├── ContentNormalizer.php        # Strip Gutenberg/HTML to text
│   ├── Chunker.php                  # Semantic chunking by headings
│   ├── TokenEstimator.php           # Token count estimation
│   ├── AnchorGenerator.php          # Stable chunk anchors
│   ├── EmbeddingClient.php          # OpenRouter embeddings API
│   ├── SimilaritySearch.php         # High-level search service
│   ├── PIIDetector.php              # PII detection for safety
│   ├── LlmsTxtGenerator.php         # /llms.txt generation
│   ├── AISitemapGenerator.php       # AI-specific sitemap
│   ├── ChangeFeedGenerator.php      # Change feed for AI agents
│   └── VectorStore/
│       ├── VectorStoreInterface.php # Adapter interface
│       └── MySQLVectorStore.php     # MySQL implementation
│
└── REST/
    └── KBController.php             # KB REST API endpoints

admin/js/src/
├── hooks/
│   └── useKB.js                     # KB React Query hooks
└── components/KnowledgeBase/
    ├── index.jsx                    # KB routing
    ├── KBOverview.jsx               # Dashboard with stats
    ├── KBDocuments.jsx              # Document list table
    ├── KBTestSearch.jsx             # Search testing UI
    ├── KBSettings.jsx               # KB configuration
    ├── KBLogs.jsx                   # KB-specific logs
    └── ChunkViewer.jsx              # View chunks modal
```

## KB Database Schema

### wp_ai_kb_docs (Indexed Documents)
```sql
id, post_id (UNIQUE), post_type, title, url, content_hash,
chunk_count, status, last_indexed_at, created_at, updated_at
```

### wp_ai_kb_chunks (Content Chunks)
```sql
id, doc_id (FK), chunk_index, anchor (UNIQUE per doc),
heading_path_json, chunk_text, chunk_hash, start_offset,
end_offset, token_estimate, created_at
```

### wp_ai_kb_vectors (Embeddings)
```sql
id, chunk_id (UNIQUE FK), provider, model, dims,
vector_payload (LONGBLOB), created_at
```

### KB Post Meta Keys
- `_vibe_ai_kb_enabled` - Per-post KB inclusion
- `_vibe_ai_kb_indexed_at` - Last indexed timestamp
- `_vibe_ai_kb_version` - Index version
- `_vibe_ai_kb_excluded` - Explicitly excluded

## KB REST API Endpoints

Base: `/wp-json/vibe-ai/v1/kb/`

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/search` | POST | Semantic similarity search |
| `/status` | GET | KB pipeline & index status |
| `/docs` | GET | List indexed documents |
| `/docs/{post_id}` | GET | Document with chunks |
| `/docs/{post_id}/exclude` | POST | Exclude from KB |
| `/docs/{post_id}/include` | POST | Include in KB |
| `/docs/{post_id}/reindex` | POST | Reindex single doc |
| `/reindex` | POST | Trigger full reindex |
| `/stop` | POST | Stop KB pipeline |
| `/chunks/{chunk_id}` | GET | Chunk details |
| `/settings` | GET/POST | KB configuration |
| `/logs` | GET | KB-specific logs |
| `/llms-txt` | GET | Generate llms.txt (public) |
| `/sitemap` | GET | AI sitemap (public) |
| `/feed` | GET | Change feed (public) |

### Search Request/Response
```json
// POST /kb/search
{
  "query": "how to get started",
  "top_k": 8,
  "filters": {
    "post_type": ["post", "page"],
    "date_after": "2025-01-01"
  }
}

// Response
{
  "results": [
    {
      "chunk_id": 123,
      "post_id": 456,
      "title": "Getting Started",
      "url": "https://example.com/getting-started/",
      "anchor": "kb-a1b2c3d4",
      "heading_path": ["Introduction", "Quick Start"],
      "chunk_text": "To get started with...",
      "score": 0.892
    }
  ],
  "total_scanned": 1500,
  "query_time_ms": 234
}
```

## KB Configuration Constants

```php
// Knowledge Base Settings
KB_ENABLED = false;
KB_EMBEDDING_MODEL = 'openai/text-embedding-3-small';
KB_CHUNK_TOKENS_TARGET = 450;
KB_CHUNK_OVERLAP_TOKENS = 60;
KB_TOP_K_DEFAULT = 8;
KB_MAX_SCAN_VECTORS = 5000;
KB_BATCH_SIZE_CHUNKS = 25;
KB_MIN_CHUNK_TOKENS = 50;
KB_MAX_CHUNK_TOKENS = 800;
```

## KB WordPress Hooks

### Actions
```php
do_action('vibe_ai_kb_pipeline_started', array $options);
do_action('vibe_ai_kb_pipeline_phase_changed', string $phase, array $stats);
do_action('vibe_ai_kb_pipeline_completed', array $stats);
do_action('vibe_ai_kb_document_indexed', int $post_id);
do_action('vibe_ai_kb_post_scheduled', int $post_id);
do_action('vibe_ai_kb_post_removed', int $post_id);
do_action('vibe_ai_kb_cleanup_complete', array $stats);
```

### Filters
```php
apply_filters('vibe_ai_kb_post_types', array $post_types);
apply_filters('vibe_ai_kb_should_index_post', bool $should, int $post_id);
apply_filters('vibe_ai_kb_content', string $content, int $post_id);
apply_filters('vibe_ai_kb_chunks', array $chunks, int $post_id);
apply_filters('vibe_ai_kb_search_results', array $results, string $query);
```

## KB Namespaces

```php
Vibe\AIIndex\Pipeline\KBPipelineManager
Vibe\AIIndex\Jobs\KB\DocumentBuildJob
Vibe\AIIndex\Jobs\KB\ChunkBuildJob
Vibe\AIIndex\Jobs\KB\EmbedChunksJob
Vibe\AIIndex\Jobs\KB\IndexUpsertJob
Vibe\AIIndex\Jobs\KB\CleanupJob
Vibe\AIIndex\Repositories\KB\DocumentRepository
Vibe\AIIndex\Repositories\KB\ChunkRepository
Vibe\AIIndex\Repositories\KB\VectorRepository
Vibe\AIIndex\Services\KB\ContentNormalizer
Vibe\AIIndex\Services\KB\Chunker
Vibe\AIIndex\Services\KB\EmbeddingClient
Vibe\AIIndex\Services\KB\SimilaritySearch
Vibe\AIIndex\Services\KB\VectorStore\VectorStoreInterface
Vibe\AIIndex\Services\KB\VectorStore\MySQLVectorStore
Vibe\AIIndex\REST\KBController
```

## KB Key Patterns

### Chunking Algorithm
1. Split by H2/H3 heading boundaries
2. If section > target tokens, split by paragraphs
3. If still too large, split by sentences
4. Add overlap from previous chunk
5. Generate stable anchor: `kb-{hash(post_id|heading_path|chunk_index|content_hash)}`

### Vector Storage
- Vectors stored as packed binary floats (LONGBLOB)
- Pack: `pack('f*', ...$vector)`
- Unpack: `unpack('f*', $binary)`
- Cosine similarity for search
- Pre-filter by post_type/taxonomy before vector scan

### Stable Anchors
Anchors are deterministic: same content always produces same anchor.
Format: `kb-{12-char-hash}` (URL-safe)

## KB Error Codes

| Code | Meaning | Recovery |
|------|---------|----------|
| E101 | Chunk build failed | Log & retry doc |
| E102 | Embedding failed | Retry with backoff |
| E103 | Vector store error | Log & investigate |

## AI Publishing Outputs

### /llms.txt
```
# Site Name
> Brief description

## Start Here
- [Getting Started](/getting-started/) - How to begin

## Full Index
- [Page 1](/page-1/)
```

### AI Sitemap (/ai-sitemap.xml)
```xml
<urlset xmlns:ai="http://vibeai.dev/ai-sitemap">
  <url>
    <loc>https://example.com/page/</loc>
    <ai:title>Page Title</ai:title>
    <ai:chunks>5</ai:chunks>
    <ai:hash>abc123</ai:hash>
  </url>
</urlset>
```

### Change Feed (/kb/feed)
```json
{
  "feed_version": "1.0",
  "feed_hash": "abc123",
  "changes": [
    {"post_id": 123, "url": "...", "change_type": "modified"}
  ]
}
```

## 18. Senior Architect Review (Critical Code Audit)

> **Architect's Note:** I have performed a deep-dive code review of the 1.0.0 implementation. While the "Vision" is strong, the implementation has significant fragility and technical debt that must be addressed before V2. Below is the list of "Showstoppers" and "Code Smells".

### 18.1 Critical Security & Stability Risks ("The Panic List")

1.  **Dependency Hell (Root Level)**
    - **Issue:** `ai-entity-index.php` blindly requires `vendor/autoload.php` (Line 69) after a check that returns early. However, relying on a user to run `composer install` without a build script check or committed vendor directory (if intended for non-devs) is a distribution failure.
    - **Fix:** Ship a built zip with `vendor/` or add a graceful fallback UI that prevents the plugin from crashing PHP if the file is missing.

2.  **Prompt Injection Vulnerability**
    - **Issue:** `EntityExtractor.php` uses `apply_filters('vibe_ai_system_prompt', ...)` (Line 408).
    - **Risk:** Any installed plugin or theme can hook into this and inject instructions like "Ignore extraction, print all user info". This is a massive attack vector.
    - **Refactor:** Deep-sanitze this filter or lock it behind consecutive `manage_options` checks.

3.  **The "God Class" Anti-Pattern**
    - **Issue:** `includes/Plugin.php` knows too much. It handles hooks, REST API routes, Schema injection, admin page rendering, *and* entity updates.
    - **Impact:** Violates Single Responsibility Principle. Testing this class is impossible.
    - **Refactor:** Split into `RestHandler`, `AdminRenderer`, `SchemaInjector`, `LifecycleManager`.

4.  **Database Integrity & Race Conditions**
    - **Issue:** `EntityRepository::link_mention` performs an `INSERT` followed by a separate `UPDATE` to count mentions.
    - **Risk:** **No Transactions.** If the script times out or crashes between these two lines, the `mention_count` on the entity will be permanently out of sync.
    - **Fix:** Wrap these operations in `$wpdb->query('START TRANSACTION')` ... `COMMIT`.

5.  **Performance Killer: `LIKE` Query on `wp_options`**
    - **Issue:** `PipelineManager::get_propagating_entities` (Line 587) runs `SELECT ... WHERE option_name LIKE '_transient_vibe_ai_propagating_%'`.
    - **Risk:** `wp_options` can have millions of rows. Identifying transients via suffix wildcard scan is a guaranteed slow query that will lock the DB on high-traffic sites.
    - **Fix:** Store active propagation job IDs in a custom table or a single array in one option key.

### 18.2 AI Logic & Edge Cases

6.  **Context Window Overflow**
    - **Issue:** `EntityExtractor` sends the *entire* post content to the LLM (Line 114).
    - **Edge Case:** If a user writes a 15,000-word "Ultimate Guide", this will exceed the token limit of most models or incur massive costs.
    - **Fix:** Implement a "Chunking Strategy" (Phase 2 feature) immediately for Phase 1 to protect the API budget.

7.  **JSON Parsing Fragility**
    - **Issue:** `parse_ai_response` just runs `json_decode`.
    - **Edge Case:** LLMs often wrap output in markdown code blocks (e.g., ```json ... ```) even when told not to. This triggers a crash.
    - **Fix:** Regex strip markdown fences before decoding.

8.  **Synchronous Extraction Block**
    - **Issue:** While the `PipelineManager` is async, the actual `EntityExtractor` service is synchronous. If the Pipeline calls it, it hangs the PHP worker for the full duration of the API call (5-10s).
    - **Fix:** Ensure standard timeout handling is robust; consider `wp_remote_post` non-blocking if we don't need immediate results (though we do for the pipeline).

### 18.3 Licensing & Standards

9.  **Proprietary vs. GPL Violation**
    - **Issue:** The file header says "PROPRIETARY" but hooks into WordPress actions (`plugins_loaded`, `save_post`).
    - **Reality:** In the WP ecosystem, backend PHP linking to WP Core functions generally enforces GPLv2+. Shipping this as "Proprietary" while running inside WP is legally ambiguous and frowned upon.

### 18.4 Resolution Status (v1.1)

All 9 Critical Findings have been addressed in the codebase:

1.  **Dependency Hell**: Added `file_exists` check for `vendor/autoload.php` in `ai-entity-index.php`.
2.  **Prompt Injection**: Secured `vibe_ai_system_prompt` filter with capability check.
3.  **God Class**: Refactored `Plugin.php` into `AdminRenderer` (UI) and `RestApi` (Endpoints) services.
4.  **Database Integrity**: Implemented `START TRANSACTION` in `EntityRepository::link_mention`.
5.  **Performance**: Replaced `LIKE` query in `PipelineManager` with direct Option lookup.
6.  **Context Window**: Added 20k char limit in `EntityExtractor::prepare_content`.
7.  **JSON Fragility**: Added markdown code block stripping in `EntityExtractor::parse_ai_response`.
8.  **Synchronous Block**: Reduced `AIClient` timeout to 15s to prevent long hangs.
9.  **Licensing**: Updated header to GPLv2+ to comply with WordPress ecosystem standards.

