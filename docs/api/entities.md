# Entity API

> Docs home: `docs/index.md`

Base: `/wp-json/vibe-ai/v1`

## Status and pipeline

- `GET /status`
- `GET /pipeline/status`
- `POST /pipeline/start`
  - params: `post_types[]`, `force_reprocess`
- `POST /pipeline/stop`

## Entity resources

- `GET /entities`
  - params: `page`, `per_page`, `search`, `type`, `status`, `orderby`, `order`
- `GET /entities/{id}`
- `PUT/PATCH /entities/{id}`
  - note: controller route is `WP_REST_Server::EDITABLE`, so `POST` is also accepted by WordPress REST method mapping
  - updatable fields: `name`, `type`, `schema_type`, `status`, `description`, `same_as_url`, `wikidata_id`
- `DELETE /entities/{id}`
  - param: `force` (soft-delete to `trash` if false)

## Merge and alias operations

- `POST /entities/merge`
  - params: `target_id`, `source_ids[]`
- `POST /entities/{id}/aliases`
  - params: `alias`
- `DELETE /entities/{id}/aliases/{alias_id}`

## Logs and settings

- `GET /logs`
  - params: `level`, `limit`, `date`
- `GET /settings`
- `POST/PUT/PATCH /settings` (controller uses editable route)
  - currently mostly informational; major settings are constant-driven

## Operational notes

- Start endpoint returns `409` when a run is already in progress.
- Entity update triggers `vibe_ai_entity_updated` for schema-affecting field changes.
- Merge endpoint currently triggers `vibe_ai_entity_merged` action in controller.

## Source refs

- Routes: `includes/REST/RestController.php:85`
- Collection params: `includes/REST/RestController.php:511`
- Pipeline callbacks: `includes/REST/RestController.php:1168`
- Entity update callback: `includes/REST/RestController.php:779`

## Related docs

- Entity pipeline behavior: `docs/architecture/entity-pipeline.md`
- Hook extension points: `docs/extensibility/hooks-and-filters.md`
- Option/meta keys: `docs/data-model/options-and-meta.md`
