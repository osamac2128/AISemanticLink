# Security Model

> Docs home: `docs/index.md`

## Access Control

### Admin REST Endpoints

All admin-facing REST endpoints require the `manage_options` capability, enforced through `permission_callback` on every route registration:

```php
// RestController.php / KBController.php
'permission_callback' => [$this, 'check_admin_permission'],

public function check_admin_permission(): bool|WP_Error
{
    if (!current_user_can(Config::REQUIRED_CAPABILITY)) {  // 'manage_options'
        return new WP_Error('rest_forbidden', '...', ['status' => 403]);
    }
    return true;
}
```

This gate applies to every write endpoint and all read endpoints except the three public publishing routes below.

### Public Endpoints (Read-Only AI Publishing)

Three endpoints are intentionally exposed without authentication for AI crawler consumption:

| Endpoint | Purpose | Auth |
|----------|---------|------|
| `GET /llms.txt` | llms.txt for AI agents | `__return_true` |
| `GET /ai-sitemap` | AI-specific sitemap | `__return_true` |
| `GET /changes` | Change feed since timestamp | `__return_true` |

Equivalent REST mirrors are also public under `/wp-json/vibe-ai/v1/kb/llms-txt`, `/kb/sitemap`, and `/kb/feed`.
These endpoints only return published content metadata. No admin data, internal IDs, or configuration is exposed.

### REST Authentication

Admin UI requests authenticate via WordPress nonces:

- Every admin page load generates a nonce via `wp_create_nonce('wp_rest')`
- The nonce is injected into the React app via `wp_localize_script` in `AdminRenderer.php:114`
- The React API client attaches the nonce as an `X-WP-Nonce` header on every request
- WordPress REST API infrastructure validates the nonce automatically before invoking any callback
- Nonces are scoped to the current user session and expire after 12-24 hours

## Nonce Verification Flow

```
AdminRenderer.php                    Browser                        WordPress REST API
       |                                |                                   |
       | wp_localize_script()           |                                   |
       |  → vibeAiData.nonce            |                                   |
       |  = wp_create_nonce('wp_rest')  |                                   |
       |-------------------------------→|                                   |
       |                                | fetch( apiUrl, {                  |
       |                                |   headers: {                      |
       |                                |     'X-WP-Nonce': nonce           |
       |                                |   }                               |
       |                                | })                                |
       |                                |----------------------------------→|
       |                                |                                   |
       |                                |     rest_cookie_check_errors()    |
       |                                |     → validates nonce             |
       |                                |     → matches current user        |
       |                                |     → checks user capabilities    |
       |                                |                                   |
       |                                |     callback execution            |
       |                                |←----------------------------------|
```

Key properties:
- Nonces are tied to the authenticated user; a stolen nonce cannot elevate privileges beyond the user's role
- The `wp_rest` nonce type is the standard WordPress pattern for REST API authentication
- Invalid or expired nonces return `401 Unauthorized` before any callback executes

## Secret Handling

### API Key Management

| Constant | Purpose | Storage | Required |
|----------|---------|---------|----------|
| `VIBE_AI_OPENROUTER_KEY` | OpenRouter API key | `wp-config.php` only | Yes |
| `VIBE_AI_ENCRYPTION_KEY` | Optional encryption key | `wp-config.php` only | No |

The OpenRouter key is loaded at runtime from a PHP constant, never stored in the database:

```php
// AIClient.php:117
if (defined('VIBE_AI_OPENROUTER_KEY')) {
    $this->api_key = VIBE_AI_OPENROUTER_KEY;
} else {
    throw new \RuntimeException(
        'OpenRouter API key not configured. Define VIBE_AI_OPENROUTER_KEY in wp-config.php'
    );
}
```

The settings endpoint confirms key presence without exposing the value:

```php
// RestController.php:1444
'api_key_configured' => defined('VIBE_AI_OPENROUTER_KEY') && !empty(VIBE_AI_OPENROUTER_KEY),
```

### Logger Sanitization

The `Logger` class automatically redacts sensitive context keys before writing to disk:

```php
// Logger.php:188
$sensitive_keys = ['api_key', 'password', 'secret', 'token', 'key', 'authorization'];

foreach ($context as $key => $value) {
    $lower_key = strtolower($key);
    foreach ($sensitive_keys as $sensitive) {
        if (strpos($lower_key, $sensitive) !== false) {
            $context[$key] = '[REDACTED]';
            break;
        }
    }
    // Recursively sanitize nested arrays
    if (is_array($value)) {
        $context[$key] = $this->sanitizeContext($value);
    }
}
```

Any context key containing `api_key`, `password`, `secret`, `token`, `key`, or `authorization` (case-insensitive substring match) is replaced with `[REDACTED]` before logging. Nested arrays are sanitized recursively.

### AIClient Header Protection

The `AIClient` never logs request headers. API call logs via `Logger::api()` record only model, duration, and success status — no authentication headers or payload content.

## Input Sanitization by Endpoint

### REST Parameter Handling

All REST endpoints define typed `args` with explicit `sanitize_callback` and `validate_callback` functions. WordPress invokes these automatically before the endpoint callback executes.

### Entity Fields

| Field | Sanitizer | Validator |
|-------|-----------|-----------|
| `name` | `sanitize_text_field` | `validate_non_empty_string` |
| `slug` | `sanitize_title` (via repository) | — |
| `description` | `wp_kses_post` | — |
| `type` | `sanitize_text_field` + enum check | `Config::VALID_TYPES` |
| `status` | `sanitize_text_field` + enum check | `Config::VALID_STATUSES` |
| `same_as_url` | `esc_url_raw` | — |
| `wikidata_id` | `sanitize_text_field` | Regex `^Q[0-9]+$` |

### Search and Filter Parameters

| Parameter | Sanitizer | Notes |
|-----------|-----------|-------|
| `search` | `sanitize_text_field` | Entity name search |
| `query` (KB) | `sanitize_text_field` | Semantic search query, max 2000 chars |
| `level` (logs) | `sanitize_text_field` | Enum-constrained to log levels |
| `date` (logs) | `sanitize_text_field` | Validated as `YYYY-MM-DD` via `checkdate()` |

### ID Parameters

- Single IDs: `absint` sanitizer + `validate_positive_integer` validator
- Array of IDs: `array_map('absint', ...)` via `sanitize_integer_array` callback
- Array of strings: `array_map('sanitize_text_field', ...)` via `sanitize_string_array` callback

### Batch Operations

Merge source IDs and bulk exclude/include endpoints use `sanitize_integer_array`:

```php
// RestController.php:478
public function sanitize_integer_array(mixed $value): array
{
    if (!is_array($value)) { return []; }
    return array_map('absint', array_filter($value, 'is_numeric'));
}
```

## Output Encoding

### JSON-LD (Public Schema Output)

Schema.org JSON-LD injected into `wp_head` uses defense-in-depth encoding:

```php
// Plugin.php:167
$safe_json = wp_json_encode(
    $decoded,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
);
printf('<script type="application/ld+json">%s</script>' . "\n", $safe_json);
```

The `JSON_HEX_*` flags convert `<`, `>`, `'`, and `"` to their Unicode escape sequences, preventing both XSS and HTML injection within the `<script>` tag.

### REST Responses

All REST responses use `rest_ensure_response()`, which handles proper JSON encoding via WordPress infrastructure:

```php
$response = rest_ensure_response($response_data);
```

### Admin UI

The admin interface is a React SPA. JSX auto-escapes interpolated values, providing built-in XSS protection. No `dangerouslySetInnerHTML` is used for entity or user-supplied content.

## SQL Injection Prevention

All database queries in repositories use `$wpdb->prepare()` with parameterized placeholders:

```php
// EntityRepository.php:84-88
$existing_id = $this->wpdb->get_var(
    $this->wpdb->prepare(
        "SELECT id FROM {$this->entities_table} WHERE slug = %s",
        $slug
    )
);
```

Table names use `$wpdb->prefix` concatenation (safe because table names are constants, not user input). Sort columns (`orderby`) use a whitelist:

```php
// EntityRepository.php:476-477
$allowed_orderby = ['id', 'name', 'type', 'status', 'mention_count', 'created_at', 'updated_at'];
$orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
```

No raw SQL concatenation with user input exists anywhere in the codebase.

## Threat Model

| Threat | Attack Vector | Mitigation |
|--------|---------------|------------|
| **Prompt injection** | `vibe_ai_system_prompt` filter modifies AI instructions | Capability check (`manage_options`, `WP_CLI`, or `DOING_CRON`) required before filter is applied (`EntityExtractor.php:409`) |
| **API key exposure** | Key logged or returned in API response | Constant-only storage (never in DB), logger sanitization, settings endpoint returns only boolean `api_key_configured` |
| **CSRF on REST endpoints** | Forged requests from malicious sites | WordPress nonce system via `X-WP-Nonce` header, validated by REST infrastructure |
| **IDOR on entity endpoints** | Accessing/modifying entities without authorization | All entity endpoints gated by `manage_options` — admin-only, no public entity access |
| **XSS via entity names** | Malicious HTML/JS in entity names injected into pages | JSON-LD output encoded with `JSON_HEX_TAG \| JSON_HEX_APOS \| JSON_HEX_QUOT`, React auto-escaping in admin UI |
| **SQL injection** | Crafted parameters in REST requests | Parameterized queries via `$wpdb->prepare()`, whitelist for sortable columns |
| **Log file access** | Direct HTTP access to log files | `.htaccess` (deny from all) + `index.php` (silence) placed in log directory |
| **Rate limit bypass** | Excessive API calls depleting quota | Circuit breaker (5 failures → 5 min cooldown), per-minute request tracking in `AIClient` |

## Prompt Override Risk

`EntityExtractor` supports a `vibe_ai_system_prompt` filter for customizing the AI extraction prompt. The code performs a capability check before applying any hook override:

```php
// EntityExtractor.php:408-415
$allow_override = current_user_can('manage_options')
    || defined('WP_CLI')
    || defined('DOING_CRON');

if ($allow_override) {
    $prompt = apply_filters('vibe_ai_system_prompt', $prompt);
}
```

This restricts prompt manipulation to admin users, WP-CLI commands, and scheduled cron jobs. Any plugin or theme with elevated execution context can still influence behavior via the filter — this is treated as trusted-admin scope.

## Recommended Hardening

- Restrict `manage_options` capability to trusted administrators only
- Define `VIBE_AI_OPENROUTER_KEY` in `wp-config.php` with restrictive file permissions (never in the database)
- Rotate API keys periodically; update the `wp-config.php` constant
- Monitor logs for unusual extraction volume or search activity patterns
- Keep Action Scheduler, WordPress core, and PHP runtime up to date
- Enable `DISALLOW_FILE_EDIT` in `wp-config.php` to prevent theme/plugin editing
- Consider `VIBE_AI_ENCRYPTION_KEY` for environments requiring encrypted storage
- Review the `vibe_ai_system_prompt` filter consumers if third-party plugins are installed
