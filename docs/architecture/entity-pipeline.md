# Entity Pipeline

> Docs home: `docs/index.md`

## Purpose

Build canonical entities and mention edges from WordPress content, then generate per-post JSON-LD caches.

## Orchestrator

- Class: `includes/Pipeline/PipelineManager.php`
- State storage: `vibe_ai_pipeline_*` options
- Statuses: `idle`, `running`, `paused`, `completed`, `failed`

## Phases

1. `preparation`
2. `extraction`
3. `deduplication`
4. `linking`
5. `indexing`
6. `schema_build`

Each phase is scheduled as an Action Scheduler job (`vibe_ai_phase_{phase}`).

## Trigger points

- Manual start/stop via REST (`/pipeline/start`, `/pipeline/stop`).
- Content update path: `save_post` sets `_vibe_ai_needs_extraction` and invalidates schema cache.
- Entity update path: schema-impacting changes trigger propagation actions.

## Notable behaviors

- Dynamic batch sizing (`includes/Services/BatchSizeManager.php`) adjusts between 5 and 50 based on processing duration.
- Entity extraction trims post content to 20k chars before LLM calls (`EntityExtractor::prepare_content`).
- Response parser strips markdown code fences before `json_decode` in `EntityExtractor::parse_ai_response`.
- Prompt override filter (`vibe_ai_system_prompt`) is capability-gated before applying external filters.

## Source refs

- Phases and status model: `includes/Pipeline/PipelineManager.php:53`, `includes/Pipeline/PipelineManager.php:65`, `includes/Pipeline/PipelineManager.php:77`
- State options and status payload: `includes/Pipeline/PipelineManager.php:28`, `includes/Pipeline/PipelineManager.php:33`, `includes/Pipeline/PipelineManager.php:209`
- Phase scheduling: `includes/Pipeline/PipelineManager.php:171`, `includes/Pipeline/PipelineManager.php:450`, `includes/Pipeline/PipelineManager.php:454`
- REST trigger points: `includes/REST/RestController.php:240`, `includes/REST/RestController.php:264`, `includes/REST/RestController.php:272`
- `save_post` and extraction flagging: `includes/Plugin.php:131`, `includes/Plugin.php:205`, `includes/Plugin.php:209`
- Entity propagation trigger path: `includes/REST/RestController.php:849`, `includes/Plugin.php:238`, `includes/Plugin.php:245`
- Dynamic batch sizing bounds: `includes/Services/BatchSizeManager.php:21`, `includes/Services/BatchSizeManager.php:26`, `includes/Services/BatchSizeManager.php:90`
- Content truncation and parser cleanup: `includes/Services/EntityExtractor.php:364`, `includes/Services/EntityExtractor.php:370`, `includes/Services/EntityExtractor.php:430`, `includes/Services/EntityExtractor.php:456`
- Prompt override gating: `includes/Services/EntityExtractor.php:408`, `includes/Services/EntityExtractor.php:409`, `includes/Services/EntityExtractor.php:414`

## Related docs

- Entity REST endpoints: `docs/api/entities.md`
- Option/meta keys for pipeline state and cache: `docs/data-model/options-and-meta.md`
- Hook contracts: `docs/extensibility/hooks-and-filters.md`
- Operational behavior and tuning: `docs/operations/performance-and-scaling.md`
