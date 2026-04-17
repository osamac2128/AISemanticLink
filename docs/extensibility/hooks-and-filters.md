# Hooks and Filters

> Docs home: `docs/index.md`

Complete reference for all WordPress actions and filters provided by AI Entity Index. Use these to extend, customize, or integrate with the plugin's entity extraction and Knowledge Base pipelines.

## Actions

### Entity Pipeline Lifecycle

#### `vibe_ai_pipeline_started`

Fired when the entity extraction pipeline begins a new run.

```php
do_action('vibe_ai_pipeline_started', array $config);
```

| Param | Type | Description |
|-------|------|-------------|
| `$config` | `array` | Pipeline configuration: `post_types`, `batch_size`, `force_reprocess`, `started_at`, `started_by` |

**Example — Log pipeline start to an external service:**

```php
add_action('vibe_ai_pipeline_started', function (array $config) {
    my_external_log('Entity pipeline started', [
        'post_types' => $config['post_types'],
        'started_by' => $config['started_by'],
    ]);
});
```

---

#### `vibe_ai_pipeline_phase_changed`

Fired on each phase transition within the entity pipeline. Fires *after* the new phase is stored but *before* the phase job is scheduled.

```php
do_action('vibe_ai_pipeline_phase_changed', string $phase, array $stats);
```

| Param | Type | Description |
|-------|------|-------------|
| `$phase` | `string` | The new phase name: `preparation`, `extraction`, `deduplication`, `linking`, `indexing`, `schema_build` |
| `$stats` | `array` | Pipeline statistics: `total_entities`, `total_mentions`, `avg_confidence`, `entities_by_type` |

**Example — Send a Slack notification on phase change:**

```php
add_action('vibe_ai_pipeline_phase_changed', function (string $phase, array $stats) {
    if ($phase === 'extraction') {
        slack_notify("Entity pipeline entered extraction with {$stats['total_entities']} entities so far.");
    }
}, 10, 2);
```

---

#### `vibe_ai_pipeline_completed`

Fired when all 6 entity pipeline phases have completed successfully.

```php
do_action('vibe_ai_pipeline_completed', array $stats, array $progress);
```

| Param | Type | Description |
|-------|------|-------------|
| `$stats` | `array` | Final pipeline statistics: `total_entities`, `total_mentions`, `avg_confidence`, `entities_by_type` |
| `$progress` | `array` | Final progress data: `total`, `completed`, `failed`, `skipped`, `percentage`, `avg_process_time` |

**Example — Trigger downstream sync after pipeline completes:**

```php
add_action('vibe_ai_pipeline_completed', function (array $stats, array $progress) {
    if ($stats['total_entities'] > 0) {
        my_sync_entities_to_external_index($stats['total_entities']);
    }
}, 10, 2);
```

---

#### `vibe_ai_pipeline_failed`

Fired on unrecoverable pipeline failure. The pipeline status is set to `failed` and no further phases will execute.

```php
do_action('vibe_ai_pipeline_failed', string $error, array $context);
```

| Param | Type | Description |
|-------|------|-------------|
| `$error` | `string` | Error message describing the failure |
| `$context` | `array` | Additional context such as `exception` class name, phase, etc. |

**Example — Alert on pipeline failure:**

```php
add_action('vibe_ai_pipeline_failed', function (string $error, array $context) {
    wp_mail('admin@example.com', 'Entity Pipeline Failed', $error);
}, 10, 2);
```

---

### Entity Domain

#### `vibe_ai_entities_extracted`

Fired after AI entity extraction completes for a single post. Fires once per post inside the extraction phase.

```php
do_action('vibe_ai_entities_extracted', int $post_id, array $entities);
```

| Param | Type | Description |
|-------|------|-------------|
| `$post_id` | `int` | The WordPress post ID that was processed |
| `$entities` | `array` | Array of extracted entity associative arrays. Each contains: `name` (string), `type` (string), `confidence` (float), `context` (string), `aliases` (array) |

**Example — Push extracted entities to an analytics tracker:**

```php
add_action('vibe_ai_entities_extracted', function (int $post_id, array $entities) {
    foreach ($entities as $entity) {
        my_analytics_track('entity_extracted', [
            'post_id'    => $post_id,
            'name'       => $entity['name'],
            'type'       => $entity['type'],
            'confidence' => $entity['confidence'],
        ]);
    }
}, 10, 2);
```

---

#### `vibe_ai_entity_updated`

Fired after an entity is updated via the REST API with schema-affecting changes. Triggers the schema propagation chain (`PropagateEntityChangeJob`).

```php
do_action('vibe_ai_entity_updated', int $entity_id, array $changes);
```

| Param | Type | Description |
|-------|------|-------------|
| `$entity_id` | `int` | The entity database ID |
| `$changes` | `array` | Associative array of changed field names mapped to `true`. Schema-affecting fields: `name`, `schema_type`, `same_as_url`, `wikidata_id` |

**Example — Audit entity edits:**

```php
add_action('vibe_ai_entity_updated', function (int $entity_id, array $changes) {
    my_audit_log('entity_updated', [
        'entity_id' => $entity_id,
        'changed'   => array_keys($changes),
        'user_id'   => get_current_user_id(),
    ]);
}, 10, 2);
```

---

#### `vibe_ai_entities_merged`

Fired after multiple entities are merged into a single target entity via the REST API. Triggers schema propagation for all affected posts.

```php
do_action('vibe_ai_entities_merged', int $target_id, array $source_ids, array $affected_posts);
```

| Param | Type | Description |
|-------|------|-------------|
| `$target_id` | `int` | The canonical entity ID that other entities were merged into |
| `$source_ids` | `array` | Array of entity IDs that were absorbed into the target |
| `$affected_posts` | `array` | Array of post IDs whose mentions or schema were updated by the merge |

**Example — Invalidate external cache after merge:**

```php
add_action('vibe_ai_entities_merged', function (int $target_id, array $source_ids, array $affected_posts) {
    foreach ($affected_posts as $post_id) {
        my_cache_purge('post_schema_' . $post_id);
    }
}, 10, 3);
```

---

#### `vibe_ai_entity_propagation_complete`

Fired after all affected posts have been updated for an entity change (schema regeneration). This fires once per entity update cycle, not per post.

```php
do_action('vibe_ai_entity_propagation_complete', int $entity_id);
```

| Param | Type | Description |
|-------|------|-------------|
| `$entity_id` | `int` | The entity ID whose changes have been fully propagated |

**Example — Mark propagation as done in external tracking:**

```php
add_action('vibe_ai_entity_propagation_complete', function (int $entity_id) {
    my_task_tracker->complete('entity_propagation', $entity_id);
});
```

---

### KB Pipeline Lifecycle

#### `vibe_ai_kb_pipeline_started`

Fired when the KB indexing pipeline begins a new run.

```php
do_action('vibe_ai_kb_pipeline_started', array $options);
```

| Param | Type | Description |
|-------|------|-------------|
| `$options` | `array` | Pipeline configuration: `scope` (all/post_type/post_id), `post_types`, `force`, `batch_size`, `started_at`, `started_by` |

**Example — Record KB pipeline start time:**

```php
add_action('vibe_ai_kb_pipeline_started', function (array $options) {
    set_transient('my_kb_pipeline_start', time(), HOUR_IN_SECONDS);
});
```

---

#### `vibe_ai_kb_pipeline_phase_changed`

Fired on each KB phase transition. Fires *after* the new phase is stored but *before* the phase job is scheduled.

```php
do_action('vibe_ai_kb_pipeline_phase_changed', string $phase, array $stats);
```

| Param | Type | Description |
|-------|------|-------------|
| `$phase` | `string` | The new phase name: `kb_document_build`, `kb_chunk_build`, `kb_embed_chunks`, `kb_index_upsert`, `kb_cleanup` |
| `$stats` | `array` | KB statistics: `total_docs`, `total_chunks`, `total_vectors`, `by_post_type`, `by_status` |

**Example — Monitor KB progress:**

```php
add_action('vibe_ai_kb_pipeline_phase_changed', function (string $phase, array $stats) {
    error_log("[KB Pipeline] Now in phase: {$phase}. Docs: {$stats['total_docs']}, Chunks: {$stats['total_chunks']}");
}, 10, 2);
```

---

#### `vibe_ai_kb_pipeline_completed`

Fired when all KB pipeline phases have completed successfully.

```php
do_action('vibe_ai_kb_pipeline_completed', array $stats);
```

| Param | Type | Description |
|-------|------|-------------|
| `$stats` | `array` | Final KB statistics: `total_docs`, `total_chunks`, `total_vectors`, `by_post_type`, `by_status` |

**Example — Trigger llms.txt generation after full reindex:**

```php
add_action('vibe_ai_kb_pipeline_completed', function (array $stats) {
    if (function_exists('my_generate_llms_txt')) {
        my_generate_llms_txt();
    }
});
```

---

#### `vibe_ai_kb_pipeline_failed`

Fired on unrecoverable KB pipeline failure.

```php
do_action('vibe_ai_kb_pipeline_failed', string $phase, string $error);
```

| Param | Type | Description |
|-------|------|-------------|
| `$phase` | `string` | The phase that was active when the failure occurred |
| `$error` | `string` | Error message describing the failure |

**Example — Alert on KB failure:**

```php
add_action('vibe_ai_kb_pipeline_failed', function (string $phase, string $error) {
    wp_mail('admin@example.com', 'KB Pipeline Failed', "Phase: {$phase}\nError: {$error}");
}, 10, 2);
```

---

### KB Domain

#### `vibe_ai_kb_document_indexed`

Fired after a document has been fully indexed into the KB (chunked, embedded, and upserted).

```php
do_action('vibe_ai_kb_document_indexed', int $post_id);
```

| Param | Type | Description |
|-------|------|-------------|
| `$post_id` | `int` | The WordPress post ID that was fully indexed |

---

#### `vibe_ai_kb_post_scheduled`

Fired when a post is scheduled for KB reindexing (typically from `save_post` when `vibe_ai_kb_auto_index` is enabled).

```php
do_action('vibe_ai_kb_post_scheduled', int $post_id);
```

| Param | Type | Description |
|-------|------|-------------|
| `$post_id` | `int` | The WordPress post ID scheduled for reindexing |

---

#### `vibe_ai_kb_post_removed`

Fired when a post is deleted and its KB data (documents, chunks, vectors) is removed.

```php
do_action('vibe_ai_kb_post_removed', int $post_id);
```

| Param | Type | Description |
|-------|------|-------------|
| `$post_id` | `int` | The WordPress post ID that was removed from the KB |

---

#### `vibe_ai_kb_post_excluded`

Fired when a post is trashed and excluded from KB indexing.

```php
do_action('vibe_ai_kb_post_excluded', int $post_id);
```

| Param | Type | Description |
|-------|------|-------------|
| `$post_id` | `int` | The WordPress post ID excluded from the KB |

---

#### `vibe_ai_kb_post_included`

Fired when a post is untrashed and re-included for KB indexing.

```php
do_action('vibe_ai_kb_post_included', int $post_id);
```

| Param | Type | Description |
|-------|------|-------------|
| `$post_id` | `int` | The WordPress post ID re-included in the KB |

---

#### `vibe_ai_kb_cleanup_complete`

Fired after stale KB data cleanup completes.

```php
do_action('vibe_ai_kb_cleanup_complete', array $stats);
```

| Param | Type | Description |
|-------|------|-------------|
| `$stats` | `array` | Cleanup statistics: documents removed, chunks removed, vectors removed |

---

## Filters

### Entity Extraction

#### `vibe_ai_post_types`

Control which post types are processed by the entity extraction pipeline.

```php
apply_filters('vibe_ai_post_types', array $post_types);
```

| Param | Type | Default | Return |
|-------|------|---------|--------|
| `$post_types` | `array<string>` | `['post', 'page', 'product', 'attachment']` | `array<string>` |

**Example — Add a custom post type to entity extraction:**

```php
add_filter('vibe_ai_post_types', function (array $post_types): array {
    return array_merge($post_types, ['my_cpt', 'project']);
});
```

**Example — Restrict extraction to posts only:**

```php
add_filter('vibe_ai_post_types', function (array $post_types): array {
    return ['post'];
});
```

---

#### `vibe_ai_confidence_threshold`

Adjust the minimum confidence score for entity acceptance and Schema.org inclusion.

```php
apply_filters('vibe_ai_confidence_threshold', float $threshold);
```

| Param | Type | Default | Valid Range | Return |
|-------|------|---------|-------------|--------|
| `$threshold` | `float` | `0.60` | `0.40` – `0.95` | `float` |

Entities below this threshold are stored but excluded from Schema.org output. The value is clamped internally to the `CONFIDENCE_LOW` (0.40) – `CONFIDENCE_HIGH` (0.85) range.

**Example — Raise threshold for higher quality schema:**

```php
add_filter('vibe_ai_confidence_threshold', function (float $threshold): float {
    return 0.80;
});
```

---

#### `vibe_ai_system_prompt`

Customize the AI system prompt used for entity extraction. **Capability-checked**: only applies when the current context has `manage_options`, is a WP-CLI command, or is a cron job.

```php
apply_filters('vibe_ai_system_prompt', string $prompt);
```

| Param | Type | Default | Return |
|-------|------|---------|--------|
| `$prompt` | `string` | Built-in extraction prompt (see `EntityExtractor::SYSTEM_PROMPT`) | `string` |

> **Warning:** Modifying this prompt can break extraction quality. Keep the JSON response format directive intact. Ensure any custom prompt still instructs the model to return `{"entities": [...]}` with `name`, `type`, `confidence`, `context`, and `aliases` fields.

**Example — Add domain-specific extraction rules:**

```php
add_filter('vibe_ai_system_prompt', function (string $prompt): string {
    return $prompt . "\n\nADDITIONAL RULES:\n- Prioritize medical terminology extraction\n- Always classify drug names as PRODUCT type";
});
```

---

#### `vibe_ai_extracted_entities`

Post-process entities after AI extraction but before storage. Allows adding, removing, or modifying entities for a specific post.

```php
apply_filters('vibe_ai_extracted_entities', array $entities, int $post_id);
```

| Param | Type | Description | Return |
|-------|------|-------------|--------|
| `$entities` | `array` | Array of entity arrays with `name`, `type`, `confidence`, `context`, `aliases` | `array` |
| `$post_id` | `int` | The post ID entities were extracted from | _(not returned)_ |

**Example — Remove low-confidence entities for specific post types:**

```php
add_filter('vibe_ai_extracted_entities', function (array $entities, int $post_id): array {
    $post = get_post($post_id);
    if ($post && $post->post_type === 'product') {
        return array_filter($entities, fn($e) => $e['confidence'] >= 0.80);
    }
    return $entities;
}, 10, 2);
```

**Example — Add a manually curated entity:**

```php
add_filter('vibe_ai_extracted_entities', function (array $entities, int $post_id): array {
    if (is_single($post_id) && has_category('ai-research', $post_id)) {
        $entities[] = [
            'name'       => 'Artificial Intelligence',
            'type'       => 'CONCEPT',
            'confidence' => 0.99,
            'context'    => 'Category: AI Research',
            'aliases'    => ['AI'],
        ];
    }
    return $entities;
}, 10, 2);
```

---

### Schema

#### `vibe_ai_schema_json`

Modify the Schema.org JSON-LD structure for a post before it is cached and injected into the page.

```php
apply_filters('vibe_ai_schema_json', array $schema, int $post_id);
```

| Param | Type | Description | Return |
|-------|------|-------------|--------|
| `$schema` | `array` | Full JSON-LD structure with `@context` and `@graph` keys | `array` |
| `$post_id` | `int` | The post ID the schema is generated for | _(not returned)_ |

**Example — Add a custom schema node (publisher):**

```php
add_filter('vibe_ai_schema_json', function (array $schema, int $post_id): array {
    $schema['@graph'][] = [
        '@type' => 'Organization',
        '@id'   => get_site_url() . '/#/organization',
        'name'  => get_bloginfo('name'),
        'url'   => get_site_url(),
    ];
    return $schema;
}, 10, 2);
```

**Example — Filter out entities below a custom threshold:**

```php
add_filter('vibe_ai_schema_json', function (array $schema, int $post_id): array {
    $schema['@graph'] = array_filter($schema['@graph'], function ($node) {
        if (($node['@type'] ?? '') === 'Thing' && empty($node['description'])) {
            return false;
        }
        return true;
    });
    return $schema;
}, 10, 2);
```

---

### Knowledge Base

#### `vibe_ai_kb_post_types`

Control which post types are indexed into the Knowledge Base.

```php
apply_filters('vibe_ai_kb_post_types', array $post_types);
```

| Param | Type | Default | Return |
|-------|------|---------|--------|
| `$post_types` | `array<string>` | Same as entity post types: `['post', 'page', 'product', 'attachment']` | `array<string>` |

**Example — Index only posts and pages in the KB:**

```php
add_filter('vibe_ai_kb_post_types', function (array $post_types): array {
    return ['post', 'page'];
});
```

---

#### `vibe_ai_kb_should_index_post`

Per-post inclusion control for KB indexing. Runs after post type and status checks.

```php
apply_filters('vibe_ai_kb_should_index_post', bool $should, int $post_id);
```

| Param | Type | Description | Return |
|-------|------|-------------|--------|
| `$should` | `bool` | Default `true` if post passes type/status checks | `bool` |
| `$post_id` | `int` | The post ID being evaluated | _(not returned)_ |

**Example — Exclude posts in a specific category from KB indexing:**

```php
add_filter('vibe_ai_kb_should_index_post', function (bool $should, int $post_id): bool {
    if (has_category('internal-notes', $post_id)) {
        return false;
    }
    return $should;
}, 10, 2);
```

**Example — Exclude posts older than 2 years:**

```php
add_filter('vibe_ai_kb_should_index_post', function (bool $should, int $post_id): bool {
    $post = get_post($post_id);
    if ($post && strtotime($post->post_date) < strtotime('-2 years')) {
        return false;
    }
    return $should;
}, 10, 2);
```

---

#### `vibe_ai_kb_content`

Modify post content before it is chunked for KB indexing. Runs after content normalization (HTML stripping, shortcode expansion).

```php
apply_filters('vibe_ai_kb_content', string $content, int $post_id);
```

| Param | Type | Description | Return |
|-------|------|-------------|--------|
| `$content` | `string` | Normalized plain text content | `string` |
| `$post_id` | `int` | The post ID being indexed | _(not returned)_ |

**Example — Prepend the post title to KB content:**

```php
add_filter('vibe_ai_kb_content', function (string $content, int $post_id): string {
    $post = get_post($post_id);
    return $post->post_title . "\n\n" . $content;
}, 10, 2);
```

---

#### `vibe_ai_kb_chunks`

Modify chunks after splitting but before embedding generation.

```php
apply_filters('vibe_ai_kb_chunks', array $chunks, int $post_id);
```

| Param | Type | Description | Return |
|-------|------|-------------|--------|
| `$chunks` | `array` | Array of chunk data arrays with `chunk_index`, `anchor`, `heading_path`, `chunk_text`, `chunk_hash`, `start_offset`, `end_offset`, `token_estimate` | `array` |
| `$post_id` | `int` | The post ID being chunked | _(not returned)_ |

**Example — Merge very small chunks with their predecessor:**

```php
add_filter('vibe_ai_kb_chunks', function (array $chunks, int $post_id): array {
    $merged = [];
    foreach ($chunks as $chunk) {
        if (!empty($merged) && $chunk['token_estimate'] < 50) {
            $prev = array_pop($merged);
            $prev['chunk_text'] .= "\n\n" . $chunk['chunk_text'];
            $prev['end_offset'] = $chunk['end_offset'];
            $prev['token_estimate'] += $chunk['token_estimate'];
            $merged[] = $prev;
        } else {
            $merged[] = $chunk;
        }
    }
    return $merged;
}, 10, 2);
```

---

#### `vibe_ai_kb_search_results`

Post-process KB semantic search results. Can re-rank, filter, or augment results before they are returned to the caller.

```php
apply_filters('vibe_ai_kb_search_results', array $results, string $query);
```

| Param | Type | Description | Return |
|-------|------|-------------|--------|
| `$results` | `array` | Array of result arrays with `chunk_id`, `doc_id`, `post_id`, `title`, `url`, `anchor`, `heading_path`, `chunk_text`, `score` | `array` |
| `$query` | `string` | The original search query | _(not returned)_ |

**Example — Boost results from recent posts:**

```php
add_filter('vibe_ai_kb_search_results', function (array $results, string $query): array {
    foreach ($results as &$result) {
        $post_date = get_the_date('U', $result['post_id']);
        $age_days = (time() - $post_date) / DAY_IN_SECONDS;
        if ($age_days < 30) {
            $result['score'] *= 1.2;
        }
    }
    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
    return $results;
}, 10, 2);
```

**Example — Limit search results to specific post types:**

```php
add_filter('vibe_ai_kb_search_results', function (array $results, string $query): array {
    return array_filter($results, function ($result) {
        $post = get_post($result['post_id']);
        return $post && in_array($post->post_type, ['post', 'docs'], true);
    });
}, 10, 2);
```

---

## Implementation Guidance

### Treat hooks as extension points

All hooks documented here are stable extension points. Hook names and parameter signatures will not change in patch releases. If a breaking change is required, the old hook will be deprecated with a notice and maintained for at least one major version cycle.

Hooks **not** listed in this document are internal implementation details and may change without notice.

### Prompt customization

When using `vibe_ai_system_prompt`, avoid weakening the extraction rules. The prompt must still produce deterministic, structured JSON output. Key constraints to preserve:

- The response must be valid JSON with an `entities` key.
- Each entity must include `name`, `type`, `confidence`, `context`, and `aliases`.
- The `type` field must be one of the allowed values (PERSON, ORG, COMPANY, LOCATION, COUNTRY, PRODUCT, SOFTWARE, EVENT, WORK, CONCEPT).

### Callback performance

Hooks fire during pipeline execution, which runs on Action Scheduler background jobs. Keep callback execution time minimal:

- **Avoid database writes** in hook callbacks. Use Action Scheduler to defer heavy work.
- **Avoid HTTP requests** in hook callbacks. Queue them asynchronously.
- **Entity hooks** (`vibe_ai_entities_extracted`, `vibe_ai_entity_updated`) fire per-entity. With thousands of entities, even 10ms per callback adds significant overhead.
- **KB hooks** (`vibe_ai_kb_content`, `vibe_ai_kb_chunks`) fire per-post during indexing. Avoid expensive operations in these filters.

### Priority and argument count

All hook registrations should specify explicit priority and accepted argument count:

```php
// Good: explicit priority (10) and arg count (2)
add_action('vibe_ai_pipeline_phase_changed', 'my_callback', 10, 2);

// Avoid: relying on defaults when you need both params
add_action('vibe_ai_pipeline_phase_changed', 'my_callback');
```

Default priority is 10. Use lower numbers (1-9) to run before other callbacks, higher numbers (11+) to run after. For filters that modify data (especially `vibe_ai_schema_json`), be aware that multiple plugins may be filtering the same value.

### Filter chaining

Filters are applied in WordPress priority order. Multiple plugins can filter the same value:

```php
// Plugin A runs first (priority 10)
add_filter('vibe_ai_schema_json', function (array $schema, int $post_id): array {
    // Add publisher node
    return $schema;
}, 10, 2);

// Plugin B runs after (priority 20)
add_filter('vibe_ai_schema_json', function (array $schema, int $post_id): array {
    // Modify what Plugin A added
    return $schema;
}, 20, 2);
```

### Action Scheduler context

Pipeline actions run inside Action Scheduler jobs, which execute as cron requests. Be aware:

- `get_current_user_id()` returns 0 in cron context.
- `current_user_can()` checks will fail unless the hook explicitly passes user context.
- The `vibe_ai_system_prompt` filter is capability-checked and will not apply in frontend requests.

---

## Source refs

- Pipeline actions: `includes/Pipeline/PipelineManager.php` (start, advance_phase, complete_pipeline, fail)
- KB pipeline actions: `includes/Pipeline/KBPipelineManager.php` (start, advancePhase, complete, fail)
- Entity extraction action: `includes/Jobs/ExtractionJob.php` (`do_action('vibe_ai_entities_extracted', ...)`)
- Entity update action: `includes/REST/RestController.php` (update_entity, merge_entities)
- Propagation completion: `includes/Jobs/PropagateEntityChangeJob.php` (complete_propagation)
- Entity filters: `includes/Pipeline/PipelineManager.php:140` (`vibe_ai_post_types`), `includes/Services/SchemaGenerator.php:108` (`vibe_ai_confidence_threshold`), `includes/Services/EntityExtractor.php:414` (`vibe_ai_system_prompt`)
- Schema filter: `includes/Services/SchemaGenerator.php:117` (`vibe_ai_schema_json`)
- KB filters: `includes/Pipeline/KBPipelineManager.php` (`vibe_ai_kb_post_types`, `vibe_ai_kb_should_index_post`)

## Related docs

- `docs/getting-started/configuration.md` — Runtime config model and option overrides
- `docs/architecture/entity-pipeline.md` — Pipeline phase descriptions
- `docs/architecture/kb-pipeline.md` — KB pipeline phase descriptions
- `docs/api/rest-overview.md` — REST endpoints that trigger entity hooks
- `docs/data-model/options-and-meta.md` — Option keys and post meta keys
