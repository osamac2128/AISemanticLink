# Knowledge Base API

> Docs home: `docs/index.md`

Base: `/wp-json/vibe-ai/v1/kb`

All admin endpoints require `manage_options` capability unless noted as **(public)**. Admin request and response bodies are JSON. Public AI publishing endpoints may return `text/plain`, `application/json`, or `application/xml`.

---

## Search and status

### POST /kb/search

Semantic similarity search over chunked and embedded content.

**Request body**:

```json
{
  "query": "how to get started",
  "top_k": 8,
  "filters": {
    "post_type": ["post", "page"],
    "date_after": "2025-01-01"
  }
}
```

| Parameter | Type | Default | Constraints | Description |
|-----------|------|---------|-------------|-------------|
| `query` | string | — | required, max 2000 chars | Natural language search query |
| `top_k` | integer | `8` | 1–50 | Number of results to return |
| `filters` | object | `{}` | — | Filter criteria (see below) |

**Filter properties**:

| Filter | Type | Description |
|--------|------|-------------|
| `post_type` | `string[]` | Restrict to given post types |
| `post_ids` | `integer[]` | Only include these post IDs |
| `exclude_ids` | `integer[]` | Exclude these post IDs |
| `date_after` | `string` | ISO date (YYYY-MM-DD), only content published after |
| `date_before` | `string` | ISO date (YYYY-MM-DD), only content published before |

**Response** `200 OK`:

```json
{
  "results": [
    {
      "chunk_id": 123,
      "post_id": 456,
      "doc_id": 78,
      "title": "Getting Started",
      "url": "https://example.com/getting-started/",
      "anchor": "kb-a1b2c3d4",
      "heading_path": ["Introduction", "Quick Start"],
      "chunk_text": "To get started with AI Entity Index, first configure your OpenRouter API key in wp-config.php...",
      "score": 0.8923,
      "token_estimate": 387
    },
    {
      "chunk_id": 456,
      "post_id": 789,
      "doc_id": 92,
      "title": "Installation Guide",
      "url": "https://example.com/installation-guide/",
      "anchor": "kb-e5f6g7h8",
      "heading_path": ["Setup", "Prerequisites"],
      "chunk_text": "Before installing, ensure you have WordPress 6.0 or higher and PHP 8.1+...",
      "score": 0.8541,
      "token_estimate": 412
    }
  ],
  "query": "how to get started",
  "top_k": 8,
  "total_scanned": 1500,
  "query_time_ms": 234
}
```

**Error** `400 Bad Request` — KB disabled:

```json
{
  "code": "kb_disabled",
  "message": "Knowledge Base is not enabled.",
  "data": { "status": 400 }
}
```

**Error** `500 Internal Server Error` — search failure:

```json
{
  "code": "kb_search_failed",
  "message": "Search failed: Embedding service unavailable",
  "data": { "status": 500 }
}
```

### GET /kb/status

Get KB pipeline status and document/chunk/vector stats.

**Response** `200 OK`:

```json
{
  "kb_enabled": true,
  "pipeline": {
    "status": "running",
    "running": true,
    "current_phase": "kb_embed_chunks",
    "progress": {
      "total": 500,
      "completed": 320,
      "failed": 5,
      "percentage": 64.0
    }
  },
  "stats": {
    "total_docs": 245,
    "indexed_docs": 198,
    "pending_docs": 40,
    "chunked_docs": 8,
    "excluded_docs": 5,
    "failed_docs": 2,
    "total_chunks": 3847,
    "total_vectors": 3840,
    "failed_chunks": 7
  },
  "last_indexed_at": "2025-04-10 14:22:05",
  "_links": {
    "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/status" }],
    "documents": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/docs" }],
    "search": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/search" }],
    "reindex": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/reindex" }],
    "settings": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/settings" }]
  }
}
```

---

## Document management

### GET /kb/docs

List indexed documents with pagination and filtering.

**Query parameters**:

| Parameter | Type | Default | Values | Description |
|-----------|------|---------|--------|-------------|
| `page` | integer | `1` | min 1 | Page number |
| `per_page` | integer | `20` | 1–100 | Items per page |
| `search` | string | — | — | Search documents by title |
| `status` | string | — | `pending`, `chunked`, `indexed`, `error`, `excluded` | Filter by indexing status |
| `post_type` | string | — | — | Filter by post type |
| `orderby` | string | `last_indexed_at` | `id`, `post_id`, `title`, `status`, `chunk_count`, `last_indexed_at`, `created_at` | Sort field |
| `order` | string | `DESC` | `ASC`, `DESC` | Sort direction |

**Example request**: `GET /wp-json/vibe-ai/v1/kb/docs?status=indexed&per_page=3&orderby=chunk_count&order=DESC`

**Response** `200 OK`:

```json
{
  "documents": [
    {
      "id": 78,
      "post_id": 456,
      "post_type": "post",
      "title": "Getting Started with AI Entity Index",
      "url": "https://example.com/getting-started/",
      "status": "indexed",
      "chunk_count": 12,
      "last_indexed_at": "2025-04-10 12:00:00",
      "created_at": "2025-03-01 09:00:00"
    },
    {
      "id": 92,
      "post_id": 789,
      "post_type": "page",
      "title": "Documentation Hub",
      "url": "https://example.com/docs/",
      "status": "indexed",
      "chunk_count": 8,
      "last_indexed_at": "2025-04-10 12:00:05",
      "created_at": "2025-03-05 14:30:00"
    },
    {
      "id": 105,
      "post_id": 1023,
      "post_type": "post",
      "title": "Advanced Configuration",
      "url": "https://example.com/advanced-config/",
      "status": "indexed",
      "chunk_count": 6,
      "last_indexed_at": "2025-04-09 18:30:00",
      "created_at": "2025-03-12 11:00:00"
    }
  ],
  "total": 198,
  "page": 1,
  "per_page": 3,
  "total_pages": 66
}
```

Response headers:
```
X-WP-Total: 198
X-WP-TotalPages: 66
```

### GET /kb/docs/{post_id}

Get a single document with its chunks.

**Response** `200 OK`:

```json
{
  "id": 78,
  "post_id": 456,
  "post_type": "post",
  "title": "Getting Started with AI Entity Index",
  "url": "https://example.com/getting-started/",
  "status": "indexed",
  "chunk_count": 12,
  "last_indexed_at": "2025-04-10 12:00:00",
  "created_at": "2025-03-01 09:00:00",
  "content_hash": "sha256:abc123def456...",
  "updated_at": "2025-04-10 12:00:00",
  "chunks": [
    {
      "id": 123,
      "chunk_index": 0,
      "anchor": "kb-a1b2c3d4",
      "heading_path": ["Introduction"],
      "chunk_text": "AI Entity Index automatically extracts and links named entities from your WordPress content...",
      "token_estimate": 387,
      "has_vector": true,
      "created_at": "2025-04-10 12:00:01"
    },
    {
      "id": 124,
      "chunk_index": 1,
      "anchor": "kb-e5f6g7h8",
      "heading_path": ["Introduction", "Prerequisites"],
      "chunk_text": "Before you begin, ensure you have WordPress 6.0+, PHP 8.1+, and an OpenRouter API key...",
      "token_estimate": 412,
      "has_vector": true,
      "created_at": "2025-04-10 12:00:01"
    }
  ],
  "_links": {
    "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/docs/456" }],
    "collection": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/docs" }],
    "reindex": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/docs/456/reindex" }],
    "exclude": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/docs/456/exclude" }],
    "include": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/docs/456/include" }],
    "post": [{ "href": "https://example.com/getting-started/" }],
    "edit": [{ "href": "https://example.com/wp-admin/post.php?post=456&action=edit" }]
  }
}
```

**Error** `404 Not Found`:

```json
{
  "code": "kb_document_not_found",
  "message": "Document not found in Knowledge Base.",
  "data": { "status": 404 }
}
```

### POST /kb/docs/{post_id}/reindex

Reindex a single document. Schedules an asynchronous reindex job.

**Request body**: none required.

**Response** `200 OK`:

```json
{
  "success": true,
  "message": "Document reindex has been scheduled.",
  "post_id": 456
}
```

### POST /kb/docs/{post_id}/exclude

Exclude a document from the Knowledge Base. Existing chunks and vectors are retained but the document is marked as `excluded`.

**Response** `200 OK`:

```json
{
  "success": true,
  "message": "Document excluded from Knowledge Base.",
  "document": {
    "id": 78,
    "post_id": 456,
    "post_type": "post",
    "title": "Getting Started with AI Entity Index",
    "url": "https://example.com/getting-started/",
    "status": "excluded",
    "chunk_count": 12,
    "last_indexed_at": "2025-04-10 12:00:00",
    "created_at": "2025-03-01 09:00:00"
  }
}
```

### POST /kb/docs/{post_id}/include

Re-include a previously excluded document. Sets status to `pending` for the next pipeline run.

**Response** `200 OK`:

```json
{
  "success": true,
  "message": "Document included in Knowledge Base. It will be indexed on next pipeline run.",
  "document": {
    "id": 78,
    "post_id": 456,
    "post_type": "post",
    "title": "Getting Started with AI Entity Index",
    "url": "https://example.com/getting-started/",
    "status": "pending",
    "chunk_count": 12,
    "last_indexed_at": "2025-04-10 12:00:00",
    "created_at": "2025-03-01 09:00:00"
  }
}
```

### POST /kb/docs/exclude

Bulk include or exclude documents.

**Request body**:

```json
{
  "post_ids": [456, 789, 1023],
  "exclude": true
}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `post_ids` | `integer[]` | — | required | Post IDs to update |
| `exclude` | boolean | `true` | `true` to exclude, `false` to include |

**Response** `200 OK`:

```json
{
  "success": true,
  "exclude": true,
  "updated_post_ids": [456, 789],
  "failed_post_ids": [1023],
  "updated_count": 2,
  "failed_count": 1
}
```

---

## Pipeline control

### POST /kb/reindex

Trigger a full Knowledge Base reindex.

**Request body**:

```json
{
  "post_types": ["post", "page"],
  "force": true
}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `post_types` | `string[]` | `["post","page","product","attachment"]` | Post types to index |
| `force` | boolean | `false` | Force reindex of all documents, even if unchanged |

**Response** `200 OK`:

```json
{
  "success": true,
  "message": "KB reindex started successfully.",
  "status": "running",
  "phase": "kb_document_build"
}
```

**Error** `409 Conflict`:

```json
{
  "code": "kb_pipeline_running",
  "message": "KB pipeline is already running. Stop it first before starting a new run.",
  "data": { "status": 409 }
}
```

### POST /kb/stop

Stop the KB indexing pipeline.

**Request body**: none required.

**Response** `200 OK`:

```json
{
  "success": true,
  "message": "KB pipeline stopped successfully.",
  "status": "idle"
}
```

---

## Chunks, logs, settings

### GET /kb/chunks/{chunk_id}

Get detailed chunk information including vector metadata.

**Response** `200 OK`:

```json
{
  "id": 123,
  "doc_id": 78,
  "chunk_index": 0,
  "anchor": "kb-a1b2c3d4",
  "heading_path": ["Introduction", "Quick Start"],
  "chunk_text": "To get started with AI Entity Index, first configure your OpenRouter API key in wp-config.php. Then navigate to Settings > AI Entity Index and enable the features you need...",
  "chunk_hash": "sha256:abc123...",
  "start_offset": 0,
  "end_offset": 1842,
  "token_estimate": 387,
  "created_at": "2025-04-10 12:00:01",
  "has_vector": true,
  "vector_info": {
    "provider": "mysql",
    "model": "openai/text-embedding-3-small",
    "dims": 1536
  },
  "document": {
    "id": 78,
    "post_id": 456,
    "title": "Getting Started with AI Entity Index",
    "url": "https://example.com/getting-started/"
  },
  "_links": {
    "document": [{ "href": "https://example.com/wp-json/vibe-ai/v1/kb/docs/456" }]
  }
}
```

**Error** `404 Not Found`:

```json
{
  "code": "kb_chunk_not_found",
  "message": "Chunk not found.",
  "data": { "status": 404 }
}
```

### GET /kb/logs

Get KB-specific log entries, optionally filtered by component.

**Query parameters**:

| Parameter | Type | Default | Values | Description |
|-----------|------|---------|--------|-------------|
| `level` | string | `info` | `debug`, `info`, `warning`, `error` | Minimum log level |
| `limit` | integer | `50` | 1–500 | Maximum entries |
| `component` | string | `all` | `kb`, `embedding`, `chunking`, `all` | Filter by component |

**Example request**: `GET /wp-json/vibe-ai/v1/kb/logs?level=error&component=embedding&limit=5`

**Response** `200 OK`:

```json
{
  "entries": [
    {
      "timestamp": "14:15:33",
      "level": "ERROR",
      "message": "Embedding generation failed for chunk",
      "context": {
        "chunk_id": 456,
        "doc_id": 92,
        "error": "OpenRouter API returned 502"
      }
    },
    {
      "timestamp": "13:45:12",
      "level": "ERROR",
      "message": "Vector upsert failed",
      "context": {
        "chunk_id": 234,
        "error": "MySQL vector index write timeout"
      }
    }
  ],
  "count": 2,
  "level": "error",
  "component": "embedding"
}
```

### GET /kb/settings

Get KB configuration settings.

**Response** `200 OK`:

```json
{
  "kb_enabled": true,
  "embedding_model": "openai/text-embedding-3-small",
  "chunk_size": 450,
  "chunk_overlap": 60,
  "post_types": ["post", "page", "product", "attachment"],
  "auto_index": true
}
```

### POST /kb/settings

Update KB settings. Only provided fields are updated.

**Request body** (any subset):

```json
{
  "kb_enabled": true,
  "chunk_size": 600,
  "chunk_overlap": 75,
  "auto_index": true,
  "post_types": ["post", "page"],
  "embedding_model": "openai/text-embedding-3-small"
}
```

| Parameter | Type | Constraints | Description |
|-----------|------|-------------|-------------|
| `kb_enabled` | boolean | — | Enable/disable the Knowledge Base |
| `embedding_model` | string | — | Embedding model identifier |
| `chunk_size` | integer | 100–2000 | Target chunk size in tokens |
| `chunk_overlap` | integer | 0–500 | Chunk overlap in tokens |
| `post_types` | `string[]` | — | Post types to include in KB |
| `auto_index` | boolean | — | Automatically index new/updated posts |

**Response** `200 OK` (returns updated settings):

```json
{
  "kb_enabled": true,
  "embedding_model": "openai/text-embedding-3-small",
  "chunk_size": 600,
  "chunk_overlap": 75,
  "post_types": ["post", "page"],
  "auto_index": true
}
```

**Error** `400 Bad Request` — no fields provided:

```json
{
  "code": "rest_no_update_data",
  "message": "No valid settings provided for update.",
  "data": { "status": 400 }
}
```

---

## Public AI publishing endpoints

These endpoints are publicly accessible (no authentication required). They are designed for consumption by AI crawlers, agents, and search engines.
The crawler-facing URLs are `/llms.txt`, `/ai-sitemap`, and `/changes`. Equivalent REST mirrors remain available at `/wp-json/vibe-ai/v1/kb/llms-txt`, `/wp-json/vibe-ai/v1/kb/sitemap`, and `/wp-json/vibe-ai/v1/kb/feed`.

### GET /llms.txt **(public)**

Generate an `llms.txt` file for AI crawlers. Returns `text/plain` content.
REST mirror: `GET /wp-json/vibe-ai/v1/kb/llms-txt`

**Query parameters**:

| Parameter | Type | Default | Values | Description |
|-----------|------|---------|--------|-------------|
| `mode` | string | `curated` | `curated`, `full` | Generation mode |
| `include_descriptions` | boolean | `true` | — | Include page descriptions |
| `max_entries` | integer | `100` | 1–1000 | Max entries in full index |

**Example request**: `GET https://example.com/llms.txt?mode=curated&include_descriptions=true`

**Response** `200 OK` (Content-Type: `text/plain`):

```
# example.com

> AI Entity Index - WordPress plugin for semantic entity extraction and knowledge base

## Core Pages

- [Getting Started](https://example.com/getting-started/): Complete guide to setting up AI Entity Index, including API key configuration and first extraction
- [Documentation Hub](https://example.com/docs/): Central documentation covering REST API, pipeline architecture, and extensibility
- [Advanced Configuration](https://example.com/advanced-config/): Fine-tune extraction models, chunk sizes, confidence thresholds, and rate limiting
- [API Reference](https://example.com/api-reference/): Full REST API documentation with request/response examples
- [Changelog](https://example.com/changelog/): Version history with detailed release notes

## Optional

- [Troubleshooting](https://example.com/troubleshooting/): Common issues and solutions for entity extraction and knowledge base indexing
```

Response headers:
```
Content-Type: text/plain; charset=utf-8
ETag: "abc123def456"
X-Robots-Tag: noindex, follow
Cache-Control: public, max-age=3600
```

### GET /ai-sitemap **(public)**

Generate an AI-specific sitemap in JSON or XML format with the `ai:` namespace extension.
Crawler-facing URLs:
- `/ai-sitemap` or `/ai-sitemap.json` for JSON
- `/ai-sitemap.xml` for XML
- REST mirror: `GET /wp-json/vibe-ai/v1/kb/sitemap?format=json|xml`

**Query parameters**:

| Parameter | Type | Default | Values | Description |
|-----------|------|---------|--------|-------------|
| `format` | string | `json` | `json`, `xml` | Output format |

**JSON format** — `GET https://example.com/ai-sitemap`:

**Response** `200 OK`:

```json
{
  "urlset": [
    {
      "loc": "https://example.com/getting-started/",
      "lastmod": "2025-04-10",
      "changefreq": "weekly",
      "ai:chunk_count": 12,
      "ai:has_structured_data": true,
      "ai:entity_count": 8
    },
    {
      "loc": "https://example.com/docs/",
      "lastmod": "2025-04-09",
      "changefreq": "weekly",
      "ai:chunk_count": 8,
      "ai:has_structured_data": true,
      "ai:entity_count": 5
    }
  ]
}
```

**XML format** — `GET https://example.com/ai-sitemap.xml`:

**Response** `200 OK` (Content-Type: `application/xml`):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:ai="https://example.com/ai-sitemap/">
  <url>
    <loc>https://example.com/getting-started/</loc>
    <lastmod>2025-04-10</lastmod>
    <changefreq>weekly</changefreq>
    <ai:chunk_count>12</ai:chunk_count>
    <ai:has_structured_data>true</ai:has_structured_data>
    <ai:entity_count>8</ai:entity_count>
  </url>
  <url>
    <loc>https://example.com/docs/</loc>
    <lastmod>2025-04-09</lastmod>
    <changefreq>weekly</changefreq>
    <ai:chunk_count>8</ai:chunk_count>
    <ai:has_structured_data>true</ai:has_structured_data>
    <ai:entity_count>5</ai:entity_count>
  </url>
</urlset>
```

Response headers:
```
ETag: "abc123def456"
X-Robots-Tag: noindex, follow
Cache-Control: public, max-age=3600
```

### GET /changes **(public)**

Change feed for AI agents to discover new and modified content. Supports conditional GET via `If-None-Match` header.
REST mirror: `GET /wp-json/vibe-ai/v1/kb/feed`

**Query parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `since` | string | — | ISO 8601 datetime to get changes since |
| `limit` | integer | `50` | 1–500 | Maximum changes to return |

**Example request**: `GET https://example.com/changes?since=2025-04-09T00:00:00Z&limit=3`

**Response** `200 OK`:

```json
{
  "feed": [
    {
      "type": "updated",
      "post_id": 456,
      "title": "Getting Started with AI Entity Index",
      "url": "https://example.com/getting-started/",
      "modified_at": "2025-04-10T12:00:00Z",
      "chunks_modified": 3,
      "anchor": "kb-a1b2c3d4"
    },
    {
      "type": "created",
      "post_id": 2048,
      "title": "New Feature: Bulk Entity Operations",
      "url": "https://example.com/new-feature-bulk-operations/",
      "modified_at": "2025-04-10T09:30:00Z",
      "chunks_modified": 6,
      "anchor": "kb-i9j0k1l2"
    }
  ],
  "since": "2025-04-09T00:00:00Z",
  "generated_at": "2025-04-10T14:32:05Z"
}
```

Response headers:
```
ETag: "xyz789"
Cache-Control: public, max-age=300
X-Robots-Tag: noindex, follow
```

**Conditional GET** with `If-None-Match: "xyz789"` returns `304 Not Modified` with empty body when nothing changed.

---

## Pinned pages config

### GET /kb/config/pinned-pages

Get pinned pages for the curated `llms.txt` output.

**Response** `200 OK`:

```json
{
  "pinned_pages": [
    {
      "post_id": 456,
      "title": "Getting Started with AI Entity Index",
      "url": "https://example.com/getting-started/",
      "type": "post"
    },
    {
      "post_id": 789,
      "title": "Documentation Hub",
      "url": "https://example.com/docs/",
      "type": "page"
    }
  ],
  "post_ids": [456, 789]
}
```

### PUT /kb/config/pinned-pages

Set pinned pages in desired order. The route is registered as `WP_REST_Server::EDITABLE`; use `PUT`/`PATCH` for idempotent updates.

**Request body**:

```json
{
  "post_ids": [456, 789, 1023]
}
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `post_ids` | `integer[]` | yes | Post IDs in desired order; all must be published |

**Response** `200 OK` (returns updated configuration):

```json
{
  "pinned_pages": [
    {
      "post_id": 456,
      "title": "Getting Started with AI Entity Index",
      "url": "https://example.com/getting-started/",
      "type": "post"
    },
    {
      "post_id": 789,
      "title": "Documentation Hub",
      "url": "https://example.com/docs/",
      "type": "page"
    },
    {
      "post_id": 1023,
      "title": "Advanced Configuration",
      "url": "https://example.com/advanced-config/",
      "type": "post"
    }
  ],
  "post_ids": [456, 789, 1023]
}
```

**Error** `400 Bad Request` — unpublished post:

```json
{
  "code": "rest_invalid_post",
  "message": "Post ID 2048 does not exist or is not published.",
  "data": { "status": 400 }
}
```

---

## KB pipeline phases

The KB pipeline processes through these phases in order:

| Phase | Description |
|-------|-------------|
| `kb_document_build` | Create/update document records from WordPress posts |
| `kb_chunk_build` | Split documents into overlapping token chunks with anchors |
| `kb_embed_chunks` | Generate embeddings via configured model |
| `kb_index_upsert` | Insert/update vectors in the vector store |
| `kb_cleanup` | Remove orphaned chunks and stale vectors |

---

## Source refs

- Routes: `includes/REST/KBController.php:150`
- Search callback: `includes/REST/KBController.php:804`
- Status callback: `includes/REST/KBController.php:886`
- Document callbacks: `includes/REST/KBController.php:941`, `includes/REST/KBController.php:989`
- Reindex callbacks: `includes/REST/KBController.php:1189`, `includes/REST/KBController.php:1236`
- Chunk callback: `includes/REST/KBController.php:1328`
- Logs callback: `includes/REST/KBController.php:1398`
- Settings callbacks: `includes/REST/KBController.php:1452`, `includes/REST/KBController.php:1473`
- Public AI publishing endpoints: `includes/REST/KBController.php:452`, `includes/REST/KBController.php:483`, `includes/REST/KBController.php:501`
- Pinned pages: `includes/REST/KBController.php:526`
- Settings update params: `includes/REST/KBController.php:754`

## Related docs

- KB pipeline behavior: `docs/architecture/kb-pipeline.md`
- Data schema: `docs/data-model/schema.md`
- Operations and recovery: `docs/operations/recovery-playbooks.md`
