# Troubleshooting Guide

> Docs home: `docs/index.md`

Common issues organized by symptom. Each entry includes the likely cause and step-by-step resolution.

---

## 1. Admin Page Is Blank / White Screen

### Likely Cause

Missing build assets or a JavaScript error prevents the React admin UI from rendering.

### Steps to Resolve

1. **Check the browser console** for errors (F12 → Console). A missing-bundle error confirms the build files are absent.

2. **Verify build files exist** in the plugin directory:

   ```bash
   ls -la admin/js/build/
   ```

   You should see `index.js`, `index.css`, and chunk files. If the directory is empty or missing, the build was never run or was deleted.

3. **Rebuild the admin assets**:

   ```bash
   cd wp-content/plugins/ai-entity-index
   npm install
   npm run build
   ```

   For development with hot reload, use `npm run start` instead.

4. **Verify PHP version**. The plugin requires PHP 8.1+. Older versions may throw fatal errors that produce a white screen:

   ```bash
   php -v
   ```

5. If the issue persists after rebuilding, check the PHP error log for fatal errors related to `AI_Entity_Index` or `VIBE_AI`.

---

## 2. Pipeline Won't Start (409 Conflict)

### Likely Cause

A previous pipeline run is stuck in the `running` state. The pipeline enforces a single concurrent run and returns `409 Conflict` when one is already active.

### Steps to Resolve

1. **Check the current pipeline status**:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     /wp-json/vibe-ai/v1/status | jq .
   ```

   If the response shows `"status": "running"` but progress hasn't changed, the run is stuck.

2. **Stop the stuck pipeline**:

   ```bash
   curl -s -X POST -H "X-WP-Nonce: <nonce>" \
     /wp-json/vibe-ai/v1/stop | jq .
   ```

3. **Verify the pipeline is idle** by polling status again. You should see `"status": "idle"`.

4. **Restart the pipeline** normally.

> **Tip:** See [Recovery Playbooks](../operations/recovery-playbooks.md) for detailed diagnosis of stuck pipelines.

---

## 3. No Entities Being Extracted

### Likely Cause

The API key is missing or misconfigured, the selected post types contain no eligible content, or the confidence threshold is set too high.

### Steps to Resolve

1. **Verify the OpenRouter API key** is defined in `wp-config.php`:

   ```php
   define('VIBE_AI_OPENROUTER_KEY', 'sk-or-v1-...');
   ```

   The constant must appear **before** the `/* That's all, stop editing! */` line.

2. **Check post type settings**. Navigate to the admin UI or query the settings endpoint:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     /wp-json/vibe-ai/v1/settings | jq '.post_types'
   ```

   Ensure at least one populated post type (e.g. `post`) is enabled.

3. **Lower the confidence threshold**. The default is `0.60`. If set too high (e.g. `0.95`), nearly all extractions will be discarded:

   ```bash
   curl -s -X POST -H "X-WP-Nonce: <nonce>" \
     -H "Content-Type: application/json" \
     -d '{"entity_confidence_threshold": 0.50}' \
     /wp-json/vibe-ai/v1/settings
   ```

4. **Check logs for API errors**. Authentication failures, model errors, and extraction issues are all logged:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     "/wp-json/vibe-ai/v1/logs?level=error&limit=10" | jq '.entries[]'
   ```

   Common errors: `401 Unauthorized` (bad key), `429 Too Many Requests` (rate limited), `400 Bad Request` (model not available).

---

## 4. Entities Extracted but No Schema.org Output

### Likely Cause

The Schema.org output cache is stale or invalid, extracted entities fall below the confidence threshold for output, or the theme does not call `wp_head()`.

### Steps to Resolve

1. **Check the Schema.org cache** for a given post:

   ```bash
   wp post meta get <post_id> _vibe_ai_schema_cache
   ```

   If the meta key is missing or empty, no Schema.org data has been generated for that post.

2. **Verify the confidence threshold** for Schema output. The default is `0.60`. Entities with confidence below this value are excluded from the structured data:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     /wp-json/vibe-ai/v1/settings | jq '.entity_confidence_threshold'
   ```

3. **Ensure the theme calls `wp_head()`**. Check your theme's `header.php`:

   ```php
   <?php wp_head(); ?>
   ```

   Without this hook, the plugin cannot inject Schema.org JSON-LD into the page `<head>`.

4. **Trigger re-extraction** for the affected posts to regenerate the cache:

   ```bash
   curl -s -X POST -H "X-WP-Nonce: <nonce>" \
     -H "Content-Type: application/json" \
     -d '{"post_ids": [123, 456]}' \
     /wp-json/vibe-ai/v1/extract
   ```

> **Tip:** You can also clear the Schema cache manually by deleting the `_vibe_ai_schema_cache` meta key for a post, then re-extracting.

---

## 5. Knowledge Base Search Returns No Results

### Likely Cause

The KB feature is not enabled, no indexing run has been performed, or the vector store is empty.

### Steps to Resolve

1. **Verify KB is enabled**:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     /wp-json/vibe-ai/v1/settings | jq '.kb_enabled'
   ```

2. **Check KB status for document, chunk, and vector counts**:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     /wp-json/vibe-ai/v1/kb/status | jq .
   ```

   If `vector_count` is `0`, embeddings have not been generated.

3. **Run a full KB reindex**:

   ```bash
   curl -s -X POST -H "X-WP-Nonce: <nonce>" \
     /wp-json/vibe-ai/v1/kb/reindex | jq .
   ```

4. **Verify the embedding model** is correctly configured. The model must support embeddings (not a chat-only model):

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     /wp-json/vibe-ai/v1/settings | jq '.kb_embedding_model'
   ```

---

## 6. Slow Pipeline Processing

### Likely Cause

The batch size is too large for the server to handle, API rate limits are causing retries and backoffs, or server resources (memory, CPU) are constrained.

### Steps to Resolve

1. **Reduce the batch size**. Lower the number of posts processed per batch cycle:

   ```bash
   curl -s -X POST -H "X-WP-Nonce: <nonce>" \
     -H "Content-Type: application/json" \
     -d '{"pipeline_batch_size": 3}' \
     /wp-json/vibe-ai/v1/settings
   ```

   The default is `5`. For shared hosting, try `2` or `3`.

2. **Check logs for rate limit warnings**:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     "/wp-json/vibe-ai/v1/logs?level=warning&limit=20" | jq '.entries[] | select(.message | test("rate"))'
   ```

3. **Ensure Action Scheduler workers are healthy**. Navigate to **WP Admin → Tools → Scheduled Actions** and check that `vibe_ai_*` jobs are completing and not piling up in the `pending` queue. If workers are stalled, see the [Recovery Playbooks](../operations/recovery-playbooks.md).

4. **Monitor server resources** during a pipeline run. The extraction process is memory-intensive due to API response handling and NLP processing.

---

## 7. Rate Limit Errors (429)

### Likely Cause

The OpenRouter API quota has been exceeded. This happens when batch sizes are large, the pipeline runs frequently, or the account is on a lower-tier plan.

### Steps to Resolve

1. **Reduce batch size** to lower concurrent API calls:

   ```bash
   curl -s -X POST -H "X-WP-Nonce: <nonce>" \
     -H "Content-Type: application/json" \
     -d '{"pipeline_batch_size": 2}' \
     /wp-json/vibe-ai/v1/settings
   ```

2. **Space out pipeline runs**. Avoid running the pipeline on a tight schedule if your content volume doesn't require it.

3. **Check your OpenRouter dashboard** for current usage, rate limits, and remaining quota:

   ```
   https://openrouter.ai/settings/credits
   ```

4. **Consider upgrading your API tier** if you consistently hit rate limits with production workloads. Higher tiers offer increased rate limits and priority access.

5. **Review logs** for 429-specific entries to identify patterns:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     "/wp-json/vibe-ai/v1/logs?level=error&limit=20" | jq '.entries[] | select(.message | test("429|rate"))'
   ```

---

## 8. Entity Merges Not Propagating

### Likely Cause

Propagation jobs that update mentions and Schema.org caches after a merge are stuck in the Action Scheduler queue or were never scheduled.

### Steps to Resolve

1. **Check the Action Scheduler queue**. Navigate to **WP Admin → Tools → Scheduled Actions** and filter by `vibe_ai_propagate_entity`.

2. **Look for failed or stuck jobs**. Pending actions that haven't run indicate a scheduler issue. Failed actions will show error details.

3. **Manually trigger re-extraction** for affected posts to rebuild their entity associations and Schema.org cache:

   ```bash
   curl -s -X POST -H "X-WP-Nonce: <nonce>" \
     -H "Content-Type: application/json" \
     -d '{"post_ids": [123, 456]}' \
     /wp-json/vibe-ai/v1/extract
   ```

4. If Action Scheduler itself is unhealthy, see [Recovery Playbooks](../operations/recovery-playbooks.md) for general scheduler troubleshooting.

---

## 9. Database Errors on Activation

### Likely Cause

The MySQL storage engine is not InnoDB, the character set doesn't match, or the database user lacks `CREATE TABLE` privileges.

### Steps to Resolve

1. **Verify InnoDB is available**. The plugin's tables require InnoDB for foreign key support:

   ```sql
   SHOW ENGINES;
   ```

   Confirm `InnoDB` has a status of `YES` or `DEFAULT`.

2. **Check the character set**. Tables are created with `utf8mb4`:

   ```sql
   SELECT @@character_set_database, @@collation_database;
   ```

   If the database uses `latin1` or `utf8` (3-byte), the plugin may fail to create tables with `utf8mb4` columns.

3. **Ensure the database user has `CREATE TABLE` privileges**:

   ```sql
   SHOW GRANTS FOR CURRENT_USER();
   ```

   Look for `CREATE` and `ALTER` privileges on the database.

4. **Verify minimum MySQL/MariaDB versions**:

   - MySQL 8.0+
   - MariaDB 10.6+

   ```sql
   SELECT VERSION();
   ```

5. **Review the WordPress debug log** for specific SQL errors during activation:

   ```bash
   grep -i "ai_entity_index\|vibe_ai\|dbDelta" wp-content/debug.log
   ```

---

## 10. Log Files Growing Too Large

### Likely Cause

The log level is set to `debug`, generating verbose output, or the daily cleanup cron is not running.

### Steps to Resolve

1. **Set the log level to `info` or `warning`** to reduce output volume:

   ```bash
   curl -s -X POST -H "X-WP-Nonce: <nonce>" \
     -H "Content-Type: application/json" \
     -d '{"log_level": "warning"}' \
     /wp-json/vibe-ai/v1/settings
   ```

   | Log Level | Use Case |
   |-----------|----------|
   | `debug` | Active development or diagnosing a specific issue |
   | `info` | Normal operation; records pipeline runs and key events |
   | `warning` | Production; only records warnings and errors |
   | `error` | Minimal; only critical failures |

2. **Verify the daily cleanup cron is running**. The plugin registers a cron job (`vibe_ai_cleanup_logs`) that prunes old log entries:

   ```bash
   wp cron event list | grep vibe_ai
   ```

3. **Manually clean old logs** if the cron has missed runs:

   ```bash
   wp eval "AI_Entity_Index\Includes\Logger::cleanup();"
   ```

   Or via the REST API, delete logs older than a specific date:

   ```bash
   curl -s -X DELETE -H "X-WP-Nonce: <nonce>" \
     "/wp-json/vibe-ai/v1/logs?before=2025-01-01"
   ```

> **Tip:** In production, `warning` is the recommended log level. Switch to `debug` only when actively diagnosing an issue, then switch back.
