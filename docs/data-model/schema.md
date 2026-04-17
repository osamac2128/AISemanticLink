# Database Schema

> Docs home: `docs/index.md`

Source: `includes/Activator.php`, `includes/Config.php`

The AI Entity Index plugin creates **six custom tables** in the WordPress database, prefixed with the site's `$wpdb->prefix` (documented here as `wp_`). Tables are split into two logical groups: **Entity tables** (entity extraction, linking, and Schema.org output) and **Knowledge Base tables** (document chunking and vector storage for semantic search).

Schema creation is handled by `dbDelta` for baseline DDL. Foreign keys and certain indexes are applied via separate `ALTER TABLE` statements after `dbDelta` completes, because `dbDelta` does not support `FOREIGN KEY` or `UNIQUE KEY` on InnoDB in all WordPress versions.

---

## Schema Version Tracking

| Constant / Option | Value | Purpose |
|-------------------|-------|---------|
| `Config::DB_VERSION` | `'1.1.0'` | Code-defined schema version |
| `vibe_ai_db_version` (wp_options) | `'1.1.0'` | Persisted schema version |

On every admin request, `Activator::maybeUpgrade()` compares the stored option against `Config::DB_VERSION`. If the code version is higher, the upgrade routine runs and the option is updated. This mechanism supports both fresh installs and incremental migrations.

### Migration Functions

| Function | Purpose |
|----------|---------|
| `Activator::maybeUpgrade()` | Compares `vibe_ai_db_version` option to `Config::DB_VERSION`; runs `activate()` if fresh install, or runs targeted migrations if upgrading |
| `Activator::migrateKBLegacyColumns()` | Adds missing columns/indexes to KB tables from pre-1.1.0 installs. Specifically: adds `idx_last_indexed_at` index on `wp_ai_kb_docs.last_indexed_at` and `idx_doc_chunk_index` composite index on `wp_ai_kb_chunks(doc_id, chunk_index)` |

---

## Entity-Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         WordPress Core                                  │
│                     ┌──────────────┐                                    │
│                     │  wp_posts    │                                    │
│                     │  ID (PK)     │                                    │
│                     └──────┬───────┘                                    │
│                            │                                            │
│           ┌────────────────┼─────────────────┐                          │
│           │ CASCADE        │ CASCADE         │ CASCADE                  │
│           ▼                ▼                 ▼                          │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐                  │
│  │wp_ai_mentions│  │ wp_ai_kb_docs│  │ (post meta   │                  │
│  │ entity_id FK │  │ post_id FK   │  │  keys)       │                  │
│  │ post_id FK ──┼──┼─►            │  └──────────────┘                  │
│  └──────┬───────┘  └──────┬───────┘                                    │
│         │                 │                                             │
│         │ CASCADE         │ CASCADE                                     │
│         ▼                 ▼                                             │
│  ┌──────────────┐  ┌──────────────────┐                                │
│  │wp_ai_entities│  │ wp_ai_kb_chunks  │                                │
│  │ id (PK)      │  │ doc_id FK        │                                │
│  └──────┬───────┘  └──────┬───────────┘                                │
│         │                 │                                             │
│         │ CASCADE         │ CASCADE                                     │
│         ▼                 ▼                                             │
│  ┌──────────────┐  ┌──────────────────┐                                │
│  │wp_ai_aliases │  │ wp_ai_kb_vectors │                                │
│  │ canonical_id │  │ chunk_id FK      │                                │
│  └──────────────┘  └──────────────────┘                                │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘

Legend:
  FK     = Foreign Key
  PK     = Primary Key
  CASCADE = ON DELETE CASCADE (deleting parent row removes children)
```

---

## Table: `wp_ai_entities`

Canonical entity records. Each row represents a unique real-world entity (person, organization, concept, etc.) discovered by the AI extraction pipeline.

| Column | MySQL Type | Nullable | Default | Constraints | Description |
|--------|-----------|----------|---------|-------------|-------------|
| `id` | `bigint(20) unsigned` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Unique entity identifier |
| `name` | `varchar(255)` | NO | — | `NOT NULL` | Human-readable display name (e.g. "Sam Altman") |
| `slug` | `varchar(255)` | NO | — | `UNIQUE`, `NOT NULL` | URL-safe identifier, sluggified from `name` |
| `type` | `varchar(50)` | NO | `'CONCEPT'` | — | Internal entity type (see Entity Types table below) |
| `schema_type` | `varchar(100)` | NO | `'Thing'` | — | Schema.org type mapping for JSON-LD output |
| `description` | `text` | YES | `NULL` | — | Optional entity description |
| `same_as_url` | `varchar(2048)` | YES | `NULL` | — | External reference URL (Wikipedia, Wikidata, etc.) |
| `wikidata_id` | `varchar(50)` | YES | `NULL` | — | Wikidata entity ID (e.g. "Q3107329") |
| `status` | `varchar(20)` | NO | `'raw'` | — | Entity lifecycle: `raw`, `approved`, `rejected` |
| `mention_count` | `int unsigned` | NO | `0` | — | Denormalized count of linked mentions |
| `created_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | — | Row creation timestamp |
| `updated_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | — | Last modification timestamp |

### Indexes

| Index Name | Type | Columns |
|-----------|------|---------|
| `PRIMARY` | Primary Key | `id` |
| `idx_slug` | Unique | `slug` |
| `idx_type` | Regular | `type` |
| `idx_status` | Regular | `status` |
| `idx_mention_count` | Regular | `mention_count` |

---

## Table: `wp_ai_mentions`

Edge table linking entities to posts. Each row records that a specific entity was detected in a specific post, along with confidence and context.

| Column | MySQL Type | Nullable | Default | Constraints | Description |
|--------|-----------|----------|---------|-------------|-------------|
| `id` | `bigint(20) unsigned` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Unique mention identifier |
| `entity_id` | `bigint(20) unsigned` | NO | — | `NOT NULL`, FK → `wp_ai_entities.id` | Referenced entity |
| `post_id` | `bigint(20) unsigned` | NO | — | `NOT NULL`, FK → `wp_posts.ID` | Source WordPress post |
| `confidence` | `float` | YES | `0.0` | — | AI confidence score (0.0–1.0) |
| `context_snippet` | `text` | YES | `NULL` | — | Surrounding text where entity was found |
| `is_primary` | `tinyint(1)` | NO | `0` | — | Whether this is the primary mention of the entity in the post |
| `created_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | — | When the mention was recorded |

### Indexes

| Index Name | Type | Columns |
|-----------|------|---------|
| `PRIMARY` | Primary Key | `id` |
| `idx_entity_post` | Unique | `entity_id, post_id` |
| `idx_post_id` | Regular | `post_id` |
| `idx_confidence` | Regular | `confidence` |

### Foreign Keys

| Constraint Name | Column | References | On Delete |
|----------------|--------|-----------|-----------|
| `fk_mentions_entity` | `entity_id` | `wp_ai_entities(id)` | `CASCADE` |
| `fk_mentions_post` | `post_id` | `wp_posts(ID)` | `CASCADE` |

---

## Table: `wp_ai_aliases`

Maps alternate names (aliases) to their canonical entity. Enables the system to recognize that "OpenAI" and "OpenAI Inc." refer to the same entity.

| Column | MySQL Type | Nullable | Default | Constraints | Description |
|--------|-----------|----------|---------|-------------|-------------|
| `id` | `bigint(20) unsigned` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Unique alias identifier |
| `canonical_id` | `bigint(20) unsigned` | NO | — | `NOT NULL`, FK → `wp_ai_entities.id` | Parent canonical entity |
| `alias` | `varchar(255)` | NO | — | `NOT NULL` | Alternate name text |
| `alias_slug` | `varchar(255)` | NO | — | `UNIQUE`, `NOT NULL` | URL-safe sluggified alias |
| `source` | `varchar(50)` | NO | `'ai'` | — | Origin of the alias: `ai`, `user`, `import` |
| `created_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | — | When the alias was created |

### Indexes

| Index Name | Type | Columns |
|-----------|------|---------|
| `PRIMARY` | Primary Key | `id` |
| `idx_alias_slug` | Unique | `alias_slug` |
| `idx_canonical` | Regular | `canonical_id` |

### Foreign Keys

| Constraint Name | Column | References | On Delete |
|----------------|--------|-----------|-----------|
| `fk_alias_canonical` | `canonical_id` | `wp_ai_entities(id)` | `CASCADE` |

---

## Table: `wp_ai_kb_docs`

Tracks WordPress posts/pages that have been indexed into the Knowledge Base. Each row represents a document that has been chunked and optionally vectorized.

| Column | MySQL Type | Nullable | Default | Constraints | Description |
|--------|-----------|----------|---------|-------------|-------------|
| `id` | `bigint(20) unsigned` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Unique document identifier |
| `post_id` | `bigint(20) unsigned` | NO | — | `UNIQUE`, `NOT NULL`, FK → `wp_posts.ID` | Source WordPress post |
| `post_type` | `varchar(50)` | NO | — | `NOT NULL` | WordPress post type (post, page, product, etc.) |
| `title` | `varchar(255)` | NO | — | `NOT NULL` | Document title at time of indexing |
| `url` | `varchar(2048)` | NO | — | `NOT NULL` | Permalink at time of indexing |
| `content_hash` | `varchar(64)` | NO | — | `NOT NULL` | SHA-256 hash of post content; used for change detection |
| `chunk_count` | `int unsigned` | NO | `0` | — | Number of chunks generated from this document |
| `status` | `varchar(20)` | NO | `'pending'` | — | Indexing status: `pending`, `indexed`, `failed` |
| `last_indexed_at` | `datetime` | YES | `NULL` | — | Timestamp of most recent successful indexing |
| `created_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | — | Row creation timestamp |
| `updated_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | — | Last modification timestamp |

### Indexes

| Index Name | Type | Columns | Added By |
|-----------|------|---------|----------|
| `PRIMARY` | Primary Key | `id` | Initial creation |
| `idx_post_id` | Unique | `post_id` | Initial creation |
| `idx_status` | Regular | `status` | Initial creation |
| `idx_post_type` | Regular | `post_type` | Initial creation |
| `idx_content_hash` | Regular | `content_hash` | Initial creation |
| `idx_last_indexed_at` | Regular | `last_indexed_at` | `migrateKBLegacyColumns()` (v1.1.0) |

### Foreign Keys

| Constraint Name | Column | References | On Delete |
|----------------|--------|-----------|-----------|
| `fk_kb_docs_post` | `post_id` | `wp_posts(ID)` | `CASCADE` |

---

## Table: `wp_ai_kb_chunks`

Stores individual content chunks extracted from KB documents. Each chunk represents a semantically coherent portion of a document, anchored to its heading context.

| Column | MySQL Type | Nullable | Default | Constraints | Description |
|--------|-----------|----------|---------|-------------|-------------|
| `id` | `bigint(20) unsigned` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Unique chunk identifier |
| `doc_id` | `bigint(20) unsigned` | NO | — | `NOT NULL`, FK → `wp_ai_kb_docs.id` | Parent document |
| `chunk_index` | `int unsigned` | NO | — | `NOT NULL` | Sequential position of chunk within document (0-based) |
| `anchor` | `varchar(100)` | NO | — | `NOT NULL` | HTML anchor/heading ID for deep linking |
| `heading_path_json` | `text` | YES | `NULL` | — | JSON array of heading hierarchy leading to this chunk |
| `chunk_text` | `longtext` | NO | — | `NOT NULL` | Actual chunk content text |
| `chunk_hash` | `varchar(64)` | NO | — | `NOT NULL` | SHA-256 hash of `chunk_text` for dedup/change detection |
| `start_offset` | `int unsigned` | NO | `0` | — | Character offset where chunk begins in source content |
| `end_offset` | `int unsigned` | NO | `0` | — | Character offset where chunk ends in source content |
| `token_estimate` | `int unsigned` | NO | `0` | — | Estimated token count for the chunk |
| `created_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | — | Row creation timestamp |

### Indexes

| Index Name | Type | Columns | Added By |
|-----------|------|---------|----------|
| `PRIMARY` | Primary Key | `id` | Initial creation |
| `idx_doc_anchor` | Unique | `doc_id, anchor` | Initial creation |
| `idx_doc_id` | Regular | `doc_id` | Initial creation |
| `idx_chunk_hash` | Regular | `chunk_hash` | Initial creation |
| `idx_doc_chunk_index` | Regular | `doc_id, chunk_index` | `migrateKBLegacyColumns()` (v1.1.0) |

### Foreign Keys

| Constraint Name | Column | References | On Delete |
|----------------|--------|-----------|-----------|
| `fk_kb_chunks_doc` | `doc_id` | `wp_ai_kb_docs(id)` | `CASCADE` |

---

## Table: `wp_ai_kb_vectors`

Stores embedding vectors for KB chunks. Each chunk has one vector per (provider, model) combination. The raw binary vector is stored as a `longblob`.

| Column | MySQL Type | Nullable | Default | Constraints | Description |
|--------|-----------|----------|---------|-------------|-------------|
| `id` | `bigint(20) unsigned` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Unique vector identifier |
| `chunk_id` | `bigint(20) unsigned` | NO | — | `UNIQUE`, `NOT NULL`, FK → `wp_ai_kb_chunks.id` | Parent chunk |
| `provider` | `varchar(50)` | NO | `'openrouter'` | — | Embedding API provider name |
| `model` | `varchar(100)` | NO | — | `NOT NULL` | Full model identifier (e.g. "text-embedding-3-small") |
| `dims` | `int unsigned` | YES | `NULL` | — | Vector dimensionality (e.g. 1536, 3072) |
| `vector_payload` | `longblob` | NO | — | `NOT NULL` | Serialized embedding vector binary data |
| `created_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | — | When the embedding was generated |

### Indexes

| Index Name | Type | Columns |
|-----------|------|---------|
| `PRIMARY` | Primary Key | `id` |
| `idx_chunk_id` | Unique | `chunk_id` |
| `idx_model` | Regular | `model` |

### Foreign Keys

| Constraint Name | Column | References | On Delete |
|----------------|--------|-----------|-----------|
| `fk_kb_vectors_chunk` | `chunk_id` | `wp_ai_kb_chunks(id)` | `CASCADE` |

---

## Foreign Key Summary

All foreign keys use `ON DELETE CASCADE`, ensuring that deleting a WordPress post or a parent entity/document automatically cleans up all child rows. This prevents orphaned data without requiring application-level cleanup logic.

```
wp_ai_mentions.entity_id  ──▶  wp_ai_entities.id       (fk_mentions_entity,   CASCADE)
wp_ai_mentions.post_id    ──▶  wp_posts.ID             (fk_mentions_post,     CASCADE)
wp_ai_aliases.canonical_id──▶  wp_ai_entities.id       (fk_alias_canonical,   CASCADE)
wp_ai_kb_docs.post_id     ──▶  wp_posts.ID             (fk_kb_docs_post,      CASCADE)
wp_ai_kb_chunks.doc_id    ──▶  wp_ai_kb_docs.id        (fk_kb_chunks_doc,     CASCADE)
wp_ai_kb_vectors.chunk_id ──▶  wp_ai_kb_chunks.id      (fk_kb_vectors_chunk,  CASCADE)
```

> **Note:** Foreign keys are created via `ALTER TABLE ... ADD CONSTRAINT` after `dbDelta` runs, because `dbDelta` does not reliably handle `FOREIGN KEY` clauses across all supported WordPress/MySQL versions.

---

## Entity Types

The `type` column in `wp_ai_entities` uses a fixed set of internal type strings, each mapped to a Schema.org type stored in the `schema_type` column. The default is `CONCEPT` / `Thing`.

| Internal Type | Schema.org Type | Example | Description |
|---------------|-----------------|---------|-------------|
| `PERSON` | `Person` | Sam Altman | Individual human beings |
| `ORG` | `Organization` | OpenAI | Organizations, institutions, agencies |
| `COMPANY` | `Corporation` | Microsoft | For-profit corporate entities |
| `LOCATION` | `Place` | San Francisco | Geographic locations (cities, regions, landmarks) |
| `COUNTRY` | `Country` | United States | Sovereign nations |
| `PRODUCT` | `Product` | iPhone | Physical or digital products |
| `SOFTWARE` | `SoftwareApplication` | WordPress | Software applications and platforms |
| `EVENT` | `Event` | WWDC 2025 | Named events, conferences, happenings |
| `WORK` | `CreativeWork` | The Great Gatsby | Books, articles, creative productions |
| `CONCEPT` | `Thing` | Machine Learning | Abstract concepts, topics, technologies |

---

## Confidence Thresholds

The `confidence` column in `wp_ai_mentions` determines how each entity-post link is handled by the pipeline. Thresholds are configurable via the `vibe_ai_confidence_threshold` option (default `0.60`).

| Tier | Range | Schema.org Output | Review Queue | Storage | Description |
|------|-------|-------------------|--------------|---------|-------------|
| **High** | 0.85 – 1.0 | Included in JSON-LD | No | Stored | Auto-approved, high-confidence matches |
| **Medium** | 0.60 – 0.84 | Included in JSON-LD | Yes (flagged) | Stored | Included in output but flagged for human review |
| **Low** | 0.40 – 0.59 | Excluded from JSON-LD | No | Stored | Retained in database for analytics but hidden from public Schema |
| **Reject** | 0.0 – 0.39 | Excluded | No | Discarded | Below minimum threshold; not stored at all |

> The `vibe_ai_confidence_threshold` option controls the boundary between "Reject" and "Low". The boundary between "Low" and "Medium" is the same value. The "High" tier boundary is fixed at 0.85.

---

## Notes for Maintainers

- **`dbDelta`** handles baseline table creation on plugin activation. Foreign keys and certain indexes are added separately via `ALTER TABLE` statements.
- **Upgrade path** in `Activator::maybeUpgrade()` compares `vibe_ai_db_version` to `Config::DB_VERSION` and runs targeted migrations when the code version is higher.
- **`migrateKBLegacyColumns()`** handles pre-1.1.0 upgrades, adding the `idx_last_indexed_at` and `idx_doc_chunk_index` indexes that were introduced in v1.1.0.
- **All tables use InnoDB** to support foreign key constraints. MyISAM tables cannot enforce referential integrity.
- **The `wp_` prefix** is dynamic and uses `$wpdb->prefix`. The actual table names are accessed via `Config::getTableName('entities')`, `Config::getTableName('mentions')`, etc.
- **CASCADE behavior** means deleting a WordPress post automatically removes all associated mentions, KB docs, chunks, and vectors. Deleting an entity removes all its mentions and aliases.

---

## Source Refs

| File | Relevant Symbols |
|------|-----------------|
| `includes/Activator.php` | `activate()`, `maybeUpgrade()`, `migrateKBLegacyColumns()`, table DDL, FK creation |
| `includes/Config.php` | `DB_VERSION`, `SCHEMA_CACHE_VERSION`, `getTableName()`, entity type constants |
