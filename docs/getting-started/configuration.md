# Configuration

> Docs home: `docs/index.md`

Complete reference for configuring AI Entity Index. The plugin uses a three-tier configuration model: PHP constants (hard defaults), `wp_options` (operator overrides), and WordPress filters (request-time overrides).

## Required Constants

Add to `wp-config.php` before the `/* That's all, stop editing! */` line:

### `VIBE_AI_OPENROUTER_KEY` (required)

The OpenRouter API key used for all AI model calls (entity extraction and KB embeddings).

```php
define('VIBE_AI_OPENROUTER_KEY', 'sk-or-v1-...');
```

Without this constant, the plugin will register but all AI-powered operations will fail with an authentication error.

### `VIBE_AI_ENCRYPTION_KEY` (optional)

Encryption key for sensitive data at rest. If not defined, the plugin auto-generates one and stores it.

```php
define('VIBE_AI_ENCRYPTION_KEY', 'your-random-32-byte-string-here');
```

---

## Per-Option Configuration Reference

All options below are stored in the `wp_options` table and can be set via the admin UI, the REST API (`GET/POST /wp-json/vibe-ai/v1/settings`), or directly via `update_option()`.

### Entity Pipeline Options

#### `vibe_ai_model`

| Property | Value |
|----------|-------|
| **Type** | `string` |
| **Default** | `anthropic/claude-opus-4.5` |
| **Valid values** | `anthropic/claude-opus-4.5`, `anthropic/claude-sonnet-4`, `anthropic/claude-3.5-sonnet` |
| **Affects** | AI model used for entity extraction via OpenRouter |
| **Set via** | Admin UI, REST API, `wp_options` |

Controls which LLM performs named entity recognition. More capable models produce higher-quality extractions at higher cost.

```php
update_option('vibe_ai_model', 'anthropic/claude-sonnet-4');
```

---

#### `vibe_ai_batch_size`

| Property | Value |
|----------|-------|
| **Type** | `int` |
| **Default** | `50` |
| **Valid range** | `5` – `50` |
| **Affects** | Number of posts processed per batch in the extraction phase |
| **Set via** | Admin UI, REST API, `wp_options` (managed dynamically by `BatchSizeManager`) |

The `BatchSizeManager` adjusts this value automatically based on processing performance. Manual override is supported but will be adjusted after the next batch completes. See [Config Constants](#config-constants-in-configphp) for `MIN_BATCH_SIZE`, `MAX_BATCH_SIZE`, and `TARGET_PROCESS_TIME`.

```php
update_option('vibe_ai_batch_size', 25);
```

---

#### `vibe_ai_confidence_threshold`

| Property | Value |
|----------|-------|
| **Type** | `float` |
| **Default** | `0.60` |
| **Valid range** | `0.40` – `0.95` |
| **Affects** | Minimum confidence for entities to be included in Schema.org JSON-LD output |
| **Set via** | Admin UI, REST API, `wp_options`, `vibe_ai_confidence_threshold` filter |

Entities below this threshold are still stored in the database but excluded from schema output. See `Config::CONFIDENCE_HIGH` (0.85), `CONFIDENCE_MEDIUM` (0.60), and `CONFIDENCE_LOW` (0.40) for the tier definitions.

```php
update_option('vibe_ai_confidence_threshold', 0.75);
```

---

#### `vibe_ai_post_types`

| Property | Value |
|----------|-------|
| **Type** | `array` (serialized) |
| **Default** | `['post', 'page', 'product', 'attachment']` |
| **Valid values** | Any registered public post type |
| **Affects** | Which post types the entity extraction pipeline processes |
| **Set via** | Admin UI, REST API, `wp_options`, `vibe_ai_post_types` filter |

```php
update_option('vibe_ai_post_types', ['post', 'page', 'my_cpt']);
```

---

### Logging Options

#### `vibe_ai_logging_enabled`

| Property | Value |
|----------|-------|
| **Type** | `bool` |
| **Default** | `true` |
| **Valid values** | `true`, `false` |
| **Affects** | Master switch for file-based logging |
| **Set via** | Admin UI, REST API, `wp_options` |

When disabled, no log files are written. Errors are still reported via WordPress standard error handling.

```php
update_option('vibe_ai_logging_enabled', false);
```

---

#### `vibe_ai_log_level`

| Property | Value |
|----------|-------|
| **Type** | `string` |
| **Default** | `info` |
| **Valid values** | `debug`, `info`, `warning`, `error` |
| **Affects** | Minimum log level written to log files |
| **Set via** | Admin UI, REST API, `wp_options` |

Log levels are hierarchical: `error` includes only errors, `warning` includes warnings and errors, `info` adds informational messages, `debug` logs everything.

```php
update_option('vibe_ai_log_level', 'debug');
```

---

### Knowledge Base Options

#### `vibe_ai_kb_enabled`

| Property | Value |
|----------|-------|
| **Type** | `bool` |
| **Default** | `false` |
| **Valid values** | `true`, `false` |
| **Affects** | Master switch for the entire KB feature. When `false`, no KB endpoints, pipelines, or auto-indexing run. |
| **Set via** | Admin UI, REST API, `wp_options` |

Must be set to `true` before any KB operations are available. KB tables are created during activation regardless of this setting.

```php
update_option('vibe_ai_kb_enabled', true);
```

---

#### `vibe_ai_kb_embedding_model`

| Property | Value |
|----------|-------|
| **Type** | `string` |
| **Default** | `openai/text-embedding-3-small` |
| **Valid values** | Any embedding model identifier available via OpenRouter |
| **Affects** | Model used to generate vector embeddings for KB chunks |
| **Set via** | Admin UI, REST API, `wp_options` |

Changing this option after content has been indexed requires a full KB reindex, as existing embeddings will be incompatible with the new model.

```php
update_option('vibe_ai_kb_embedding_model', 'openai/text-embedding-3-large');
```

---

#### `vibe_ai_kb_chunk_size`

| Property | Value |
|----------|-------|
| **Type** | `int` |
| **Default** | `450` |
| **Valid range** | `50` – `800` |
| **Affects** | Target token count per chunk during content splitting |
| **Set via** | Admin UI, REST API, `wp_options` |

Larger chunks provide more context per embedding but may reduce retrieval precision. Smaller chunks improve precision but may lose context. The chunker respects heading boundaries, so actual chunk sizes may vary.

```php
update_option('vibe_ai_kb_chunk_size', 300);
```

---

#### `vibe_ai_kb_chunk_overlap`

| Property | Value |
|----------|-------|
| **Type** | `int` |
| **Default** | `60` |
| **Valid range** | `0` to less than `vibe_ai_kb_chunk_size` |
| **Affects** | Token overlap between consecutive chunks |
| **Set via** | Admin UI, REST API, `wp_options` |

Overlap provides context continuity between chunks. Higher values improve retrieval of content that spans chunk boundaries at the cost of increased storage and embedding generation.

```php
update_option('vibe_ai_kb_chunk_overlap', 80);
```

---

#### `vibe_ai_kb_post_types`

| Property | Value |
|----------|-------|
| **Type** | `array` (serialized) |
| **Default** | Same as `vibe_ai_post_types`: `['post', 'page', 'product', 'attachment']` |
| **Valid values** | Any registered public post type |
| **Affects** | Which post types are indexed into the KB |
| **Set via** | Admin UI, REST API, `wp_options`, `vibe_ai_kb_post_types` filter |

Independent from entity post types — you can extract entities from products while only indexing posts in the KB.

```php
update_option('vibe_ai_kb_post_types', ['post', 'docs']);
```

---

#### `vibe_ai_kb_auto_index`

| Property | Value |
|----------|-------|
| **Type** | `bool` |
| **Default** | `false` |
| **Valid values** | `true`, `false` |
| **Affects** | Whether posts are automatically indexed/reindexed on save |
| **Set via** | Admin UI, REST API, `wp_options` |

When enabled, the KB pipeline schedules a single-post reindex job 30 seconds after a post is saved. This delay batches rapid successive saves. Disabled by default to avoid unexpected API costs.

```php
update_option('vibe_ai_kb_auto_index', true);
```

---

## Config Hierarchy

Configuration values are resolved in the following precedence order (highest to lowest):

```
1. WordPress filters (request-time)     ← Highest priority
   ↓ falls through if no filter is registered
2. wp_options (operator overrides)      ← Set via admin UI / REST API
   ↓ falls through if no option is stored
3. Config.php constants (hard defaults) ← Lowest priority
```

### How each layer works

**Config.php constants** — Hard-coded PHP constants that ship with the plugin. These always have a value and serve as the fallback for every configuration lookup.

**wp_options** — Database-stored values that override constants. Set by the admin settings page, the REST API (`POST /wp-json/vibe-ai/v1/settings`), or direct `update_option()` calls. These persist across requests.

**WordPress filters** — Applied at runtime during each request. These override both constants and `wp_options`. Filters are the most flexible mechanism because they can be conditional:

```php
// Override confidence threshold only for product posts
add_filter('vibe_ai_confidence_threshold', function (float $threshold): float {
    if (is_singular('product')) {
        return 0.85;
    }
    return $threshold;
});
```

### When each layer is consulted

| Config value | Constants | wp_options | Filters |
|-------------|-----------|------------|---------|
| `vibe_ai_model` | `Config::DEFAULT_MODEL` | `get_option('vibe_ai_model')` | — |
| `vibe_ai_batch_size` | `Config::BATCH_SIZE` | `get_option('vibe_ai_batch_size')` | — |
| `vibe_ai_confidence_threshold` | `Config::CONFIDENCE_MEDIUM` | `get_option('vibe_ai_confidence_threshold')` | `apply_filters('vibe_ai_confidence_threshold', ...)` |
| `vibe_ai_post_types` | `Config::DEFAULT_POST_TYPES` | `get_option('vibe_ai_post_types')` | `apply_filters('vibe_ai_post_types', ...)` |
| `vibe_ai_kb_enabled` | `Config::KB_ENABLED` | `get_option('vibe_ai_kb_enabled')` | — |
| `vibe_ai_kb_post_types` | `Config::DEFAULT_POST_TYPES` | `get_option('vibe_ai_kb_post_types')` | `apply_filters('vibe_ai_kb_post_types', ...)` |

---

## Key Config Constants

Defined in `includes/Config.php`. These are the hard defaults and cannot be overridden via `wp_options` or filters (unless a specific filter exists as noted above).

### Processing

| Constant | Value | Description |
|----------|-------|-------------|
| `BATCH_SIZE` | `50` | Default batch size for processing posts |
| `MIN_BATCH_SIZE` | `5` | Dynamic sizing floor |
| `MAX_BATCH_SIZE` | `50` | Dynamic sizing ceiling |
| `MAX_CONCURRENT_BATCHES` | `3` | Maximum concurrent batches allowed |
| `PROPAGATION_BATCH_SIZE` | `50` | Batch size for entity propagation jobs |
| `TARGET_PROCESS_TIME` | `5.0` | Target processing time in seconds for dynamic batch sizing |

### AI Model

| Constant | Value | Description |
|----------|-------|-------------|
| `DEFAULT_MODEL` | `anthropic/claude-opus-4.5` | Primary extraction model |
| `FALLBACK_MODEL` | `anthropic/claude-opus-4.5` | Model used when primary is unavailable |
| `MAX_TOKENS` | `4096` | Maximum tokens in AI response |
| `TEMPERATURE` | `0.1` | Model temperature (low for consistency) |

### Confidence Thresholds

| Constant | Value | Description |
|----------|-------|-------------|
| `CONFIDENCE_HIGH` | `0.85` | Auto-approve tier |
| `CONFIDENCE_MEDIUM` | `0.60` | Include but flag for review |
| `CONFIDENCE_LOW` | `0.40` | Store but exclude from schema |
| `SCHEMA_MIN_CONFIDENCE` | `0.60` | Minimum confidence for Schema.org inclusion |

### Entity Limits

| Constant | Value | Description |
|----------|-------|-------------|
| `MAX_ENTITIES_PER_POST` | `50` | Maximum entities extracted per post |
| `MAX_CONTEXT_LENGTH` | `500` | Maximum context snippet length (chars) |
| `MAX_ALIASES_PER_ENTITY` | `20` | Maximum aliases per entity |
| `MAX_DESCRIPTION_LENGTH` | `500` | Maximum description length (chars) |

### Rate Limiting and Retry

| Constant | Value | Description |
|----------|-------|-------------|
| `REQUESTS_PER_MINUTE` | `60` | Maximum API requests per minute |
| `TOKENS_PER_MINUTE` | `100000` | Maximum tokens per minute |
| `RETRY_ATTEMPTS` | `3` | Number of retry attempts for failed API calls |
| `BASE_DELAY_SECONDS` | `5` | Base delay before first retry |
| `BACKOFF_MULTIPLIER` | `2` | Exponential backoff multiplier |

### Knowledge Base

| Constant | Value | Description |
|----------|-------|-------------|
| `KB_CHUNK_TOKENS_TARGET` | `450` | Target tokens per chunk |
| `KB_CHUNK_OVERLAP_TOKENS` | `60` | Token overlap between chunks |
| `KB_TOP_K_DEFAULT` | `8` | Default number of search results |
| `KB_MAX_SCAN_VECTORS` | `5000` | Maximum vectors to scan during search |
| `KB_BATCH_SIZE_CHUNKS` | `25` | Batch size for chunk processing |
| `KB_MIN_CHUNK_TOKENS` | `50` | Minimum tokens for a valid chunk |
| `KB_MAX_CHUNK_TOKENS` | `800` | Maximum tokens per chunk |

---

## Environment Variables

The following constants are typically defined in `wp-config.php` but can also be set as server environment variables:

| Constant | Required | Description |
|----------|----------|-------------|
| `VIBE_AI_OPENROUTER_KEY` | **Yes** | OpenRouter API key for all AI calls (entity extraction + embeddings) |
| `VIBE_AI_ENCRYPTION_KEY` | No | Encryption key for sensitive data at rest. Auto-generated if not provided. |

### Setting via wp-config.php

```php
// Required — plugin will not function without this
define('VIBE_AI_OPENROUTER_KEY', 'sk-or-v1-your-key-here');

// Optional — auto-generated if not defined
define('VIBE_AI_ENCRYPTION_KEY', 'your-random-32-byte-string');

// Optional — useful for development
define('VIBE_AI_LOG_LEVEL', 'debug');
```

---

## Source refs

- Config constants: `includes/Config.php`
- Batch size management: `includes/Services/BatchSizeManager.php`
- Confidence threshold filter: `includes/Services/SchemaGenerator.php:108`
- Post types filter: `includes/Pipeline/PipelineManager.php:140`
- KB enabled check: `includes/Config.php:isKBEnabled()`
- KB post types: `includes/Config.php:getKBPostTypes()`
- KB pipeline auto-index: `includes/Pipeline/KBPipelineManager.php:656` (`on_save_post`)
- REST settings endpoint: `includes/REST/RestController.php:get_settings()`

## Related docs

- `docs/extensibility/hooks-and-filters.md` — WordPress filters for runtime overrides
- `docs/data-model/options-and-meta.md` — Complete option and meta key reference
- `docs/getting-started/installation.md` — Plugin installation and activation
- `docs/architecture/overview.md` — How configuration flows through the system
