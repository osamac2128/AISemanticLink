# Architecture Overview

> Docs home: `docs/index.md`

## System shape

The plugin has two asynchronous processing systems sharing common infrastructure:

- Entity extraction pipeline for semantic truth and JSON-LD.
- Knowledge Base pipeline for chunking, vectors, and semantic retrieval.

Both use Action Scheduler jobs and WordPress options for state/progress.

## Core layers

- Trigger layer
  - REST controls
  - `save_post` hooks
  - scheduled actions
- Orchestration layer
  - `PipelineManager` (entity)
  - `KBPipelineManager` (KB)
- Job layer
  - `includes/Jobs/*.php`
  - `includes/Jobs/KB/*.php`
- Data layer
  - custom plugin tables
  - `wp_postmeta`
  - `wp_options`
- Delivery layer
  - REST responses
  - `wp_head` JSON-LD injection

## Bootstrap flow

1. `ai-entity-index.php` validates PHP version and autoload.
2. Action Scheduler is loaded.
3. `Vibe\AIIndex\Plugin` is instantiated and `run()` is called.
4. Plugin registers:
   - admin renderer
   - REST routes
   - public hooks (`wp_head`, post hooks)
   - scheduler hooks
   - KB hooks (only when enabled)

## Source refs

- Bootstrap and plugin init: `ai-entity-index.php:38`, `ai-entity-index.php:87`, `ai-entity-index.php:102`, `includes/Plugin.php:40`
- Route/controller wiring: `includes/Plugin.php:81`, `includes/Plugin.php:85`, `includes/Plugin.php:90`
- Public and scheduler hooks: `includes/Plugin.php:104`, `includes/Plugin.php:115`, `includes/Plugin.php:131`, `includes/Plugin.php:166`
- Entity pipeline state/options: `includes/Pipeline/PipelineManager.php:28`, `includes/Pipeline/PipelineManager.php:33`, `includes/Pipeline/PipelineManager.php:38`
- KB pipeline state/options: `includes/Pipeline/KBPipelineManager.php:39`, `includes/Pipeline/KBPipelineManager.php:44`, `includes/Pipeline/KBPipelineManager.php:49`

## Related docs

- Entity execution details: `docs/architecture/entity-pipeline.md`
- KB execution details: `docs/architecture/kb-pipeline.md`
- Class-to-file ownership map: `docs/architecture/component-map.md`
- REST surface area: `docs/api/rest-overview.md`
