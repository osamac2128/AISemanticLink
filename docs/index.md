# AI Entity Index - Internal Documentation

> Legacy spec archive: `docs/changelog/internal-history.md`

This is the canonical developer documentation set for the plugin.

## What this plugin does

AI Entity Index is a WordPress plugin that builds two parallel AI-facing systems:

- Entity pipeline: extracts, normalizes, and links named entities from content, then emits Schema.org JSON-LD.
- Knowledge Base pipeline: chunks content, generates embeddings, stores vectors, and exposes semantic search endpoints.

## Documentation map

- Getting started
  - `docs/getting-started/installation.md`
  - `docs/getting-started/configuration.md`
- Architecture
  - `docs/architecture/overview.md`
  - `docs/architecture/entity-pipeline.md`
  - `docs/architecture/kb-pipeline.md`
  - `docs/architecture/component-map.md`
- Data model
  - `docs/data-model/schema.md`
  - `docs/data-model/options-and-meta.md`
- API
  - `docs/api/rest-overview.md`
  - `docs/api/entities.md`
  - `docs/api/knowledge-base.md`
- Extensibility
  - `docs/extensibility/hooks-and-filters.md`
- Security
  - `docs/security/security-model.md`
- Operations
  - `docs/operations/logging-monitoring.md`
  - `docs/operations/performance-and-scaling.md`
  - `docs/operations/recovery-playbooks.md`
- Development
  - `docs/development/local-setup.md`
  - `docs/development/build-test-release.md`
- Changelog / drift tracking
  - `docs/changelog/internal-history.md`
  - `docs/changelog/implementation-drift.md`

## Source of truth

Use these files as canonical implementation references when updating docs:

- Bootstrap and lifecycle: `ai-entity-index.php`, `includes/Plugin.php`, `includes/Activator.php`
- Configuration: `includes/Config.php`
- Entity pipeline: `includes/Pipeline/PipelineManager.php`, `includes/Jobs/*.php`
- KB pipeline: `includes/Pipeline/KBPipelineManager.php`, `includes/Jobs/KB/*.php`
- REST: `includes/REST/RestController.php`, `includes/REST/KBController.php`

## Documentation maintenance

- Keep `docs/claude.md` as a short pointer file and keep legacy narrative content archived in `docs/changelog/internal-history.md`
- For any behavior/API/schema update, edit the canonical page under `docs/` and add or refresh `Source refs` with concrete file+line anchors
- Treat implementation as authoritative when docs and code diverge; do not infer behavior from old docs without checking source files
- Record known mismatches in `docs/changelog/implementation-drift.md` with impact, current state, and planned resolution
- When REST endpoints change, update both `docs/api/*.md` and architecture pages that describe trigger points (`docs/architecture/*.md`)

### Update workflow

1. Identify changed implementation files (controllers, pipeline managers, services, config).
2. Update the most specific docs first (API/data model/architecture), then update `docs/index.md` if navigation changed.
3. Add or refresh `Source refs` and `Related docs` in touched pages.
4. Add a drift entry in `docs/changelog/implementation-drift.md` if code and published metadata/docs intentionally differ.
