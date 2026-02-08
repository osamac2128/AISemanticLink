# Knowledge Base Pipeline

> Docs home: `docs/index.md`

## Purpose

Index site content for semantic retrieval (RAG): normalize content, chunk, embed, store vectors, and expose search.

## Orchestrator

- Class: `includes/Pipeline/KBPipelineManager.php`
- State options: `vibe_ai_kb_pipeline_*`
- Scheduler group: `vibe-ai-kb`

## Phases

1. `kb_document_build`
2. `kb_chunk_build`
3. `kb_embed_chunks`
4. `kb_index_upsert`
5. `kb_cleanup`

## Trigger points

- Full run: `POST /wp-json/vibe-ai/v1/kb/reindex`
- Single post: `schedulePost()` from save hooks and explicit `POST /kb/docs/{post_id}/reindex`
- Stop: `POST /kb/stop`

## Notable behaviors

- Duplicate scheduling checks use `as_next_scheduled_action` before queueing.
- `OPTION_STOP_REQUESTED` provides cooperative stop signaling.
- Semantic search endpoint (`/kb/search`) uses `SimilaritySearch + EmbeddingClient + VectorStore`.
- Public AI publishing endpoints are available for machine crawlers:
  - `/kb/llms-txt`
  - `/kb/sitemap`
  - `/kb/feed`

## Source refs

- Phase sequence and scheduler group: `includes/Pipeline/KBPipelineManager.php:74`, `includes/Pipeline/KBPipelineManager.php:85`, `includes/Pipeline/KBPipelineManager.php:101`
- State options and stop flag: `includes/Pipeline/KBPipelineManager.php:39`, `includes/Pipeline/KBPipelineManager.php:44`, `includes/Pipeline/KBPipelineManager.php:49`, `includes/Pipeline/KBPipelineManager.php:69`
- Start/stop behavior: `includes/Pipeline/KBPipelineManager.php:184`, `includes/Pipeline/KBPipelineManager.php:221`, `includes/Pipeline/KBPipelineManager.php:245`, `includes/Pipeline/KBPipelineManager.php:696`
- Single-post scheduling and duplicate suppression: `includes/Pipeline/KBPipelineManager.php:497`, `includes/Pipeline/KBPipelineManager.php:508`, `includes/Pipeline/KBPipelineManager.php:522`
- `save_post`/`delete_post` hooks: `includes/Pipeline/KBPipelineManager.php:166`, `includes/Pipeline/KBPipelineManager.php:656`, `includes/Pipeline/KBPipelineManager.php:672`
- REST trigger points: `includes/REST/KBController.php:316`, `includes/REST/KBController.php:337`, `includes/REST/KBController.php:363`
- Semantic search stack: `includes/REST/KBController.php:804`, `includes/REST/KBController.php:823`, `includes/REST/KBController.php:829`
- Public AI publishing endpoints: `includes/REST/KBController.php:452`, `includes/REST/KBController.php:483`, `includes/REST/KBController.php:501`

## Related docs

- KB REST endpoints: `docs/api/knowledge-base.md`
- Storage schema for docs/chunks/vectors: `docs/data-model/schema.md`
- Recovery procedures and stop/restart flow: `docs/operations/recovery-playbooks.md`
- Hook contracts and extension points: `docs/extensibility/hooks-and-filters.md`
