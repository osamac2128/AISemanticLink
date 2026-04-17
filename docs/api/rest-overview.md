# REST API Overview

> Docs home: `docs/index.md`

## Namespace

- Base namespace: `/wp-json/vibe-ai/v1`
- Controllers:
  - `includes/REST/RestController.php` — entity extraction pipeline, entity CRUD, aliases, logs, settings
  - `includes/REST/KBController.php` — knowledge base search, document management, and REST mirrors for AI publishing resources

## Auth model

- Most endpoints require `manage_options` capability (`Config::REQUIRED_CAPABILITY`).
- Public crawler-facing endpoints are available at `/llms.txt`, `/ai-sitemap`, and `/changes`.
- Equivalent public REST mirrors remain available under `/kb/llms-txt`, `/kb/sitemap`, and `/kb/feed`.
- Auth is handled via WordPress nonces and cookies (standard WP REST API auth). For programmatic access, use Application Passwords or an authentication plugin.

## Response style

- JSON responses via `rest_ensure_response`.
- Common errors return `WP_Error` with status code payload.
- Collection endpoints include pagination headers where applicable:
  - `X-WP-Total`
  - `X-WP-TotalPages`
- Responses include HATEOAS links (`_links`) for discovery.

## Status surfaces

- `GET /status` returns extraction pipeline state, high-level entity stats, and a `semantic_health` object for dashboard reporting.
- `semantic_health` includes:
  - summary scoring
  - schema coverage and freshness
  - entity coverage
  - KB coverage
  - AI publishing endpoint readiness
  - pass/warn/fail release checks

## Endpoint groups

- Entity and extraction pipeline: `docs/api/entities.md`
- Knowledge base and semantic retrieval: `docs/api/knowledge-base.md`

## Request/Response Format

All request and response bodies are JSON (`Content-Type: application/json`).

### Success response

Success responses return the requested resource directly as a JSON object or array. HTTP status is `200 OK` for reads, `201 Created` for resource creation.

### Error response

Errors follow the WordPress REST API error format:

```json
{
  "code": "vibe_ai_entity_not_found",
  "message": "Entity not found",
  "data": {
    "status": 404
  }
}
```

Additional examples:

```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to access this resource.",
  "data": {
    "status": 403
  }
}
```

```json
{
  "code": "rest_invalid_param",
  "message": "source_ids must be a non-empty array of entity IDs.",
  "data": {
    "status": 400
  }
}
```

```json
{
  "code": "rest_pipeline_running",
  "message": "Pipeline is already running. Stop it first before starting a new run.",
  "data": {
    "status": 409
  }
}
```

## Common HTTP Status Codes

| Code | Meaning | When |
|------|---------|------|
| `200` | OK | Successful read or update |
| `201` | Created | Alias added, resource created |
| `400` | Bad Request | Invalid parameters, validation failure |
| `401` | Unauthorized | Missing or invalid authentication |
| `403` | Forbidden | User lacks `manage_options` capability |
| `404` | Not Found | Entity, document, chunk, or alias not found |
| `409` | Conflict | Pipeline already running, alias already exists |
| `429` | Too Many Requests | Rate limit exceeded (AI provider or local) |
| `500` | Internal Server Error | Unexpected failure, database error |

## Error Codes

The plugin uses the following internal error codes throughout the pipeline and REST layer. Each includes the recommended recovery strategy.

| Code | Description | Recovery |
|------|-------------|----------|
| **E001** | API rate limit hit | Auto-retry with exponential backoff (see Rate Limiting below) |
| **E002** | Invalid JSON from AI response | Log the malformed response and skip the item |
| **E003** | Database constraint violation | Log full context and investigate manually |
| **E004** | Entity not found | Return `404` to the client |
| **E005** | Propagation timeout (`PROPAGATION_TIMEOUT = 3600s`) | Resume from checkpoint on next pipeline run |
| **E101** | Chunk build failed for a document | Log error and retry the document on next pipeline pass |
| **E102** | Embedding generation failed | Retry with exponential backoff up to `RETRY_ATTEMPTS` |
| **E103** | Vector store error | Log full context and investigate manually |

## Pagination

Collection endpoints (`GET /entities`, `GET /kb/docs`, `GET /logs`) support standard pagination:

### Parameters

| Parameter | Type | Default | Max | Description |
|-----------|------|---------|-----|-------------|
| `page` | integer | `1` | — | Current page number (1-based) |
| `per_page` | integer | `20` | `100` | Items per page |

### Response headers

```
X-WP-Total: 142
X-WP-TotalPages: 8
```

### Response body

```json
{
  "entities": [ ... ],
  "total": 142,
  "pages": 8
}
```

### HATEOAS links

Paginated responses include `_links` for navigation:

```json
{
  "_links": {
    "self": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities?page=3&per_page=20" }],
    "first": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities?page=1&per_page=20" }],
    "prev": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities?page=2&per_page=20" }],
    "next": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities?page=4&per_page=20" }],
    "last": [{ "href": "https://example.com/wp-json/vibe-ai/v1/entities?page=8&per_page=20" }]
  }
}
```

## Rate Limiting

The `AIClient` (`includes/Services/AIClient.php`) implements multiple layers of rate protection for OpenRouter API calls.

### Local rate limit

- **Window**: 60 seconds (sliding)
- **Max requests per window**: 60 (`REQUESTS_PER_MINUTE`)
- **Max tokens per window**: 100,000 (`TOKENS_PER_MINUTE`)
- When the local limit is exceeded, a `RateLimitException` is thrown and the retry loop handles it.

### Retry with exponential backoff

- **Max attempts**: 3 (`MAX_RETRIES`)
- **Base delay**: 5 seconds (`BASE_DELAY_SECONDS`)
- **Multiplier**: 2 (`BACKOFF_MULTIPLIER`)
- **Delay sequence**: 5s → 10s → 20s

```
attempt 1 → fail → wait 5s
attempt 2 → fail → wait 10s
attempt 3 → fail → throw RuntimeException
```

### Circuit breaker

- **Failure threshold**: 5 consecutive failures (`CIRCUIT_FAILURE_THRESHOLD`)
- **Cooldown period**: 300 seconds / 5 minutes (`CIRCUIT_COOLDOWN_SECONDS`)
- **State storage**: WordPress option `vibe_ai_openrouter_circuit`
- When the circuit is open, all requests immediately fail with `RuntimeException('OpenRouter circuit breaker is open; retry later')` until the cooldown expires.
- A single successful call resets the circuit breaker state.

### Provider rate limit (429)

When OpenRouter returns HTTP 429, the `RateLimitException` is created with `Retry-After` header data if available, and the standard retry loop applies.

## Source refs

- Route registration (entity): `includes/REST/RestController.php:77`
- Route registration (KB): `includes/REST/KBController.php:144`
- Permission model (`manage_options`): `includes/REST/RestController.php:343`, `includes/REST/KBController.php:558`
- Retry and backoff logic: `includes/Services/AIClient.php:145`
- Circuit breaker: `includes/Services/AIClient.php:382`
- Rate limit constants: `includes/Config.php:64`

## Related docs

- Data keys and option names: `docs/data-model/options-and-meta.md`
- Pipeline architecture: `docs/architecture/entity-pipeline.md`, `docs/architecture/kb-pipeline.md`
