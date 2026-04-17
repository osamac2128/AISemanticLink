# Migration Guide

> Docs home: `docs/index.md`

How to upgrade AI Entity Index, what the automatic migration process does, and how to roll back if something goes wrong.

---

## Upgrade Process Overview

AI Entity Index uses an automatic, non-destructive migration system. Upgrades follow this sequence:

1. **Upload new plugin files** — via WordPress plugin updater, FTP, or WP-CLI.
2. **WordPress loads the new code** — on the next admin page load, `Activator::maybeUpgrade()` fires on `admin_init`.
3. **Version change detected** — the stored `vibe_ai_db_version` option is compared against the code-defined `Config::DB_VERSION` constant.
4. **Schema migrations run automatically** — if the code version is higher, targeted migration functions execute. Each migration is **idempotent** (safe to run multiple times).
5. **Version is updated** — the `vibe_ai_db_version` option is set to the new value.
6. **No data loss** — all schema changes are additive (new columns and indexes). Existing data is never deleted or modified.

```
┌─────────────┐    ┌──────────────────┐    ┌───────────────────┐    ┌──────────────┐
│ Upload new   │───►│ maybeUpgrade()   │───►│ Run migrations    │───►│ Update       │
│ plugin files │    │ detects version  │    │ (idempotent)      │    │ db_version   │
└─────────────┘    └──────────────────┘    └───────────────────┘    └──────────────┘
```

---

## Version History

| Version | Date | Description |
|---------|------|-------------|
| **1.0.0** | Initial release | Entity extraction pipeline, entity CRUD, alias management, Schema.org JSON-LD output |
| **1.0.8** | Current plugin version | Admin and REST contract alignment, KB pipeline lifecycle fixes, and public AI publishing endpoint support |
| **1.1.0** | Current DB schema version | KB schema expansion — new columns for indexing metadata, vector payload storage, and query performance indexes |

> **Note:** The plugin version (visible in the WordPress plugins list) and the DB schema version (`vibe_ai_db_version`) are tracked independently. The DB version only changes when schema modifications are required.

---

## Schema Migration Details (DB 1.0.0 → 1.1.0)

The `migrateKBLegacyColumns()` function applies the following changes to Knowledge Base tables. All migrations use `addColumnIfMissing()` which checks for column existence before altering, making the process safe to re-run.

### New Columns

| Table | Column | MySQL Type | Purpose |
|-------|--------|-----------|---------|
| `wp_ai_kb_docs` | `last_indexed_at` | `DATETIME DEFAULT NULL` | Tracks when a document was last indexed, enabling incremental reindexing |
| `wp_ai_kb_chunks` | `heading_path_json` | `TEXT` | Stores the heading hierarchy path for each chunk (e.g. `["H1 Title", "H2 Subtitle"]`) |
| `wp_ai_kb_chunks` | `token_estimate` | `INT UNSIGNED DEFAULT 0` | Approximate token count per chunk for cost tracking and context window management |
| `wp_ai_kb_vectors` | `vector_payload` | `LONGBLOB` | Raw binary vector data stored alongside the base64-encoded representation |
| `wp_ai_kb_vectors` | `dims` | `SMALLINT UNSIGNED` | Vector dimensionality, used for validation and similarity search optimization |

### New Indexes

| Table | Index Name | Columns | Purpose |
|-------|-----------|---------|---------|
| `wp_ai_kb_docs` | `idx_last_indexed_at` | `last_indexed_at` | Speeds up incremental reindex queries that filter by last-indexed timestamp |
| `wp_ai_kb_chunks` | `idx_doc_chunk_index` | `(doc_id, chunk_index)` | Composite index for efficient chunk lookups by document and position |

### Migration Safety

- **Idempotent**: `addColumnIfMissing()` checks `SHOW COLUMNS` before each `ALTER TABLE`, so running the migration multiple times has no side effects.
- **Non-destructive**: No columns are dropped, renamed, or resized. No data is transformed or deleted.
- **Low risk**: Index additions are online operations on InnoDB and do not lock the table for extended periods.

---

## Pre-Upgrade Checklist

Complete these steps before upgrading to a new version:

- [ ] **Backup the database**. A full MySQL dump ensures you can roll back if needed:

  ```bash
  mysqldump -u <user> -p <database> > backup_$(date +%Y%m%d_%H%M%S).sql
  ```

- [ ] **Note the current DB version** for reference:

  ```bash
  wp option get vibe_ai_db_version
  ```

  Or query directly:

  ```sql
  SELECT option_value FROM wp_options WHERE option_name = 'vibe_ai_db_version';
  ```

- [ ] **Verify server requirements**:

  | Requirement | Minimum |
  |-------------|---------|
  | PHP | 8.1+ |
  | MySQL | 8.0+ |
  | MariaDB | 10.6+ |

  ```bash
  php -v
  mysql --version
  ```

- [ ] **Ensure no active pipeline runs**. Stop any running extraction before upgrading:

  ```bash
  curl -s -X POST -H "X-WP-Nonce: <nonce>" /wp-json/vibe-ai/v1/stop
  ```

  Confirm status is `idle` before proceeding.

---

## Post-Upgrade Verification

After the upgrade completes, verify everything is working:

1. **Check the status endpoint** returns valid data:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" /wp-json/vibe-ai/v1/status | jq .
   ```

   Expected: `"status": "idle"` with no error fields.

2. **Verify entity counts match pre-upgrade values**:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" "/wp-json/vibe-ai/v1/entities?per_page=1" | jq '.total'
   ```

   Compare against the count you noted before upgrading.

3. **Check KB status** for document, chunk, and vector counts:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" /wp-json/vibe-ai/v1/kb/status | jq .
   ```

4. **Review logs for migration errors**:

   ```bash
   curl -s -H "X-WP-Nonce: <nonce>" \
     "/wp-json/vibe-ai/v1/logs?level=error&limit=10" | jq '.entries[]'
   ```

   Look for any entries containing `migration` or `ALTER TABLE`.

5. **Test the admin UI** loads correctly with no console errors (F12 → Console).

---

## Rollback Procedure

If the upgrade causes issues, follow these steps to revert:

### 1. Restore the Database

```bash
mysql -u <user> -p <database> < backup_YYYYMMDD_HHMMSS.sql
```

### 2. Revert to the Previous Plugin Version

Replace the plugin files with the previous version via FTP, or:

```bash
wp plugin install ai-entity-index --version=<previous-version> --force
```

### 3. Clear Plugin Transients

Remove cached data that may reference the new schema:

```bash
wp db query "DELETE FROM wp_options WHERE option_name LIKE '_transient_vibe_ai_%';"
wp db query "DELETE FROM wp_options WHERE option_name LIKE '_transient_timeout_vibe_ai_%';"
```

### 4. Verify Rollback

- Check the DB version matches the downgraded plugin:

  ```bash
  wp option get vibe_ai_db_version
  ```

- Confirm the admin UI loads without errors.
- Verify entity and KB data are intact.

> **Warning:** Rollback is only supported to the previous version. If you skip multiple versions and need to roll back, you **must** restore from a database backup taken before the upgrade.

---

## Breaking Changes Policy

AI Entity Index follows a strict additive-only policy for schema and API changes:

### Schema

- **Additive only** — new columns and indexes are added, never removed.
- **No column type changes** — column definitions are not modified after release.
- **Migrations are version-gated** — `maybeUpgrade()` only runs migration functions when a version delta is detected.

### REST API

- **Backwards-compatible** — existing endpoints, parameters, and response shapes are preserved across versions.
- **New fields are additive** — response objects may gain new fields, but existing fields retain their types and semantics.
- **Deprecation notices** — before any endpoint or parameter is removed, it will be marked as deprecated in the changelog and the response will include a `deprecation_notice` field for at least one major version.

### Deprecation Timeline

| Phase | Duration | Behavior |
|-------|----------|----------|
| **Active** | Current release | Fully supported, no warnings |
| **Deprecated** | 1 major version | `deprecation_notice` in responses, changelog entry |
| **Removed** | Next major version | Endpoint/parameter removed |

See [Implementation Drift](../changelog/implementation-drift.md) for tracking deviations between documentation and implementation.
