# Changelog

All notable changes to AI Entity Index will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.8] - 2026-04-10

### Fixed

- **Critical**: Entity pipeline jobs (Extraction, Resolution, Enrichment, Linking, Validation, Deduplication) and PropagateEntityChangeJob were never wired to Action Scheduler — their `register()` methods were never called
- **Critical**: `ExtractionJob` called non-existent `$extractor->extract()` — fatal PHP error on every run; fixed to `$extractor->extract_from_content()`
- **High**: `LinkingJob` read from wrong transient (`vibe_ai_extracted_entities_backup`, never written) while `DeduplicationJob` deleted the correct one before it ran — all confidence scores defaulted to 0.5, all context snippets were empty
- **High**: Frontend admin UI used `window.vibeAI` (undefined) instead of `window.vibeAiData` — half the admin UI failed with auth errors
- **Medium**: KB pipeline double-scheduled phases — each job both directly scheduled the next phase AND fired a completion action that triggered the same schedule
- **Medium**: `POST /settings` endpoint always returned `{ success: true }` without reading or writing any settings
- **Medium**: `SchemaInjector::inject()` never checked `is_enabled()` — disabling schema injection had no effect
- **Medium**: `SimilaritySearch::findSimilarToPost()` built `$excludeFilters` but passed original `$filters` to `searchWithVector()`
- **Medium**: KB Documents exclude/include toggle handlers in admin UI just logged to console
- **Medium**: `useKB.js` set `Content-Type: application/json` on GET requests, triggering ModSecurity 403s
- **Medium**: Dead `EntityDrawer` in `App.jsx` was never reachable (EntityManager had its own)
- **Medium**: Dead files `RestApi.php` and `useEntities.js` removed
- **Low**: `$wpdb` transaction safety — `START TRANSACTION` + `try/catch` doesn't catch `$wpdb` errors; fixed to check `$wpdb->last_error`
- **Low**: Missing `register_uninstall_hook()` in bootstrap
- **Low**: `ChunkBuildJob` `heading_path_json` was always empty — inline paragraph-based chunking never extracted HTML headings

### Improved

- Frontend API functions centralized — 6 components no longer duplicate fetch/nonce/error-handling logic
- `ChunkBuildJob` refactored to use the dedicated `Chunker` service instead of inline paragraph-based chunking

## [1.0.7] - 2026-04-10

### Fixed

- React QueryClientProvider wrapping for all routes
- REST controller architecture (RestController + KBController replacing RestApi)
- JSON parsing robustness (markdown fence stripping)
- Circuit breaker and rate limit handling

### Added

- Settings and logs REST endpoints
- Debug logging infrastructure across all components

## [1.0.6] - 2026-03-15

### Fixed

- Entity propagation using direct option lookup instead of LIKE query
- Transaction safety in EntityRepository link_mention

### Improved

- Admin UI component stability
- Error handling in extraction pipeline

## [1.0.5] - 2026-03-01

### Added

- Knowledge Base pipeline (5 phases)
- Semantic search API with vector similarity
- AI publishing endpoints (llms.txt, AI sitemap, change feed)
- KB admin UI (Overview, Documents, Test Search, Settings, Logs)
- MySQL vector storage with VectorStoreInterface adapter pattern

## [1.0.4] - 2026-02-15

### Fixed

- 20k character content truncation in EntityExtractor
- AIClient timeout reduced to 15 seconds

### Improved

- Dynamic batch sizing with BatchSizeManager
- Schema.org JSON-LD generation

## [1.0.3] - 2026-02-08

### Fixed

- License declarations consistency (proprietary)
- REST method surface documentation

### Improved

- Prompt injection protection with capability check
- Admin UI refactored (AdminRenderer separation)

## [1.0.2] - 2026-02-01

### Added

- Entity merge functionality
- Bulk entity operations
- Entity drawer with inline editing

### Improved

- Admin table with TanStack React Table

## [1.0.1] - 2026-01-20

### Fixed

- Plugin activation on PHP < 8.1 (now shows error)
- Log directory protection (.htaccess + index.php)

### Added

- Daily cleanup cron for old log files

### Improved

- Admin sidebar navigation

## [1.0.0] - 2026-01-10

### Added

- 6-phase entity extraction pipeline
- 10 entity types with Schema.org mapping
- OpenRouter AI integration (Claude models)
- Action Scheduler background processing
- React admin UI with Dashboard, Entity Manager, Settings
- Schema.org JSON-LD injection
- Chain-link cache invalidation
- Alias resolution and deduplication
