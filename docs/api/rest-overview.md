# REST API Overview

> Docs home: `docs/index.md`

## Namespace

- Base namespace: `/wp-json/vibe-ai/v1`
- Controllers:
  - `includes/REST/RestController.php`
  - `includes/REST/KBController.php`

## Auth model

- Most endpoints require `manage_options` capability.
- Public endpoints exist for AI publishing resources under `/kb/*`.

## Response style

- JSON responses via `rest_ensure_response`.
- Common errors return `WP_Error` with status code payload.
- Collection endpoints include pagination headers where applicable:
  - `X-WP-Total`
  - `X-WP-TotalPages`

## Endpoint groups

- Entity and extraction pipeline: `docs/api/entities.md`
- Knowledge base and semantic retrieval: `docs/api/knowledge-base.md`

## Source refs

- Route registration (entity): `includes/REST/RestController.php:77`
- Route registration (KB): `includes/REST/KBController.php:144`
- Permission model (`manage_options`): `includes/REST/RestController.php:343`, `includes/REST/KBController.php:558`

## Related docs

- Data keys and option names: `docs/data-model/options-and-meta.md`
- Pipeline architecture: `docs/architecture/entity-pipeline.md`, `docs/architecture/kb-pipeline.md`
