# Component Map

> Docs home: `docs/index.md`

## Bootstrap and lifecycle

- `ai-entity-index.php`: plugin headers, bootstrap checks, hooks, activation/deactivation wiring.
- `includes/Plugin.php`: central hook registration and integration wiring.
- `includes/Activator.php`: schema creation, upgrades, uninstall logic.

## Core services

- `includes/Services/AIClient.php`: OpenRouter chat-completions transport.
- `includes/Services/EntityExtractor.php`: extraction prompting + parser.
- `includes/Services/SchemaGenerator.php`: JSON-LD generation.
- `includes/Services/SchemaInjector.php`: front-end schema output.
- `includes/Services/BatchSizeManager.php`: adaptive throughput control.
- `includes/Services/CacheInvalidator.php`: schema cache invalidation and propagation coordination.

## Repositories

- Entity domain:
  - `includes/Repositories/EntityRepository.php`
  - `includes/Repositories/MentionRepository.php`
- KB domain:
  - `includes/Repositories/KB/DocumentRepository.php`
  - `includes/Repositories/KB/ChunkRepository.php`
  - `includes/Repositories/KB/VectorRepository.php`

## REST controllers

- `includes/REST/RestController.php`: entity/pipeline/logs/settings endpoints.
- `includes/REST/KBController.php`: KB endpoints, semantic search, AI publishing.

## Frontend admin app

- Entry: `admin/js/src/index.jsx`
- App shell: `admin/js/src/App.jsx`
- Hooks: `admin/js/src/hooks/*.js`
- Built assets: `admin/js/build/*`

## Source refs

- Bootstrap and lifecycle wiring: `ai-entity-index.php:102`, `ai-entity-index.php:116`, `ai-entity-index.php:136`, `includes/Plugin.php:40`
- REST controller registration: `includes/Plugin.php:81`, `includes/Plugin.php:85`, `includes/Plugin.php:90`
- Entity pipeline orchestration: `includes/Pipeline/PipelineManager.php:22`, `includes/Pipeline/PipelineManager.php:53`, `includes/Pipeline/PipelineManager.php:441`
- KB pipeline orchestration: `includes/Pipeline/KBPipelineManager.php:34`, `includes/Pipeline/KBPipelineManager.php:74`, `includes/Pipeline/KBPipelineManager.php:750`
- REST controllers and permission model: `includes/REST/RestController.php:77`, `includes/REST/KBController.php:144`, `includes/Config.php:256`, `includes/Config.php:259`
- Core service implementation anchors: `includes/Services/AIClient.php:19`, `includes/Services/EntityExtractor.php:17`, `includes/Services/SchemaGenerator.php:18`, `includes/Services/SchemaInjector.php:16`, `includes/Services/BatchSizeManager.php:16`

## Related docs

- System-level architecture: `docs/architecture/overview.md`
- Entity execution flow: `docs/architecture/entity-pipeline.md`
- KB execution flow: `docs/architecture/kb-pipeline.md`
- API surface and route groups: `docs/api/rest-overview.md`
