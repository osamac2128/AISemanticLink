# Recovery Playbooks

> Docs home: `docs/index.md`

Step-by-step procedures for diagnosing and resolving common issues.

---

## 1. Pipeline Stuck as Running

The entity pipeline reports `running` but progress has stalled.

### Diagnosis

**Step 1: Check pipeline status**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/status | jq .
```

Expected output if stuck:
```json
{
  "status": "running",
  "current_phase": "extraction",
  "progress": {
    "total": 150,
    "completed": 47,
    "failed": 0,
    "percentage": 31
  }
}
```

If `completed` hasn't changed across multiple polls, the pipeline is stuck.

**Step 2: Check Action Scheduler**

Navigate to **WP Admin → Tools → Scheduled Actions** and filter by `vibe_ai_*`.

Look for:
- **Pending** actions that should have run
- **Failed** actions with error messages
- Actions stuck in `processing` state

**Step 3: Inspect recent logs**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  "/wp-json/vibe-ai/v1/logs?level=warning&limit=20" | jq '.entries[]'
```

Look for: rate limit errors, extraction failures, or a gap in log entries.

### Resolution

**Step 4: Stop the pipeline via REST**

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/pipeline/stop | jq .
```

Expected:
```json
{
  "success": true,
  "message": "Pipeline stopped successfully.",
  "status": "idle"
}
```

**Step 5: Verify idle state**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/status | jq '{status, current_phase}'
```

Expected:
```json
{
  "status": "idle",
  "current_phase": null
}
```

**Step 6: Restart with reduced scope**

Start with specific post types or a smaller subset:

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"post_types":["post"]}' \
  /wp-json/vibe-ai/v1/pipeline/start | jq .
```

---

## 2. 409 on Pipeline Start

Starting the pipeline returns HTTP 409 `rest_pipeline_running`.

### Diagnosis

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/pipeline/start | jq .
```

Error response:
```json
{
  "code": "rest_pipeline_running",
  "message": "Pipeline is already running. Stop it first before starting a new run.",
  "data": {"status": 409}
}
```

### Resolution

**Step 1: Confirm no active jobs**

Check Action Scheduler for pending `vibe_ai_process_batch` actions.

**Step 2: Check actual status**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/status | jq '{status, current_phase}'
```

If `status` is `idle` but 409 persists, the pipeline state option may be stale.

**Step 3: Force stop**

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/pipeline/stop | jq .
```

**Step 4: Verify idle**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/status | jq '.status'
```

Expected: `"idle"`

**Step 5: Start again**

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/pipeline/start | jq .
```

Expected: `{"success": true, "status": "running"}`

---

## 3. Rate-Limit or API Instability

Repeated retries, `RateLimitException`, high failure counts in logs.

### Diagnosis

**Step 1: Check for rate limit logs**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  "/wp-json/vibe-ai/v1/logs?level=warning&limit=50" | \
  jq '.entries[] | select(.message | test("rate|Rate|429"))'
```

**Step 2: Check circuit breaker state**

```bash
wp option get vibe_ai_openrouter_circuit --format=json
```

If output contains `open_until` with a future timestamp, the circuit breaker is active.

**Step 3: Verify API key**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/settings | jq '.settings.api_key_configured'
```

Expected: `true`

### Resolution

**Step 4: Reduce batch size**

The `BatchSizeManager` may have already reduced batch size, but you can force a reset:

```bash
wp option update vibe_ai_batch_size 5
```

**Step 5: Stop concurrent operations**

Ensure only one pipeline is running. Stop both if needed:

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" /wp-json/vibe-ai/v1/pipeline/stop
curl -s -X POST -H "X-WP-Nonce: <nonce>" /wp-json/vibe-ai/v1/kb/stop
```

**Step 6: Wait for cooldown**

If the circuit breaker is open, wait for `open_until` to pass (default 5-minute cooldown). Alternatively:

```bash
wp option delete vibe_ai_openrouter_circuit
```

**Step 7: Retry after cooldown**

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"post_types":["post"]}' \
  /wp-json/vibe-ai/v1/pipeline/start | jq .
```

**Step 8: Inspect API provider status**

Check [OpenRouter Status](https://status.openrouter.ai/) for ongoing incidents.

---

## 4. Schema Drift After Entity Changes

Entity names or types were updated but Schema.org output on posts hasn't changed.

### Diagnosis

**Step 1: Check propagation jobs**

```bash
# Check Action Scheduler for pending vibe_ai_propagate_entity actions
# WP Admin → Tools → Scheduled Actions → search: vibe_ai_propagate_entity
```

**Step 2: Inspect propagating entities**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/status | jq '.propagating_entities'
```

If the list is non-empty, propagation is still in progress.

**Step 3: Check schema cache**

```bash
wp post meta get <post_id> _vibe_ai_schema_cache
```

### Resolution

**Step 4: Verify propagation completed**

Wait for propagation jobs to finish. Each job processes entities in batches of 50 (`PROPAGATION_BATCH_SIZE`).

**Step 5: Trigger reprocessing for affected posts**

If schema is still stale, invalidate the cache for specific posts:

```bash
wp post meta delete <post_id> _vibe_ai_schema_cache
wp post meta delete <post_id> _vibe_ai_schema_version
```

The next page load will trigger schema regeneration.

**Step 6: Bulk regeneration**

For widespread drift, re-run the pipeline:

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"force_reprocess":true}' \
  /wp-json/vibe-ai/v1/pipeline/start
```

---

## 5. KB Index Drift

Document, chunk, or vector counts don't match expected values.

### Diagnosis

**Step 1: Get KB status**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/kb/status | jq '.stats'
```

Expected output:
```json
{
  "total_docs": 85,
  "indexed_docs": 82,
  "pending_docs": 2,
  "excluded_docs": 1,
  "failed_docs": 0,
  "total_chunks": 340,
  "total_vectors": 340,
  "failed_chunks": 0
}
```

Look for:
- `pending_docs` > 0 with no pipeline running
- `failed_docs` > 0
- `total_chunks` != `total_vectors` (embedding gap)

**Step 2: Check individual documents**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  "/wp-json/vibe-ai/v1/kb/docs?status=failed" | jq '.documents[] | {post_id, status}'
```

### Resolution

**Step 3: Reindex single documents**

For specific failed or stale documents:

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/kb/docs/<post_id>/reindex | jq .
```

Expected: `{"success": true, "message": "Document reindex has been scheduled."}`

**Step 4: Verify reindex completed**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/kb/docs/<post_id> | jq '{status, chunk_count}'
```

**Step 5: Full reindex (last resort)**

Only when broad inconsistency exists:

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"force":true}' \
  /wp-json/vibe-ai/v1/kb/reindex | jq .
```

---

## 6. Blank Admin Page

The AI Entity Index admin page loads but shows a blank white screen.

### Diagnosis

**Step 1: Check browser console**

Open browser Developer Tools (F12) → Console. Look for:
- React errors: `Uncaught TypeError`, `Cannot read properties of undefined`
- 401/403 errors on REST API calls
- Failed to load `index.jsx.js`

**Step 2: Verify build artifacts exist**

```bash
ls admin/js/build/index.jsx.js
ls admin/js/build/index.jsx.css
ls admin/js/build/index.jsx.asset.php
```

All three files must exist. If missing, rebuild:

```bash
npm run build
```

**Step 3: Check API key constant**

```bash
wp eval "var_dump(defined('VIBE_AI_OPENROUTER_KEY'));"
```

Expected: `bool(true)`

If `false`, the admin page may render but some panels will show errors.

**Step 4: Check `wp_localize_script` output**

View page source and search for `vibeAiData`. Expected:

```javascript
var vibeAiData = {"apiUrl":"...","nonce":"...","version":"...","pollingInterval":2000,...};
```

If missing, the React app cannot authenticate with the REST API.

### Resolution

**Step 5: Clear browser cache**

Hard refresh (Ctrl+Shift+R) to clear cached JS assets.

**Step 6: Verify plugin URL constant**

```bash
wp eval "var_dump(VIBE_AI_PLUGIN_URL);"
```

Should point to the correct plugin directory URL.

**Step 7: Check for PHP errors**

```bash
wp eval "error_reporting(E_ALL); new Vibe\AIIndex\Admin\AdminRenderer('1.0.0');"
```

---

## 7. Entities Not Being Extracted

Pipeline runs complete but entities are missing from expected posts.

### Diagnosis

**Step 1: Verify post type is enabled**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/settings | jq '.settings.default_post_types'
```

Check that the target post type is in the list.

**Step 2: Check pipeline isn't already running**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/status | jq '.status'
```

Must be `idle` to start a new run.

**Step 3: Verify API key is valid**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/settings | jq '.settings.api_key_configured'
```

Expected: `true`

**Step 4: Check confidence threshold**

```bash
wp option get vibe_ai_confidence_threshold
```

Default is `0.60`. Entities with confidence below this threshold are stored but excluded from Schema.org output. Entities below `0.40` are rejected entirely.

If threshold is too high, legitimate entities may be filtered:

```bash
wp option update vibe_ai_confidence_threshold 0.5
```

**Step 5: Inspect logs for extraction errors**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  "/wp-json/vibe-ai/v1/logs?level=error&limit=20" | \
  jq '.entries[] | select(.message | test("extract|Extract"; "i"))'
```

Common errors:
- `JSON parse error` — AI returned malformed JSON
- `Content too large for extraction request` — post exceeds 20k char limit
- `API returned error status 401` — invalid API key

**Step 6: Check if post was already extracted**

```bash
wp post meta get <post_id> _vibe_ai_extracted_at
```

If a timestamp exists, the post was already processed. Use `force_reprocess: true` to re-extract.

### Resolution

**Step 7: Run pipeline with force reprocess**

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"post_types":["post"],"force_reprocess":true}' \
  /wp-json/vibe-ai/v1/pipeline/start | jq .
```

---

## 8. KB Search Returns No Results

Semantic search queries return an empty results array.

### Diagnosis

**Step 1: Verify KB is enabled**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/kb/status | jq '.kb_enabled'
```

Expected: `true`

**Step 2: Check document/chunk/vector counts**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/kb/status | jq '.stats'
```

If `total_vectors` is `0`, no embeddings exist. At least one full reindex must complete before search works.

**Step 3: Verify embedding model**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/kb/status | jq '.stats' # check model in vector table
```

**Step 4: Check chunk status**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/kb/status | jq '.stats.failed_chunks'
```

Failed chunks mean embedding errors occurred.

### Resolution

**Step 5: Run a full reindex**

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"force":true}' \
  /wp-json/vibe-ai/v1/kb/reindex | jq .
```

**Step 6: Wait for completion and verify**

```bash
curl -s -H "X-WP-Nonce: <nonce>" \
  /wp-json/vibe-ai/v1/kb/status | jq '{pipeline: .pipeline.status, stats: .stats}'
```

Wait until `pipeline.status` is `idle` and `total_vectors > 0`.

**Step 7: Try a broader search query**

```bash
curl -s -X POST -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"query":"test","top_k":20}' \
  /wp-json/vibe-ai/v1/kb/search | jq '.results | length'
```

If this returns results, the previous query may have been too specific or the embedding model produced low-similarity matches.

---

## 9. Database Upgrade Issues

Plugin update causes database errors or tables are missing columns.

### Diagnosis

**Step 1: Check database version**

```bash
wp option get vibe_ai_db_version
```

Current expected version: `1.1.0`

If this is older than expected, the upgrade hasn't run.

**Step 2: Manually trigger upgrade**

Visiting any WordPress admin page triggers `Activator::maybeUpgrade()` on `admin_init`. If this doesn't work:

```bash
wp eval "Vibe\AIIndex\Activator::maybeUpgrade();"
```

**Step 3: Check for FK constraint errors**

```bash
wp db query "SHOW ENGINE INNODB STATUS" | grep -A 20 "LATEST FOREIGN KEY ERROR"
```

Common issue: tables using MyISAM engine don't support foreign keys.

**Step 4: Verify engine and charset**

```bash
wp db query "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_NAME LIKE '%ai_%'"
```

All tables should use:
- **Engine**: InnoDB
- **Collation**: `utf8mb4_*` (matching `wp-config.php` `$charset_collate`)

### Resolution

**Step 5: Fix engine if MyISAM**

```bash
wp db query "ALTER TABLE wp_ai_entities ENGINE=InnoDB"
wp db query "ALTER TABLE wp_ai_mentions ENGINE=InnoDB"
wp db query "ALTER TABLE wp_ai_aliases ENGINE=InnoDB"
wp db query "ALTER TABLE wp_ai_kb_docs ENGINE=InnoDB"
wp db query "ALTER TABLE wp_ai_kb_chunks ENGINE=InnoDB"
wp db query "ALTER TABLE wp_ai_kb_vectors ENGINE=InnoDB"
```

**Step 6: Re-run schema creation**

```bash
wp eval "Vibe\AIIndex\Activator::createTables(); Vibe\AIIndex\Activator::createKBTables();"
wp option update vibe_ai_db_version "1.1.0"
```

**Step 7: Verify table structure**

```bash
wp db query "DESCRIBE wp_ai_entities"
wp db query "DESCRIBE wp_ai_kb_vectors"
```

Confirm all expected columns exist. If columns are missing from the KB tables, the legacy migration may not have run:

```bash
wp eval "
  \Vibe\AIIndex\Activator::migrateKBLegacyColumns();
  echo 'Migration complete';
"
```
