# Hooks and Filters

> Docs home: `docs/index.md`

## Important actions

- Entity pipeline lifecycle:
  - `vibe_ai_pipeline_started`
  - `vibe_ai_pipeline_phase_changed`
  - `vibe_ai_pipeline_completed`
  - `vibe_ai_pipeline_failed`
- Entity domain:
  - `vibe_ai_entities_extracted`
  - `vibe_ai_entity_updated`
  - `vibe_ai_entity_propagation_complete`
- KB pipeline lifecycle:
  - `vibe_ai_kb_pipeline_started`
  - `vibe_ai_kb_pipeline_phase_changed`
  - `vibe_ai_kb_pipeline_completed`
  - `vibe_ai_kb_pipeline_failed`

## Important filters

- Entity extraction:
  - `vibe_ai_post_types`
  - `vibe_ai_confidence_threshold`
  - `vibe_ai_system_prompt`
  - `vibe_ai_extracted_entities`
- Schema:
  - `vibe_ai_schema_json`
- KB:
  - `vibe_ai_kb_post_types`
  - `vibe_ai_kb_should_index_post`
  - `vibe_ai_kb_content`
  - `vibe_ai_kb_chunks`
  - `vibe_ai_kb_search_results`

## Implementation guidance

- Treat hooks as extension points, not stable contracts unless documented here.
- For prompt customization, avoid weakening extraction rules; ensure changes are deterministic.
- For heavy hooks, keep callback time low to avoid impacting scheduler throughput.
