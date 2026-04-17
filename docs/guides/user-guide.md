# User Guide

> How to use the AI Entity Index admin panel in WordPress.

The plugin adds an **AI Entity Index** menu item to your WordPress admin sidebar. Clicking it opens a React single-page application where you manage the entity pipeline, knowledge base, and all plugin settings.

---

## Dashboard

**Route:** `/dashboard`

The Dashboard is the main landing page. It combines pipeline controls with a semantic SEO health summary so you can see both operational status and content-readiness status in one place.

### Stats Cards

Four summary cards sit at the top of the page:

- **Total Entities** — Number of unique entities currently stored.
- **Total Mentions** — Total number of entity-to-post links across all content.
- **Average Confidence** — Mean confidence score across all extractions.
- **Posts Pending** — Posts waiting to be processed.

### Semantic SEO Health

Below the top stats cards, the Dashboard shows a semantic readiness panel with:

- **Overall health score** — Weighted summary of coverage, freshness, KB indexing, and crawler readiness.
- **Schema Coverage** — Percentage of eligible posts with valid cached JSON-LD.
- **Entity Coverage** — Percentage of eligible posts that currently have extracted entities.
- **KB Index Coverage** — Percentage of eligible KB posts that are indexed for semantic search.
- **Recent Changes** — Recent KB publishing activity surfaced from the change feed.

Two detail sections sit below the summary metrics:

- **Release Checks** — Pass/warn/fail checks for API key setup, schema injection, schema freshness, entity coverage, KB indexing, and AI publishing readiness.
- **AI Discovery Endpoints** — Health and summary metadata for `/llms.txt`, `/ai-sitemap`, and `/changes`.

### Phase Stepper

A horizontal timeline shows the six extraction phases in order:

1. Preparation
2. Extraction
3. Deduplication
4. Linking
5. Indexing
6. Schema Build

Each phase displays one of four states:

| State    | Meaning                                    |
|----------|--------------------------------------------|
| Pending  | Phase has not started yet.                 |
| Active   | Phase is currently running.                |
| Complete | Phase finished successfully.               |
| Error    | Phase encountered a failure.               |

### Progress Bar

Below the stepper, a progress bar shows:

- **Percentage** — How far the current phase has progressed.
- **Items processed** — e.g. "42 of 120 posts".
- **ETA** — Estimated time remaining.

### Live Terminal

A scrolling log viewer displays real-time pipeline output. Each log line is color-coded by level:

| Level    | Color   |
|----------|---------|
| DEBUG    | Gray    |
| INFO     | Blue    |
| API      | Cyan    |
| WARN     | Yellow  |
| ERROR    | Red     |
| SUCCESS  | Green   |

The terminal auto-scrolls to the latest entry. You can scroll up to pause auto-scroll; it resumes when you scroll back to the bottom.

### Actions

Three buttons control the pipeline:

- **Start Pipeline** — Starts extraction using the post types currently saved in Settings.
- **Stop Pipeline** — Gracefully stops the pipeline after the current batch finishes.
- **Full Reindex** — Starts a full rebuild pass so all configured content is reprocessed.

> **Warning:** Full Reindex is the right tool when schema coverage or entity freshness checks show the site needs a full rebuild.

---

## Entities

**Route:** `/entities`

The Entities page lists every entity the plugin has extracted from your content.

### Entity Table

| Column      | Description                                                            |
|-------------|------------------------------------------------------------------------|
| Select      | Checkbox for bulk actions.                                             |
| Name        | Entity name. Click to sort. Double-click to edit inline.               |
| Type        | Entity type (Person, Organization, Location, etc.). Click to sort. Double-click to change via inline dropdown. |
| Mentions    | Number of posts where this entity appears.                             |
| Status      | Badge showing current status (Active, Pending, Ignored, etc.).         |
| Schema Map  | Link to view the entity in Schema.org mapping context.                 |

### Filters

Above the table, three filters help narrow results:

- **Search** — Type to filter by entity name. Input is debounced (300ms) so it does not fire on every keystroke.
- **Type** — Multi-select dropdown to show only specific entity types.
- **Status** — Multi-select dropdown to show only specific statuses.

Filters combine with AND logic — selecting a Type and a Status shows entities matching both.

### Bulk Actions

Select one or more entities using the checkboxes, then choose an action:

- **Merge** (requires 2+ selected) — Opens the Merge Modal.
- **Set Status** — Change status for all selected entities at once.
- **Delete** — Permanently removes selected entities and their mentions.

#### Merge Modal

When merging entities:

1. A preview shows all selected entities side by side.
2. The entity with the highest mention count is auto-selected as the **target** (the surviving record).
3. All mentions and aliases from the other entities are merged into the target.
4. Confirm to complete the merge. Merged-away entities are deleted.

### Entity Drawer

Click any entity row to open a slide-over panel on the right side of the screen. The drawer has several sections:

#### Identity

- **Canonical Name** — The primary name for this entity. Editable.
- **Slug** — URL-safe identifier. Read-only, auto-generated from the name.
- **Type** — Dropdown to change the entity type.
- **Status** — Dropdown to change the entity status.

#### Semantic Links

- **Wikipedia URL** — Link to the entity's Wikipedia page. Click **Fetch** to auto-populate the description and Wikidata ID from Wikipedia.
- **Wikidata ID** — Wikidata identifier in `Q` format (e.g., `Q312`). Validated against the `Q\d+` pattern.
- **Description** — Free-text description of the entity. Maximum 500 characters.

#### Aliases

Alternative names for the entity displayed as pill tags. Each alias has an **×** button to remove it. Type a new name in the input field and press Enter to add.

#### Mentions

Shows the top 5 posts where this entity appears. Each mention displays:

- **Context snippet** — A short excerpt of the surrounding text.
- **Confidence badge** — Color-coded tier (High/Medium/Low).
- **Post link** — Click to open the WordPress post editor.

#### Actions

- **Delete** — Remove the entity and all its mentions.
- **Force Sync** — Re-extract this entity from its linked posts.
- **Save** — Save changes without regenerating Schema.org data.
- **Save & Propagate** — Save changes and regenerate Schema.org JSON-LD for every post linked to this entity. Use this when you change the entity name, type, or semantic links, so search engines see updated structured data.

---

## Knowledge Base

**Route:** `/kb`

The Knowledge Base (KB) section manages your RAG-ready content index. It has five sub-pages.

### KB Overview

**Route:** `/kb/`

Stats at the top show:

- **Total Documents** — Posts indexed into the KB.
- **Total Chunks** — Content segments created.
- **Indexed** — Successfully embedded chunks.
- **Failed** — Chunks that failed to embed.
- **Last Run** — Timestamp of the most recent indexing.

A **Reindex** button lets you rebuild the KB. A recent activity feed shows the latest indexing events.

### KB Documents

**Route:** `/kb/documents`

A data table listing every document in the KB with these columns:

| Column        | Description                                              |
|---------------|----------------------------------------------------------|
| Title         | Document title.                                          |
| Type          | Post type (post, page, etc.).                            |
| Status        | Indexing status.                                         |
| Chunks        | Number of chunks derived from this document.             |
| Last Indexed  | When the document was last processed.                    |
| Actions       | Reindex, Exclude, or View Chunks for each document.      |

Select multiple documents for bulk actions (reindex or exclude).

### KB Test Search

**Route:** `/kb/search`

A playground for testing semantic search against your KB.

1. **Query** — Type a natural language query. Results auto-load as you type.
2. **Top-K Slider** — Adjust how many results to return (1–20). Default is 8.
3. **Filters** — Narrow results by post type or date range.

Each result displays:

- **Similarity score** — Badge showing cosine similarity (0.0–1.0).
- **Heading path** — Breadcrumb showing where in the document the chunk came from.
- **Content preview** — Excerpt of the matched chunk.
- **Metadata** — Expandable section with additional details (post ID, chunk anchor, token count).

### KB Settings

**Route:** `/kb/settings`

Configure how the KB is built:

| Setting            | Description                                                          |
|--------------------|----------------------------------------------------------------------|
| Enable/Disable     | Master toggle for the entire Knowledge Base system.                  |
| Post Types         | Checkboxes for which post types to index.                            |
| Embedding Model    | Which AI model generates vector embeddings.                          |
| Target Tokens      | Desired chunk size in tokens (200–800). Default ~450.                |
| Overlap Tokens     | Number of overlapping tokens between chunks (20–150).                |
| Auto Index         | Whether published content should automatically enqueue KB indexing updates. |

Settings not listed in the UI are not currently persisted by the shipped backend and should be treated as future-facing rather than available.

### KB Logs

**Route:** `/kb/logs`

A filterable log viewer for KB-specific events:

- **Filters** — Filter by log level and KB component (`kb`, `embedding`, `chunking`, or `all`).
- **Auto-refresh** — Toggle to automatically reload logs.
- **Expandable entries** — Click a log line to see its full JSON context.

---

## Settings

**Route:** `/settings`

### API Settings

- **AI Model** — Choose which model powers entity extraction:
  - Claude Opus 4.5
  - Claude Sonnet 4
  - Claude 3.5 Sonnet
- **API Key** — The plugin reads your API key from `wp-config.php`. See the installation guide for setup instructions.

### Processing Settings

- **Batch Size** — Number of posts processed per pipeline job. Adjustable from 5 to 50. Higher values process faster but use more memory and API quota.
- **Confidence Threshold** — Minimum confidence score for an extraction to be accepted (0.40–0.95). The slider displays the current tier:
  - High: 0.85+
  - Medium: 0.60–0.84
  - Low: 0.40–0.59

### Post Types

Dynamic checkboxes listing all public post types registered on your site. Check the types you want the pipeline to process.

---

## Activity Log

**Route:** `/logs`

A historical view of all plugin activity, separate from the Dashboard's live terminal.

### Filters and Navigation

- **Level filter** — Show only entries at a specific log level (DEBUG, INFO, WARN, ERROR).
- **Date picker** — Filter entries to a specific date.
- **Pagination** — Choose 25, 50, or 100 entries per page.

### Log Entries

Each entry shows a timestamp, level badge, and message. Click an entry to expand its JSON context, which includes request details, affected IDs, and error traces.

### Stats Cards

Three summary cards above the table:

- **Info / API** — Count of informational and API call logs.
- **Warnings** — Count of warning-level entries.
- **Errors** — Count of error-level entries.

### Auto-Refresh

The log page automatically refreshes every 60 seconds. A toggle lets you disable this if you prefer manual refresh.

---

## Tips

- Use the **Entity Drawer**'s Save & Propagate button after editing entity details to ensure Schema.org output stays in sync.
- The **Test Search** page under Knowledge Base is the best way to verify your content is being indexed correctly.
- If the pipeline stalls on a phase, check the Live Terminal on the Dashboard for error messages, then consult the Activity Log for full context.
- Adjust the **Confidence Threshold** in Settings if you see too many false positives (raise it) or too many missed entities (lower it).
