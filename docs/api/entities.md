# Entity API

> Docs home: `docs/index.md`

Base: `/wp-json/vibe-ai/v1`

All endpoints require `manage_options` capability unless noted. All request/response bodies are JSON.

---

## Status and pipeline

### GET /status

Returns pipeline status, progress, and entity stats.

**Response** `200 OK`:

```json
{
  "status": "running",
  "current_phase": "extraction",
  "progress": {
    "total": 250,
    "completed": 180,
    "failed": 3,
    "percentage": 72.0
  },
  "stats": {
    "total_entities": 142,
    "total_mentions": 1847,
    "avg_confidence": 0.82
  },
  "last_activity": "2025-04-10 14:32:05",
  "propagating_entities": [17, 43],
  "_links": {
    "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/status" }],
    "entities": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities" }],
    "pipeline_start": [{ "href": "https://example.com/wp-json/vibe-ai/v1/pipeline/start" }],
    "pipeline_stop": [{ "href": "https://example.com/wp-json/vibe-ai/v1/pipeline/stop" }],
    "logs": [{ "href": "https://example.com/wp-json/vibe-ai/v1/logs" }]
  }
}
```

> Note: `GET /pipeline/status` is an alias for this endpoint (same callback).

### POST /pipeline/start

Start the entity extraction pipeline.

**Request body**:

```json
{
  "post_types": ["post", "page"],
  "force_reprocess": false
}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `post_types` | `string[]` | `["post","page","product","attachment"]` | Post types to process |
| `force_reprocess` | `boolean` | `false` | Reprocess already-extracted posts |

**Response** `200 OK`:

```json
{
  "success": true,
  "message": "Pipeline started successfully.",
  "status": "running",
  "phase": "preparation",
  "_links": {
    "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/status" }],
    "entities": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities" }],
    "pipeline_start": [{ "href": "https://example.com/wp-json/vibe-ai/v1/pipeline/start" }],
    "pipeline_stop": [{ "href": "https://example.com/wp-json/vibe-ai/v1/pipeline/stop" }],
    "logs": [{ "href": "https://example.com/wp-json/vibe-ai/v1/logs" }]
  }
}
```

**Error** `409 Conflict` — pipeline already running:

```json
{
  "code": "rest_pipeline_running",
  "message": "Pipeline is already running. Stop it first before starting a new run.",
  "data": { "status": 409 }
}
```

### POST /pipeline/stop

Stop the extraction pipeline.

**Request body**: none required.

**Response** `200 OK`:

```json
{
  "success": true,
  "message": "Pipeline stopped successfully.",
  "status": "idle",
  "_links": {
    "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/status" }],
    "entities": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities" }],
    "pipeline_start": [{ "href": "https://example.com/wp-json/vibe-ai/v1/pipeline/start" }],
    "pipeline_stop": [{ "href": "https://example.com/wp-json/vibe-ai/v1/pipeline/stop" }],
    "logs": [{ "href": "https://example.com/wp-json/vibe-ai/v1/logs" }]
  }
}
```

---

## Entity resources

### GET /entities

List entities with pagination, filtering, and sorting.

**Query parameters**:

| Parameter | Type | Default | Values | Description |
|-----------|------|---------|--------|-------------|
| `page` | integer | `1` | min 1 | Page number |
| `per_page` | integer | `20` | 1–100 | Items per page |
| `search` | string | — | — | Filter by name substring |
| `type` | string | — | `PERSON`, `ORG`, `COMPANY`, `LOCATION`, `COUNTRY`, `PRODUCT`, `SOFTWARE`, `EVENT`, `WORK`, `CONCEPT` | Filter by entity type |
| `status` | string | — | `raw`, `reviewed`, `canonical`, `trash`, `rejected` | Filter by status |
| `orderby` | string | `created_at` | `id`, `name`, `type`, `status`, `mention_count`, `created_at`, `updated_at` | Sort field |
| `order` | string | `DESC` | `ASC`, `DESC` | Sort direction |

**Example request**: `GET /wp-json/vibe-ai/v1/entities?page=2&per_page=5&type=PERSON&orderby=mention_count&order=DESC`

**Response** `200 OK`:

```json
{
  "entities": [
    {
      "id": 42,
      "name": "Jane Doe",
      "slug": "jane-doe",
      "type": "PERSON",
      "schema_type": "Person",
      "status": "canonical",
      "mention_count": 27,
      "created_at": "2025-03-15 10:22:01",
      "updated_at": "2025-04-02 08:14:33",
      "_links": {
        "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42" }],
        "collection": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities" }],
        "aliases": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42/aliases" }]
      }
    },
    {
      "id": 17,
      "name": "John Smith",
      "slug": "john-smith",
      "type": "PERSON",
      "schema_type": "Person",
      "status": "reviewed",
      "mention_count": 19,
      "created_at": "2025-03-10 09:00:00",
      "updated_at": "2025-03-28 16:45:12",
      "_links": {
        "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/17" }],
        "collection": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities" }],
        "aliases": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/17/aliases" }]
      }
    }
  ],
  "total": 23,
  "pages": 5
}
```

Response headers:
```
X-WP-Total: 23
X-WP-TotalPages: 5
```

### GET /entities/{id}

Get a single entity with its aliases and mentions.

**Response** `200 OK`:

```json
{
  "id": 42,
  "name": "Jane Doe",
  "slug": "jane-doe",
  "type": "PERSON",
  "schema_type": "Person",
  "status": "canonical",
  "mention_count": 27,
  "created_at": "2025-03-15 10:22:01",
  "updated_at": "2025-04-02 08:14:33",
  "description": "Senior software engineer and open-source contributor.",
  "same_as_url": "https://en.wikipedia.org/wiki/Jane_Doe",
  "wikidata_id": "Q12345",
  "aliases": [
    {
      "id": 101,
      "alias": "J. Doe",
      "alias_slug": "j-doe",
      "source": "ai",
      "created_at": "2025-03-15 10:22:01"
    },
    {
      "id": 102,
      "alias": "Jane",
      "alias_slug": "jane",
      "source": "manual",
      "created_at": "2025-03-20 14:05:00"
    }
  ],
  "mentions": [
    {
      "post_id": 123,
      "post_title": "Meet Our Team",
      "post_status": "publish",
      "confidence": 0.95,
      "context_snippet": "Senior engineer Jane Doe leads the infrastructure team...",
      "is_primary": true,
      "_links": {
        "post": [{ "href": "https://example.com/meet-our-team/" }],
        "edit": [{ "href": "https://example.com/wp-admin/post.php?post=123&action=edit" }]
      }
    },
    {
      "post_id": 456,
      "post_title": "Q1 Engineering Update",
      "post_status": "publish",
      "confidence": 0.88,
      "context_snippet": "...as noted by Jane Doe in the retrospective...",
      "is_primary": false,
      "_links": {
        "post": [{ "href": "https://example.com/q1-engineering-update/" }],
        "edit": [{ "href": "https://example.com/wp-admin/post.php?post=456&action=edit" }]
      }
    }
  ],
  "_links": {
    "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42" }],
    "collection": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities" }],
    "aliases": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42/aliases" }]
  }
}
```

**Error** `404 Not Found`:

```json
{
  "code": "rest_entity_not_found",
  "message": "Entity not found.",
  "data": { "status": 404 }
}
```

### PUT /entities/{id}

Update an entity. The controller route is `WP_REST_Server::EDITABLE`, so `POST`, `PUT`, and `PATCH` are all accepted by WordPress REST method mapping.

**Updatable fields**: `name`, `type`, `schema_type`, `status`, `description`, `same_as_url`, `wikidata_id`

**Request body**:

```json
{
  "name": "Jane Doe-Smith",
  "description": "VP of Engineering and open-source maintainer.",
  "same_as_url": "https://en.wikipedia.org/wiki/Jane_Doe-Smith",
  "wikidata_id": "Q67890"
}
```

**Response** `200 OK`:

```json
{
  "id": 42,
  "name": "Jane Doe-Smith",
  "slug": "jane-doe",
  "type": "PERSON",
  "schema_type": "Person",
  "status": "canonical",
  "mention_count": 27,
  "created_at": "2025-03-15 10:22:01",
  "updated_at": "2025-04-10 14:32:05",
  "_links": {
    "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42" }],
    "collection": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities" }],
    "aliases": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42/aliases" }]
  }
}
```

> When schema-affecting fields (`name`, `schema_type`, `same_as_url`, `wikidata_id`) change, the `vibe_ai_entity_updated` action fires to trigger schema propagation.

**Error** `400 Bad Request` — no fields provided:

```json
{
  "code": "rest_no_update_data",
  "message": "No valid fields provided for update.",
  "data": { "status": 400 }
}
```

**Error** `400 Bad Request` — invalid wikidata_id:

```json
{
  "code": "rest_invalid_wikidata_id",
  "message": "Wikidata ID must be in format Q followed by digits (e.g., Q12345).",
  "data": { "status": 400 }
}
```

### DELETE /entities/{id}

Delete an entity. By default performs a soft-delete (status → `trash`). Use `force=true` for permanent deletion.

**Query parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `force` | boolean | `false` | Permanently delete (bypass trash) |

**Soft delete** — `DELETE /wp-json/vibe-ai/v1/entities/42`:

```json
{
  "id": 42,
  "name": "Jane Doe",
  "slug": "jane-doe",
  "type": "PERSON",
  "schema_type": "Person",
  "status": "trash",
  "mention_count": 27,
  "created_at": "2025-03-15 10:22:01",
  "updated_at": "2025-04-10 14:35:00",
  "_links": {
    "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42" }],
    "collection": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities" }],
    "aliases": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42/aliases" }]
  }
}
```

**Hard delete** — `DELETE /wp-json/vibe-ai/v1/entities/42?force=true`:

```json
{
  "deleted": true,
  "previous": {
    "id": 42,
    "name": "Jane Doe",
    "slug": "jane-doe",
    "type": "PERSON",
    "schema_type": "Person",
    "status": "canonical",
    "mention_count": 27,
    "created_at": "2025-03-15 10:22:01",
    "updated_at": "2025-04-10 14:35:00"
  }
}
```

---

## Merge and alias operations

### POST /entities/merge

Merge multiple source entities into a single target entity. Source entities' mentions and aliases are transferred to the target.

**Request body**:

```json
{
  "target_id": 42,
  "source_ids": [17, 23]
}
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `target_id` | integer | yes | Canonical entity ID to merge into |
| `source_ids` | `integer[]` | yes | Entity IDs to merge from (must not include `target_id`) |

**Response** `200 OK`:

```json
{
  "success": true,
  "entity": {
    "id": 42,
    "name": "Jane Doe",
    "slug": "jane-doe",
    "type": "PERSON",
    "schema_type": "Person",
    "status": "canonical",
    "mention_count": 54,
    "created_at": "2025-03-15 10:22:01",
    "updated_at": "2025-04-10 14:40:00",
    "_links": {
      "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42" }],
      "collection": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities" }],
      "aliases": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities/42/aliases" }]
    }
  },
  "merged_count": 2,
  "affected_posts": 8
}
```

> The `vibe_ai_entity_merged` action fires with the target ID and affected post IDs for schema propagation.

**Error** `400 Bad Request` — source contains target:

```json
{
  "code": "rest_invalid_merge",
  "message": "No valid source entities to merge.",
  "data": { "status": 400 }
}
```

### POST /entities/{id}/aliases

Add an alias to an entity. Maximum 20 aliases per entity (`Config::MAX_ALIASES_PER_ENTITY`).

**Request body**:

```json
{
  "alias": "Dr. Jane Doe"
}
```

**Response** `201 Created`:

```json
{
  "success": true,
  "alias": {
    "id": 103,
    "alias": "Dr. Jane Doe",
    "alias_slug": "dr-jane-doe",
    "source": "manual",
    "created_at": "2025-04-10 14:45:00"
  }
}
```

**Error** `409 Conflict` — duplicate alias:

```json
{
  "code": "rest_alias_exists",
  "message": "This alias already exists for this entity.",
  "data": { "status": 409 }
}
```

**Error** `400 Bad Request` — alias limit reached:

```json
{
  "code": "rest_alias_limit_reached",
  "message": "Maximum number of aliases (20) reached for this entity.",
  "data": { "status": 400 }
}
```

### DELETE /entities/{id}/aliases/{alias_id}

Remove an alias from an entity.

**Response** `200 OK`:

```json
{
  "deleted": true
}
```

**Error** `404 Not Found` — alias not associated with entity:

```json
{
  "code": "rest_alias_not_found",
  "message": "Alias not found for this entity.",
  "data": { "status": 404 }
}
```

---

## Logs and settings

### GET /logs

Retrieve recent log entries. Logs are stored in `wp-content/uploads/vibe-ai-logs/` as daily files (`YYYY-MM-DD.log`).

**Query parameters**:

| Parameter | Type | Default | Values | Description |
|-----------|------|---------|--------|-------------|
| `level` | string | `info` | `debug`, `info`, `warning`, `error` | Minimum log level |
| `limit` | integer | `50` | 1–500 | Maximum entries |
| `date` | string | today | `YYYY-MM-DD` | Specific log date |

**Example request**: `GET /wp-json/vibe-ai/v1/logs?level=warning&limit=10&date=2025-04-09`

**Response** `200 OK`:

```json
{
  "entries": [
    {
      "timestamp": "14:22:05",
      "level": "WARNING",
      "message": "Entity extraction confidence below threshold",
      "context": {
        "post_id": 789,
        "entity_name": "Ambiguous Reference",
        "confidence": 0.35,
        "threshold": 0.4
      }
    },
    {
      "timestamp": "13:10:44",
      "level": "ERROR",
      "message": "API request failed",
      "context": {
        "status_code": 502,
        "model": "anthropic/claude-opus-4.5"
      }
    }
  ],
  "count": 2,
  "level": "warning",
  "date": "2025-04-09",
  "log_files": [
    { "date": "2025-04-10", "size": 245678 },
    { "date": "2025-04-09", "size": 189012 },
    { "date": "2025-04-08", "size": 156789 }
  ]
}
```

### GET /settings

Retrieve plugin settings (read-only view of configuration).

**Response** `200 OK`:

```json
{
  "settings": {
    "api_key_configured": true,
    "ai_model": "anthropic/claude-opus-4.5",
    "default_post_types": ["post", "page", "product", "attachment"],
    "post_types": ["post", "page", "product", "attachment"],
    "available_post_types": [
      { "name": "post", "label": "Post", "description": "" },
      { "name": "page", "label": "Page", "description": "" }
    ],
    "supported_post_types": ["post", "page", "product", "attachment"],
    "extraction_model": "anthropic/claude-opus-4.5",
    "embedding_model": "openai/text-embedding-3-small",
    "chunk_size": 450,
    "chunk_overlap": 60,
    "batch_size": 50,
    "confidence_threshold": 0.6,
    "logging_enabled": true,
    "log_level": "info",
    "polling_interval": 2000,
    "max_retries": 3,
    "version": "1.0.8"
  }
}
```

### PUT /settings

Update plugin settings. Currently informational — most settings are constant-driven via `Config` and `wp-config.php`. Returns a confirmation message.

**Request body** (any subset of settings):

```json
{
  "api_key_configured": true
}
```

**Response** `200 OK`:

```json
{
  "success": true,
  "message": "Settings are primarily configured via wp-config.php constants."
}
```

---

## Operational notes

- Start endpoint returns `409` when a run is already in progress.
- Entity update triggers `vibe_ai_entity_updated` for schema-affecting field changes (`name`, `schema_type`, `same_as_url`, `wikidata_id`).
- Merge endpoint triggers `vibe_ai_entity_merged` action with target ID and affected post IDs.
- Pipeline phases: `preparation` → `extraction` → `deduplication` → `linking` → `indexing` → `schema_build`.
- Entity types: `PERSON`, `ORG`, `COMPANY`, `LOCATION`, `COUNTRY`, `PRODUCT`, `SOFTWARE`, `EVENT`, `WORK`, `CONCEPT`.
- Entity statuses: `raw`, `reviewed`, `canonical`, `trash`, `rejected`.

## Source refs

- Routes: `includes/REST/RestController.php:85`
- Collection params: `includes/REST/RestController.php:511`
- Entity update params: `includes/REST/RestController.php:568`
- Pipeline callbacks: `includes/REST/RestController.php:1168`
- Entity update callback: `includes/REST/RestController.php:779`
- Merge callback: `includes/REST/RestController.php:945`
- Alias callbacks: `includes/REST/RestController.php:1028`, `includes/REST/RestController.php:1109`
- Logs callback: `includes/REST/RestController.php:1255`
- Settings callbacks: `includes/REST/RestController.php:1293`, `includes/REST/RestController.php:1318`

## Related docs

- Entity pipeline behavior: `docs/architecture/entity-pipeline.md`
- Hook extension points: `docs/extensibility/hooks-and-filters.md`
- Option/meta keys: `docs/data-model/options-and-meta.md`
