# Database Schema

> Docs home: `docs/index.md`

Source: `includes/Activator.php`, `includes/Config.php`

## Entity tables

- `wp_ai_entities`
  - canonical entity record (name/slug/type/schema/status/counts)
  - unique: `slug`
- `wp_ai_mentions`
  - edge table between entity and post
  - unique: `(entity_id, post_id)`
- `wp_ai_aliases`
  - alias-to-canonical mapping
  - unique: `alias_slug`

## Knowledge Base tables

- `wp_ai_kb_docs`
  - indexed post document metadata
  - unique: `post_id`
- `wp_ai_kb_chunks`
  - content chunks per document
  - unique: `(doc_id, anchor)`
- `wp_ai_kb_vectors`
  - embedding payload per chunk
  - unique: `chunk_id`

## Foreign keys

Configured post-create in `Activator`:

- mentions -> entities (`ON DELETE CASCADE`)
- mentions -> posts (`ON DELETE CASCADE`)
- aliases -> entities (`ON DELETE CASCADE`)
- kb_docs -> posts (`ON DELETE CASCADE`)
- kb_chunks -> kb_docs (`ON DELETE CASCADE`)
- kb_vectors -> kb_chunks (`ON DELETE CASCADE`)

## Notes for maintainers

- `dbDelta` handles baseline creation; FKs and some indexes are added separately.
- Upgrade path in `Activator::maybeUpgrade()` also runs KB legacy column/index migration.
