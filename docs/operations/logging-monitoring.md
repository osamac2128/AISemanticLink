# Logging and Monitoring

> Docs home: `docs/index.md`

## Log Storage

### Location and Structure

- **Directory**: `{wp_upload_dir()}/vibe-ai-logs/` (typically `wp-content/uploads/vibe-ai-logs/`)
- **File pattern**: `YYYY-MM-DD.log` (one file per day, UTC date)
- **Created on**: Plugin activation (`Activator::createLogDirectory()`)
- **Directory protection**:
  - `.htaccess` with `Order deny,allow\nDeny from all`
  - `index.php` with `<?php // Silence is golden`
- **Total size**: tracked by `Logger::getTotalLogSize()` (sum of all `.log` files)

### Log File Lifecycle

```
Activation → mkdir + .htaccess + index.php
     ↓
Runtime → append to YYYY-MM-DD.log
     ↓
Daily cron (vibe_ai_daily_cleanup) → delete files older than 30 days
     ↓
Deactivation → cron cleared, files preserved
Uninstall → entire vibe-ai-logs/ directory removed
```

## Log Format

### Line Format

```
[HH:MM:SS] [LEVEL] Message {"json":"context"}
[HH:MM:SS] [API] API Call {"model":"...","duration_ms":...,"success":...}
```

### Fields

| Field | Description |
|-------|-------------|
| `HH:MM:SS` | UTC timestamp (`gmdate('H:i:s')`) |
| `LEVEL` | `DEBUG`, `INFO`, `WARNING`, `ERROR` (or `API` tag) |
| `Message` | Human-readable description |
| `{"json"}` | Optional JSON-encoded context with `JSON_UNESCAPED_SLASHES` |

### Log Levels

Levels are ordered by priority. The configured log level acts as a minimum threshold:

| Level | Priority | Value | Use For |
|-------|----------|-------|---------|
| `debug` | Lowest | 0 | Internal state tracing, hook registration, batch processing details |
| `info` | Default | 1 | Pipeline lifecycle, entity operations, API calls |
| `warning` | Medium | 2 | Rate limits, retries, non-fatal issues |
| `error` | Highest | 3 | Extraction failures, API errors, pipeline crashes |

Only entries at or above the configured threshold are written. Default is `info`.

### Special Tags

- **`[API]`**: Tagged entries for AI API calls, logged via `Logger::api()`. Always logged at `info` level. Includes:
  - `model`: The AI model identifier (e.g., `anthropic/claude-opus-4.5`)
  - `duration_ms`: Wall-clock time of the API call
  - `success`: Boolean result
  - `error`: Error message (only on failure)

### Log Entry Examples

```
[14:23:45] [INFO] Plugin initialization started {"version":"1.0.0"}
[14:23:45] [INFO] Plugin initialization completed successfully
[14:23:46] [INFO] Pipeline started via REST API {"options":{"post_types":["post","page"],"force_reprocess":false},"user_id":1}
[14:23:46] [INFO] Pipeline started {"phase":"preparation","total_posts":150}
[14:23:47] [API] API Call {"model":"anthropic/claude-opus-4.5","duration_ms":2340,"success":true}
[14:23:48] [INFO] Entity updated {"entity_id":42,"fields":["name","schema_type"]}
[14:24:01] [WARNING] Rate limit hit {"retry_after":5,"attempt":2}
[14:24:02] [API] API Call {"model":"anthropic/claude-opus-4.5","duration_ms":1200,"success":false,"error":"HTTP 429: Too Many Requests"}
[14:25:12] [ERROR] Extraction failed {"post_id":42,"error":"JSON parse error"}
[14:30:00] [INFO] Daily cleanup completed
[14:30:00] [INFO] Log cleanup completed {"files_deleted":3}
```

### Context Sanitization

All context data is sanitized before logging. Keys matching these patterns (case-insensitive) are replaced with `[REDACTED]`:

- `api_key`, `password`, `secret`, `token`, `key`, `authorization`

Nested arrays are sanitized recursively.

## Retention and Rotation

### Configuration

| Setting | Value | Configurable |
|---------|-------|-------------|
| Default retention | 30 days | Yes, via `Logger::cleanup($days_to_keep)` parameter |
| Rotation method | Daily file creation | Automatic (no config needed) |
| Cleanup trigger | `vibe_ai_daily_cleanup` cron event | Scheduled on activation, cleared on deactivation |

### Cleanup Process

The daily cleanup cron calls `Plugin::dailyCleanup()` which:

1. Invokes `Logger::cleanup(30)` to delete `.log` files older than 30 days
2. Calls `cleanupOrphanedData()` to remove transients for deleted entities
3. Logs the cleanup result

```php
// Logger::cleanup() extracts date from filename and compares against cutoff
$filename = basename($file, '.log');  // e.g., "2026-03-11"
$file_date = strtotime($filename);
if ($file_date < $cutoff) { unlink($file); }
```

## Access Paths

### Entity Logs (REST)

```
GET /wp-json/vibe-ai/v1/logs?level=warning&limit=50&date=2026-04-10
```

Parameters:

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `level` | string | `info` | Minimum log level (`debug`, `info`, `warning`, `error`) |
| `limit` | int | 50 | Max entries (1–500) |
| `date` | string | today | Log file date (`YYYY-MM-DD`) |

Response includes `entries` array (newest first), `count`, `level`, `date`, and `log_files` list.

### KB Logs (REST)

```
GET /wp-json/vibe-ai/v1/kb/logs?component=embedding&limit=100
```

Additional parameter:

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `component` | string | `all` | Filter by `kb`, `embedding`, `chunking`, or `all` |

### Direct Filesystem

```bash
# SSH/server access
ls -la wp-content/uploads/vibe-ai-logs/
cat wp-content/uploads/vibe-ai-logs/2026-04-10.log
grep "\[ERROR\]" wp-content/uploads/vibe-ai-logs/*.log
```

### Admin UI

Navigate to **AI Entity Index → Logs** in WordPress admin. The React-based log viewer provides:
- Filterable by level
- Date selection
- Auto-refresh during pipeline runs

## Useful Status Endpoints

| Endpoint | Purpose |
|----------|---------|
| `GET /wp-json/vibe-ai/v1/status` | Entity pipeline status, progress, stats |
| `GET /wp-json/vibe-ai/v1/kb/status` | KB pipeline status, document/chunk/vector counts |
| `GET /wp-json/vibe-ai/v1/logs` | Recent entity pipeline logs |
| `GET /wp-json/vibe-ai/v1/kb/logs` | Recent KB pipeline logs |

## Monitoring Recommendations

### Key Metrics to Track

1. **Pipeline Status Transitions**
   - Watch for: `idle → running → completed` (normal)
   - Alert on: `running → running` stuck for extended periods
   - Alert on: `running → failed` without recovery

2. **Rate Limit Errors (429 Responses)**
   - Track `[API]` entries with `"success":false` and rate limit context
   - Watch for retry bursts (multiple `[WARNING] Rate limit hit` entries)
   - Correlate with batch size changes via `BatchSizeManager`

3. **Action Scheduler Queue**
   - Monitor pending/failed actions for `vibe_ai_*` hooks
   - Check **WP Admin → Tools → Scheduled Actions** regularly
   - Watch for stale actions (scheduled but never executed)

4. **KB Search Latency**
   - Track `query_time_ms` in KB search log entries
   - Establish baseline for your content volume
   - Watch for gradual increase as vector count grows

5. **Log File Size Growth**
   - Check `Logger::getTotalLogSize()` periodically
   - Sudden spikes may indicate error loops
   - Ensure retention cleanup is running daily

6. **Circuit Breaker State**
   - Monitor `vibe_ai_openrouter_circuit` option in `wp_options`
   - `open_until` timestamp present = circuit is open (API calls blocked)
   - 5 consecutive failures → 5-minute cooldown (`AIClient.php:427-429`)

## Alerting Thresholds

| Condition | Threshold | Action |
|-----------|-----------|--------|
| Rate limit errors | > 10 in 1 hour | Investigate API usage patterns, consider reducing batch size |
| Pipeline stuck | Running > 2 hours | Check Action Scheduler for stale jobs, use stop endpoint |
| Log directory size | > 100 MB | Verify retention settings, check for error loops |
| Circuit breaker open | Any activation | Check OpenRouter status, verify API key validity |
| Extraction failures | > 5 consecutive | Check API key, model availability, content size |
| KB search latency | > 2000 ms average | Review `KB_MAX_SCAN_VECTORS`, check vector count |
| Action Scheduler queue | > 50 pending `vibe_ai_*` | Check runner health, verify WP-Cron is functional |

### Setting Up Alerts

For production sites, consider monitoring via:

- **Log file grep**: Cron job scanning for `[ERROR]` patterns
- **REST polling**: Periodic `GET /status` with timeout/condition checks
- **WordPress health checks**: Integrate with site monitoring tools via the status endpoints
- **External log aggregation**: Tail log files to a centralized logging service (e.g., papertrail, Datadog)
