# Knowledge Base API

> Docs home: `docs/index.md`

Base: `/wp-json/vibe-ai/v1/kb`

## Search and status

- `POST /search`
  - params: `query`, `top_k`, `filters`
  - filters support: `post_type[]`, `post_ids[]`, `exclude_ids[]`, `date_after`, `date_before`
- `GET /status`

## Document management

- `GET /docs`
  - params: `page`, `per_page`, `search`, `status`, `post_type`, `orderby`, `order`
- `GET /docs/{post_id}`
- `POST /docs/{post_id}/exclude`
- `POST /docs/{post_id}/include`
- `POST /docs/{post_id}/reindex`
- `POST /docs/exclude`
  - params: `post_ids[]`, `exclude` (true exclude, false include)

## Pipeline control

- `POST /reindex`
  - params: `post_types[]`, `force`
- `POST /stop`

## Chunks, logs, settings

- `GET /chunks/{chunk_id}`
- `GET /logs`
  - params: `level`, `limit`, `component` (`kb|embedding|chunking|all`)
- `GET /settings`
- `POST /settings`

## Public AI publishing endpoints

- `GET /llms-txt` (public)
  - params: `mode`, `include_descriptions`, `max_entries`
- `GET /sitemap` (public)
  - params: `format` (`json|xml`)
- `GET /feed` (public)
  - params: `since`, `limit`

## Pinned pages config

- `GET /config/pinned-pages`
- `POST/PUT/PATCH /config/pinned-pages`
  - note: route is registered as `WP_REST_Server::EDITABLE`; use `PUT/PATCH` for idempotent updates
  - params: `post_ids[]`

## Source refs

- Routes: `includes/REST/KBController.php:150`
- Search callback: `includes/REST/KBController.php:804`
- Status callback: `includes/REST/KBController.php:886`
- Public AI publishing endpoints: `includes/REST/KBController.php:452`, `includes/REST/KBController.php:483`, `includes/REST/KBController.php:501`

## Related docs

- KB pipeline behavior: `docs/architecture/kb-pipeline.md`
- Data schema: `docs/data-model/schema.md`
- Operations and recovery: `docs/operations/recovery-playbooks.md`
