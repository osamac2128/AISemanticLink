# Implementation Drift Register

> Docs home: `docs/index.md`

Tracks known mismatches between implementation, package metadata, and docs.

## 2026-02-08

### License declarations were inconsistent (resolved)

- Canonical license is now consistently documented as proprietary across metadata and docs:
  - `ai-entity-index.php:11`
  - `composer.json:5`
  - `README.md:130`
  - `readme.txt:8`

Resolution details:

- Updated plugin header license fields in `ai-entity-index.php`:
  - `License: Proprietary`
  - `License URI: LICENSE`

### REST method surface vs human-readable docs (resolved)

- Entity update route is `WP_REST_Server::EDITABLE` (accepts `POST/PUT/PATCH`): `includes/REST/RestController.php:127`
- Entity settings route is `WP_REST_Server::EDITABLE` (accepts `POST/PUT/PATCH`): `includes/REST/RestController.php:327`
- KB pinned-pages route is `WP_REST_Server::EDITABLE` (accepts `POST/PUT/PATCH`): `includes/REST/KBController.php:533`

Resolution details:

- `docs/api/entities.md` now lists `PUT/PATCH` for entity updates and `POST/PUT/PATCH` for settings with WordPress compatibility notes.
- `docs/api/knowledge-base.md` now lists `POST/PUT/PATCH` for pinned-pages updates and recommends `PUT/PATCH` for idempotent updates.

### Admin UI global variable naming inconsistency (resolved 2026-04-10)

- All component references standardized on `window.vibeAiData` (matching `AdminRenderer.php`)
- Inline API functions in 6 components replaced with centralized `api/client.js` imports
- Affected files: `EntityManager/index.jsx`, `EntityDrawer/index.jsx`, `Settings/index.jsx`, `ActivityLog/index.jsx`, `EntityDrawer/MentionsSection.jsx`, `EntityManager/MergeModal.jsx`
