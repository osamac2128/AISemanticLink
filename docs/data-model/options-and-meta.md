# Options and Meta Keys

> Docs home: `docs/index.md`

Source: `includes/Config.php`, `includes/Activator.php`, `includes/PipelineManager.php`, `includes/KBPipelineManager.php`

This document is a comprehensive reference for every WordPress option and post meta key used by the AI Entity Index plugin. Each entry includes its data type, default value, valid values or range, and which components read or write it.

---

## Post Meta Keys

Post meta keys are prefixed with `_vibe_ai_` (underscore prefix hides them from the default Custom Fields UI). They are attached to individual `wp_posts` rows and store per-post pipeline state.

### Entity / Schema Meta

| Meta Key | Type | Default | Description |
|----------|------|---------|-------------|
| `_vibe_ai_schema_cache` | `string` (JSON) | — | Pre-computed JSON-LD string for the post's entity schema. Built by `SchemaBuildJob` and injected into the page `<head>` by `SchemaInjector`. Invalidated when `SCHEMA_CACHE_VERSION` changes or entity data is modified. |
| `_vibe_ai_extracted_at` | `string` (datetime) | — | Timestamp of the last successful entity extraction run on this post. Written by `ExtractionJob`. Used to determine whether re-extraction is needed. |
| `_vibe_ai_schema_version` | `int` | `1` | Cache version number for schema invalidation. Compared against `Config::SCHEMA_CACHE_VERSION`. When they differ, `SchemaBuildJob` regenerates the cache. Written by `SchemaBuildJob`, read by `SchemaInjector`. |
| `_vibe_ai_needs_extraction` | `bool` | `false` | Flag indicating the post has been modified and needs entity re-extraction on the next pipeline run. Set to `true` by `Plugin::onPostSave()` whenever a supported post type is saved. Cleared by `ExtractionJob` after successful processing. |

### Knowledge Base Meta

| Meta Key | Type | Default | Description |
|----------|------|---------|-------------|
| `_vibe_ai_kb_enabled` | `bool` | `false` | Per-post override for KB inclusion. When `true`, this post is included in the KB indexing pipeline regardless of the global `vibe_ai_kb_post_types` setting. When `false`, the global settings apply. Written by admin UI, read by `KBPipelineManager`. |
| `_vibe_ai_kb_indexed_at` | `string` (datetime) | — | Timestamp of the last successful KB indexing operation on this post. Written by `KBPipelineManager` after chunking and vectorization complete. Used to determine whether re-indexing is needed. |
| `_vibe_ai_kb_version` | `int` | — | KB index version counter for this post. Compared against the current KB schema version to detect when re-indexing is required. Written by `KBPipelineManager`. |
| `_vibe_ai_kb_excluded` | `bool` or `string` | `false` | When truthy, explicitly excludes this post from the Knowledge Base entirely. Takes precedence over all other inclusion logic. Can be set to `true` or a string reason (e.g. `"manual-exclude"`). Written by admin UI or programmatic filter, read by `KBPipelineManager`. |

### Component Access Summary

| Meta Key | Written By | Read By |
|----------|-----------|---------|
| `_vibe_ai_schema_cache` | `SchemaBuildJob` | `SchemaInjector` |
| `_vibe_ai_extracted_at` | `ExtractionJob` | `PipelineManager`, `ExtractionJob` |
| `_vibe_ai_schema_version` | `SchemaBuildJob` | `SchemaInjector`, `SchemaBuildJob` |
| `_vibe_ai_needs_extraction` | `Plugin::onPostSave()`, `ExtractionJob` | `PipelineManager`, `ExtractionJob` |
| `_vibe_ai_kb_enabled` | Admin UI / REST API | `KBPipelineManager` |
| `_vibe_ai_kb_indexed_at` | `KBPipelineManager` | `KBPipelineManager` |
| `_vibe_ai_kb_version` | `KBPipelineManager` | `KBPipelineManager` |
| `_vibe_ai_kb_excluded` | Admin UI / REST API | `KBPipelineManager` |

---

## Entity Pipeline Options

These options track the state of the entity extraction pipeline, managed by `PipelineManager`. They are stored in the `wp_options` table.

| Option Key | Type | Default | Valid Values | Description |
|-----------|------|---------|-------------|-------------|
| `vibe_ai_pipeline_status` | `string` | `'idle'` | `idle`, `running`, `failed`, `completed` | Current status of the entity extraction pipeline. Set to `running` when pipeline starts, `completed` on success, `failed` on error, `idle` when no pipeline is active. |
| `vibe_ai_pipeline_phase` | `string` | `''` | Phase name string | Human-readable name of the current pipeline phase (e.g. `"extracting"`, `"building_schema"`, `"propagating"`). |
| `vibe_ai_pipeline_progress` | `array` | `[]` | Associative array | Progress tracking with keys: `current_item` (string, currently processing item identifier), `total_items` (int, total items to process), `processed_items` (int, items completed so far). |
| `vibe_ai_pipeline_config` | `array` | `[]` | Associative array | Configuration snapshot for the current pipeline run. Captures settings like batch size, model, confidence threshold at the time the pipeline was started. |
| `vibe_ai_pipeline_last_activity` | `string` (datetime) | `''` | Datetime string | Timestamp of the last pipeline activity (item processed, phase change, etc.). Used to detect stale/hung pipelines. |
| `vibe_ai_propagating_ids` | `array` | `[]` | Array of int | Entity IDs currently being propagated to their linked posts (schema update pass). Prevents concurrent propagation of the same entity. Cleared when propagation completes. |

### Component Access

| Option Key | Written By | Read By |
|-----------|-----------|---------|
| `vibe_ai_pipeline_status` | `PipelineManager` | `PipelineManager`, Admin UI, REST API |
| `vibe_ai_pipeline_phase` | `PipelineManager` | `PipelineManager`, Admin UI |
| `vibe_ai_pipeline_progress` | `PipelineManager` | `PipelineManager`, Admin UI, REST API |
| `vibe_ai_pipeline_config` | `PipelineManager` | `PipelineManager` |
| `vibe_ai_pipeline_last_activity` | `PipelineManager` | `PipelineManager`, stale pipeline detection |
| `vibe_ai_propagating_ids` | `PipelineManager` | `PipelineManager` |

---

## KB Pipeline Options

These options track the state of the Knowledge Base indexing pipeline, managed by `KBPipelineManager`.

| Option Key | Type | Default | Valid Values | Description |
|-----------|------|---------|-------------|-------------|
| `vibe_ai_kb_pipeline_status` | `string` | `'idle'` | `idle`, `running`, `failed`, `completed` | Current status of the KB indexing pipeline. |
| `vibe_ai_kb_pipeline_phase` | `string` | `''` | Phase name string | Human-readable name of the current KB pipeline phase (e.g. `"chunking"`, `"embedding"`, `"indexing"`). |
| `vibe_ai_kb_pipeline_progress` | `array` | `[]` | Associative array | Progress tracking with keys: `current_item`, `total_items`, `processed_items`. |
| `vibe_ai_kb_pipeline_started_at` | `string` (datetime) | `''` | Datetime string | Timestamp when the current KB pipeline run was started. Used to calculate total run duration. |
| `vibe_ai_kb_pipeline_config` | `array` | `[]` | Associative array | Configuration snapshot for the current KB pipeline run (chunk size, model, post types, etc.). |
| `vibe_ai_kb_pipeline_last_activity` | `string` (datetime) | `''` | Datetime string | Timestamp of the last KB pipeline activity. Used for stale pipeline detection and timeout logic. |
| `vibe_ai_kb_pipeline_stop_requested` | `bool` | `false` | `true` / `false` | Set to `true` by admin UI or REST API to request a graceful pipeline stop. `KBPipelineManager` checks this flag between phases/items and exits cleanly when `true`. |

### Component Access

| Option Key | Written By | Read By |
|-----------|-----------|---------|
| `vibe_ai_kb_pipeline_status` | `KBPipelineManager` | `KBPipelineManager`, Admin UI, REST API |
| `vibe_ai_kb_pipeline_phase` | `KBPipelineManager` | `KBPipelineManager`, Admin UI |
| `vibe_ai_kb_pipeline_progress` | `KBPipelineManager` | `KBPipelineManager`, Admin UI, REST API |
| `vibe_ai_kb_pipeline_started_at` | `KBPipelineManager` | `KBPipelineManager`, Admin UI |
| `vibe_ai_kb_pipeline_config` | `KBPipelineManager` | `KBPipelineManager` |
| `vibe_ai_kb_pipeline_last_activity` | `KBPipelineManager` | `KBPipelineManager`, stale detection |
| `vibe_ai_kb_pipeline_stop_requested` | Admin UI, REST API | `KBPipelineManager` |

---

## Runtime Settings Options

These options configure the plugin's runtime behavior. They are set via the admin settings page or REST API and read by various pipeline components.

### General Settings

| Option Key | Type | Default | Valid Values / Range | Description |
|-----------|------|---------|---------------------|-------------|
| `vibe_ai_model` | `string` | `'anthropic/claude-opus-4.5'` | Any valid OpenRouter model string | AI model used for entity extraction. Passed to `AIClient` for API calls. |
| `vibe_ai_batch_size` | `int` | `50` | `5` – `50` | Number of posts processed per pipeline batch. Higher values use more memory and API quota but complete faster. |
| `vibe_ai_confidence_threshold` | `float` | `0.60` | `0.40` – `0.95` | Minimum confidence score for storing entity mentions. Mentions below this threshold are discarded. See [Confidence Thresholds](schema.md#confidence-thresholds). |
| `vibe_ai_post_types` | `array` | `['post','page','product','attachment']` | Any registered WordPress post types | Post types eligible for entity extraction. Posts of other types are skipped by the pipeline. |
| `vibe_ai_logging_enabled` | `bool` | `true` | `true` / `false` | Master switch for plugin logging. When `false`, no log entries are written regardless of log level. |
| `vibe_ai_log_level` | `string` | `'info'` | `debug`, `info`, `warning`, `error` | Minimum severity level for log entries. `debug` is most verbose; `error` is least. |

### Knowledge Base Settings

| Option Key | Type | Default | Valid Values / Range | Description |
|-----------|------|---------|---------------------|-------------|
| `vibe_ai_kb_enabled` | `bool` | `false` | `true` / `false` | Master switch for the Knowledge Base feature. When `false`, KB pipeline is completely disabled and KB admin pages are hidden. |
| `vibe_ai_kb_embedding_model` | `string` | `'openai/text-embedding-3-small'` | Any valid OpenRouter embedding model | Model used to generate vector embeddings for KB chunks. Determines vector dimensionality. |
| `vibe_ai_kb_chunk_size` | `int` | `450` | `50` – `800` | Target number of tokens per content chunk. Larger chunks capture more context but cost more to embed. |
| `vibe_ai_kb_chunk_overlap` | `int` | `60` | `0` – `150` | Number of overlapping tokens between consecutive chunks. Prevents information loss at chunk boundaries. |
| `vibe_ai_kb_post_types` | `array` | `['post','page','product','attachment']` | Any registered WordPress post types | Post types eligible for KB indexing. Independent from `vibe_ai_post_types` (entity extraction). |
| `vibe_ai_kb_auto_index` | `bool` | `false` | `true` / `false` | When `true`, newly saved or updated posts are automatically queued for KB indexing on the next scheduler run. |

### Component Access

| Option Key | Written By | Read By |
|-----------|-----------|---------|
| `vibe_ai_model` | Admin Settings | `AIClient`, `ExtractionJob` |
| `vibe_ai_batch_size` | Admin Settings | `PipelineManager` |
| `vibe_ai_confidence_threshold` | Admin Settings | `ExtractionJob`, `PipelineManager` |
| `vibe_ai_post_types` | Admin Settings | `PipelineManager`, `Plugin::onPostSave()` |
| `vibe_ai_logging_enabled` | Admin Settings | `Logger` |
| `vibe_ai_log_level` | Admin Settings | `Logger` |
| `vibe_ai_kb_enabled` | Admin Settings | `KBPipelineManager`, Admin UI |
| `vibe_ai_kb_embedding_model` | Admin Settings | `KBPipelineManager`, `AIClient` |
| `vibe_ai_kb_chunk_size` | Admin Settings | `KBPipelineManager`, `DocumentChunker` |
| `vibe_ai_kb_chunk_overlap` | Admin Settings | `DocumentChunker` |
| `vibe_ai_kb_post_types` | Admin Settings | `KBPipelineManager` |
| `vibe_ai_kb_auto_index` | Admin Settings | `Plugin::onPostSave()`, `KBPipelineManager` |

---

## Schema / System Options

| Option Key | Type | Default | Valid Values | Description |
|-----------|------|---------|-------------|-------------|
| `vibe_ai_db_version` | `string` | `'1.1.0'` | Semantic version string | Tracks the current database schema version. Compared against `Config::DB_VERSION` on every admin load by `Activator::maybeUpgrade()`. Updated after successful schema migrations. |
| `vibe_ai_openrouter_circuit` | `array` | `[]` | Associative array | Circuit breaker state for the OpenRouter API client managed by `AIClient`. Tracks consecutive failures, last failure timestamp, and open/closed state to prevent hammering a failing API. |

### Component Access

| Option Key | Written By | Read By |
|-----------|-----------|---------|
| `vibe_ai_db_version` | `Activator::maybeUpgrade()` | `Activator::maybeUpgrade()` |
| `vibe_ai_openrouter_circuit` | `AIClient` | `AIClient` |

---

## Transients

Transients are WordPress's time-expiring options, typically used for temporary state. The plugin uses them for propagation locking.

### Pattern: `_transient_vibe_ai_propagating_%`

| Pattern | Type | Expiry | Purpose |
|---------|------|--------|---------|
| `_transient_vibe_ai_propagating_{entity_id}` | `bool` (`true`) | Short-lived (seconds) | Marks a specific entity as currently being propagated (schema updates being written to its linked posts). Prevents concurrent propagation of the same entity from overlapping pipeline runs. |

**Lifecycle:**
1. `PipelineManager` sets the transient with the entity's ID before beginning propagation.
2. The entity's schema cache is rebuilt across all linked posts.
3. The transient is deleted after propagation completes (success or failure).
4. If a transient exists for an entity, `PipelineManager` skips it and retries on the next run.

**Related option:** `vibe_ai_propagating_ids` is the companion option that stores the full list of entity IDs currently mid-propagation (used for dashboard display and debugging).

---

## Complete Option Key Index

| Option Key | Category | Persistence |
|-----------|----------|-------------|
| `vibe_ai_pipeline_status` | Entity Pipeline | Persistent (`wp_options`) |
| `vibe_ai_pipeline_phase` | Entity Pipeline | Persistent |
| `vibe_ai_pipeline_progress` | Entity Pipeline | Persistent |
| `vibe_ai_pipeline_config` | Entity Pipeline | Persistent |
| `vibe_ai_pipeline_last_activity` | Entity Pipeline | Persistent |
| `vibe_ai_propagating_ids` | Entity Pipeline | Persistent |
| `vibe_ai_kb_pipeline_status` | KB Pipeline | Persistent |
| `vibe_ai_kb_pipeline_phase` | KB Pipeline | Persistent |
| `vibe_ai_kb_pipeline_progress` | KB Pipeline | Persistent |
| `vibe_ai_kb_pipeline_started_at` | KB Pipeline | Persistent |
| `vibe_ai_kb_pipeline_config` | KB Pipeline | Persistent |
| `vibe_ai_kb_pipeline_last_activity` | KB Pipeline | Persistent |
| `vibe_ai_kb_pipeline_stop_requested` | KB Pipeline | Persistent |
| `vibe_ai_model` | Settings | Persistent |
| `vibe_ai_batch_size` | Settings | Persistent |
| `vibe_ai_confidence_threshold` | Settings | Persistent |
| `vibe_ai_post_types` | Settings | Persistent |
| `vibe_ai_logging_enabled` | Settings | Persistent |
| `vibe_ai_log_level` | Settings | Persistent |
| `vibe_ai_kb_enabled` | Settings | Persistent |
| `vibe_ai_kb_embedding_model` | Settings | Persistent |
| `vibe_ai_kb_chunk_size` | Settings | Persistent |
| `vibe_ai_kb_chunk_overlap` | Settings | Persistent |
| `vibe_ai_kb_post_types` | Settings | Persistent |
| `vibe_ai_kb_auto_index` | Settings | Persistent |
| `vibe_ai_db_version` | System | Persistent |
| `vibe_ai_openrouter_circuit` | System | Persistent |
| `_transient_vibe_ai_propagating_*` | Transient | Expiring |

---

## Source Refs

| File | Relevant Symbols |
|------|-----------------|
| `includes/Config.php` | `SCHEMA_CACHE_VERSION`, `DB_VERSION`, all option key constants |
| `includes/Activator.php` | Default option values set during `activate()`, `maybeUpgrade()` |
| `includes/PipelineManager.php` | Entity pipeline option reads/writes, propagation state |
| `includes/KBPipelineManager.php` | KB pipeline option reads/writes, stop request handling |
| `includes/Plugin.php` | `onPostSave()` sets `_vibe_ai_needs_extraction` |
| `includes/SchemaBuildJob.php` | Writes `_vibe_ai_schema_cache`, `_vibe_ai_schema_version` |
| `includes/SchemaInjector.php` | Reads `_vibe_ai_schema_cache`, `_vibe_ai_schema_version` |
| `includes/ExtractionJob.php` | Writes `_vibe_ai_extracted_at`, clears `_vibe_ai_needs_extraction` |
| `includes/AIClient.php` | Reads/writes `vibe_ai_openrouter_circuit` |
