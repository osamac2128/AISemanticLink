# AI Entity Index — Master Product Requirements Document

**Project:** AI Entity Index (WordPress Plugin)  
**Architect:** Vibe Architect (Core Directive: Strict Spec-First)  
**Version:** 1.0.0 (Release Candidate)  
**Date:** January 15, 2026  
**Status:** Final Review  
**Target Stack:** PHP 8.1+, React 18, Custom SQL, Action Scheduler  
**Core Directive:** *"Trust the Vibe, Verify the Code."* (No Spaghetti Data)

---

## Architect's Log

> I have consolidated all previous architectural decisions, strict SQL schemas, and the "Chain-Link" cache strategy into a single, authoritative Product Requirements Document. I have expanded Section 9 (React Admin Interface) to include specific component states and visualization logic.
>
> This document defines the "What" and "How" before a single line of code is written, ensuring no semantic spaghetti is created.

---

## 1. Executive Summary

**Goal:** Establish a "Semantic Truth Layer" for WordPress websites by extracting, normalizing, and linking named entities (People, Organizations, Locations, Products, Concepts) across all content—then injecting this structured knowledge as high-performance Schema.org JSON-LD.

**Core Problem:** WordPress stores content as unstructured HTML blobs ("Semantic Spaghetti"). Search engines and LLMs require structured entity relationships to properly understand, cite, and surface content. Existing SEO plugins rely on keywords (strings) rather than entities (things) and fail to handle large-scale data updates without crashing the server.

**Value Proposition:**
- Transform unstructured content into a queryable knowledge graph
- Improve AI/LLM discoverability through explicit entity markup
- Enable semantic search and content recommendations
- Future-proof content for AI-native discovery paradigms

---

## 2. Technical Scope

### 2.1 In-Scope (MVP — The "Must Haves")

| Feature | Description |
|---------|-------------|
| Sequential AI Pipeline | Strict 6-phase Job Runner: Preparation → Extraction → Deduplication → Linking → Indexing → Schema Generation |
| Custom Knowledge Graph | Dedicated SQL tables (`wp_ai_entities`, `wp_ai_mentions`, `wp_ai_aliases`) decoupled from `wp_posts` |
| Intelligence Layer | OpenRouter API integration (model-agnostic) with strict JSON schema enforcement |
| Chain-Link Invalidation | Recursive background strategy to safely update thousands of posts when an entity changes |
| Pre-Computed Schema Injection | Asynchronous generation of JSON-LD stored in `post_meta` for zero-query frontend loading |
| React Admin SPA | Single Page Application within `wp-admin` for monitoring jobs and managing data |
| Alias Resolution | Multiple surface forms mapping to single canonical entity |
| Schema.org Type Mapping | Explicit mappings from internal types to Schema.org vocabulary |

### 2.2 Out-of-Scope (The "Panic Boundary")

- Auto-internal linking (modifying `post_content` HTML)
- Vector embeddings / Semantic search (v1 is purely LLM/text-based)
- Entity-to-entity relationship edges (Phase 2 candidate)
- Multi-site / Network setups
- Frontend CSS injection (visuals are backend only)
- Real-time extraction (batch processing only)

---

## 3. Tech Stack (Strict Versioning)

| Layer | Technology | Version | Notes |
|-------|------------|---------|-------|
| Backend | PHP | 8.1+ | Strict typing: `declare(strict_types=1);` |
| Database | MySQL / MariaDB | 8.0+ / 10.6+ | InnoDB engine required for FK support |
| Queue | Action Scheduler | 3.7+ | Vendor bundled via Composer |
| Frontend | React | 18.x | Via `@wordpress/scripts` |
| Styling | Tailwind CSS | 3.x | JIT compilation |
| Tables | TanStack Table | 8.x | Headless table management |
| Charts | Recharts | 2.x | Dashboard visualizations |
| AI Gateway | OpenRouter API | — | Model-agnostic (Claude, GPT, etc.) |

---

## 4. High-Level Architecture

**Pattern:** WordPress BFF (Backend for Frontend)

The React Admin is a "dumb" view layer polling the "smart" PHP Pipeline Manager. The Admin UI never talks to the database directly—it polls the `PipelineManager` via REST.

```
┌─────────────────────────────────────────────────────────────────┐
│                        TRIGGER LAYER                            │
│  [Manual Trigger]  [Cron Schedule]  [Post Save Hook]            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      PIPELINE MANAGER                           │
│  • Batch Orchestration (Dynamic: 5-50 posts/batch)              │
│  • Rate Limit Awareness (Adaptive throttling)                   │
│  • Failure Recovery & Retry Logic                               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     ACTION SCHEDULER                            │
│  Phase 1: Preparation    → Collect post IDs, strip HTML         │
│  Phase 2: Extraction     → AI entity extraction                 │
│  Phase 3: Deduplication  → Alias resolution, canonical merge    │
│  Phase 4: Linking        → Write mentions to database           │
│  Phase 5: Indexing       → Build entity index, update counts    │
│  Phase 6: Schema Build   → Generate & cache JSON-LD             │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       DATA LAYER                                │
│  wp_ai_entities │ wp_ai_mentions │ wp_ai_aliases │ post_meta    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      OUTPUT LAYER                               │
│  • wp_head JSON-LD injection                                    │
│  • REST API for external consumers                              │
│  • Admin UI state polling                                       │
└─────────────────────────────────────────────────────────────────┘
```

### 4.1 Data Flow

1. **Write:** Admin triggers "Phase 1". PHP Dispatcher queues batches in Action Scheduler.
2. **Process:** Worker extracts entities → Writes to Custom Tables.
3. **Read:** Frontend polls `/wp-json/vibe-ai/v1/status` for real-time progress.
4. **Inject:** `wp_head` hook reads pre-computed JSON from `post_meta` (Cache).

### 4.2 The 6-Phase Pipeline

| Phase | Name | Description | Output |
|-------|------|-------------|--------|
| 1 | **Preparation** | Collect publishable post IDs, strip HTML to plain text | Queue of post IDs |
| 2 | **Extraction** | Send content to AI, parse entity JSON response | Raw entity records |
| 3 | **Deduplication** | Resolve aliases, merge duplicates, normalize names | Canonical entities |
| 4 | **Linking** | Create mention records connecting entities to posts | Mention edges |
| 5 | **Indexing** | Update entity counts, build reverse index | Updated statistics |
| 6 | **Schema Build** | Generate JSON-LD blobs, cache in post_meta | Schema cache |

---

## 5. Database Schema ("Strict Mode")

**Design Principle:** We do not use `wp_postmeta` for relational data. We use strict foreign keys. Row-level security concepts applied via application logic—dedicated tables only.

### 5.1 Entities Table (The Canonical Truth)

```sql
CREATE TABLE wp_ai_entities (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    name            varchar(255) NOT NULL,
    slug            varchar(255) NOT NULL,
    type            varchar(50) DEFAULT 'CONCEPT',
    schema_type     varchar(100) DEFAULT 'Thing',
    description     text DEFAULT NULL,
    same_as_url     varchar(2048) DEFAULT NULL,
    wikidata_id     varchar(50) DEFAULT NULL,
    status          varchar(20) DEFAULT 'raw',
    mention_count   int unsigned DEFAULT 0,
    created_at      datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at      datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    UNIQUE KEY idx_slug (slug),
    KEY idx_type (type),
    KEY idx_status (status),
    KEY idx_mention_count (mention_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.2 Mentions Table (The Edges)

```sql
CREATE TABLE wp_ai_mentions (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    entity_id       bigint(20) unsigned NOT NULL,
    post_id         bigint(20) unsigned NOT NULL,
    confidence      float DEFAULT 0.0,
    context_snippet text,
    is_primary      tinyint(1) DEFAULT 0,
    created_at      datetime DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    UNIQUE KEY idx_entity_post (entity_id, post_id),
    KEY idx_post_id (post_id),
    KEY idx_confidence (confidence),
    
    CONSTRAINT fk_mentions_entity 
        FOREIGN KEY (entity_id) REFERENCES wp_ai_entities (id) ON DELETE CASCADE,
    CONSTRAINT fk_mentions_post 
        FOREIGN KEY (post_id) REFERENCES wp_posts (ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.3 Aliases Table (Surface Form Resolution)

```sql
CREATE TABLE wp_ai_aliases (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    canonical_id    bigint(20) unsigned NOT NULL,
    alias           varchar(255) NOT NULL,
    alias_slug      varchar(255) NOT NULL,
    source          varchar(50) DEFAULT 'ai',
    created_at      datetime DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    UNIQUE KEY idx_alias_slug (alias_slug),
    KEY idx_canonical (canonical_id),
    
    CONSTRAINT fk_alias_canonical 
        FOREIGN KEY (canonical_id) REFERENCES wp_ai_entities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.4 Post Meta Keys

| Key | Type | Purpose |
|-----|------|---------|
| `_vibe_ai_schema_cache` | JSON string | Pre-computed Schema.org JSON-LD |
| `_vibe_ai_extracted_at` | ISO datetime | Last extraction timestamp |
| `_vibe_ai_schema_version` | integer | Cache version for invalidation |

---

## 6. Entity Type System

### 6.1 Internal Types → Schema.org Mapping

| Internal Type | Schema.org Type | Example |
|---------------|-----------------|---------|
| `PERSON` | `Person` | Sam Altman, Elon Musk |
| `ORG` | `Organization` | OpenAI, Apple Inc. |
| `COMPANY` | `Corporation` | Microsoft, Google |
| `LOCATION` | `Place` | San Francisco, Tokyo |
| `COUNTRY` | `Country` | United States, Japan |
| `PRODUCT` | `Product` | iPhone, ChatGPT |
| `SOFTWARE` | `SoftwareApplication` | WordPress, Slack |
| `EVENT` | `Event` | WWDC 2025, CES |
| `WORK` | `CreativeWork` | The Great Gatsby |
| `CONCEPT` | `Thing` | Machine Learning, SEO |

### 6.2 Confidence Thresholds

| Tier | Range | Action |
|------|-------|--------|
| High | 0.85–1.0 | Auto-approve, include in Schema |
| Medium | 0.60–0.84 | Include in Schema, flag for review |
| Low | 0.40–0.59 | Store but exclude from Schema |
| Reject | 0.0–0.39 | Discard, do not store |

---

## 7. Intelligence Layer

### 7.1 Supported Models

| Provider | Model | Use Case | Cost Tier |
|----------|-------|----------|-----------|
| Anthropic | `claude-3.5-sonnet` | Accuracy-first extraction | Premium |
| OpenAI | `gpt-4o-mini` | Speed/cost optimization | Standard |
| Anthropic | `claude-3-haiku` | High-volume, lower accuracy | Budget |

### 7.2 System Prompt (The Doctrine)

```text
You are an expert Semantic Knowledge Graph Engineer specializing in Named Entity Recognition and normalization for SEO and AI discoverability.

CORE RULES:
1. Extract ONLY Named Entities (proper nouns with specific identity)
2. IGNORE generic nouns, adjectives, and common concepts
3. NORMALIZE names to their most complete, canonical form
   - "Sam" → "Sam Altman" (if context implies)
   - "the iPhone" → "iPhone"
   - "Google's AI lab" → "Google DeepMind"
4. RESOLVE ambiguity using context
   - "Apple" in tech context → "Apple Inc."
   - "Apple" in food context → reject (generic)
5. Assign appropriate TYPE from: PERSON, ORG, COMPANY, LOCATION, COUNTRY, PRODUCT, SOFTWARE, EVENT, WORK, CONCEPT
6. Provide CONFIDENCE score (0.0-1.0) based on extraction certainty
7. Include CONTEXT snippet (exact quote, max 100 chars) showing entity mention

RESPONSE FORMAT (strict JSON only, no markdown):
{
  "entities": [
    {
      "name": "Canonical Name",
      "type": "TYPE",
      "confidence": 0.95,
      "context": "...snippet where entity appears...",
      "aliases": ["alternate name", "abbreviation"]
    }
  ]
}

CRITICAL: Return ONLY valid JSON. No explanations. No markdown code blocks.
```

### 7.3 Rate Limiting Configuration

```php
const VIBE_AI_RATE_LIMITS = [
    'requests_per_minute' => 60,
    'tokens_per_minute'   => 100000,
    'batch_size'          => 50,
    'retry_attempts'      => 3,
    'backoff_multiplier'  => 2,
    'base_delay_seconds'  => 5,
];
```

---

## 8. Data Layer Implementation

### 8.1 EntityRepository (The Gatekeeper)

```php
<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Repositories;

/**
 * EntityRepository: The Gatekeeper of Truth.
 * Handles strict SQL operations for the Knowledge Graph.
 */
class EntityRepository {

    private \wpdb $wpdb;
    private string $entities_table;
    private string $mentions_table;
    private string $aliases_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->entities_table = $wpdb->prefix . 'ai_entities';
        $this->mentions_table = $wpdb->prefix . 'ai_mentions';
        $this->aliases_table  = $wpdb->prefix . 'ai_aliases';
    }

    /**
     * Upsert an Entity.
     * Logic: Check aliases first, then slug. Create if not found.
     *
     * @param string $name Raw name from AI (e.g., "Apple Inc.")
     * @param string $type Entity Type (e.g., "ORG")
     * @param array  $aliases Optional alternate names
     * @return int The Entity ID
     */
    public function upsert_entity(string $name, string $type, array $aliases = []): int {
        $clean_name = sanitize_text_field($name);
        $slug = sanitize_title($clean_name);

        // 1. Check if any alias resolves to existing canonical
        $canonical_id = $this->resolve_alias($slug);
        if ($canonical_id) {
            return $canonical_id;
        }

        // 2. Check for existing entity by slug
        $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->entities_table} WHERE slug = %s",
            $slug
        ));

        if ($existing_id) {
            return (int) $existing_id;
        }

        // 3. Insert new entity (atomic with duplicate protection)
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->entities_table} (name, slug, type, status)
             VALUES (%s, %s, %s, 'raw')
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            $clean_name,
            $slug,
            strtoupper($type)
        ));

        $entity_id = (int) $this->wpdb->insert_id;

        // 4. Register aliases
        foreach ($aliases as $alias) {
            $this->register_alias($entity_id, $alias);
        }

        return $entity_id;
    }

    /**
     * Resolve an alias to its canonical entity ID.
     */
    public function resolve_alias(string $alias_slug): ?int {
        $result = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT canonical_id FROM {$this->aliases_table} WHERE alias_slug = %s",
            sanitize_title($alias_slug)
        ));
        return $result ? (int) $result : null;
    }

    /**
     * Register an alias for a canonical entity.
     */
    public function register_alias(int $canonical_id, string $alias): void {
        $alias_slug = sanitize_title(sanitize_text_field($alias));

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->aliases_table} (canonical_id, alias, alias_slug, source)
             VALUES (%d, %s, %s, 'ai')",
            $canonical_id,
            sanitize_text_field($alias),
            $alias_slug
        ));
    }

    /**
     * Link an Entity to a Post.
     * Enforces one-link-per-post-per-entity.
     * Updates confidence/snippet if new extraction scores higher.
     */
    public function link_mention(
        int $entity_id,
        int $post_id,
        float $confidence,
        string $context,
        bool $is_primary = false
    ): void {
        $clean_context = wp_kses_post(mb_substr($context, 0, 500));

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->mentions_table}
                (entity_id, post_id, confidence, context_snippet, is_primary)
             VALUES (%d, %d, %f, %s, %d)
             ON DUPLICATE KEY UPDATE
                confidence = GREATEST(confidence, VALUES(confidence)),
                context_snippet = IF(VALUES(confidence) > confidence, VALUES(context_snippet), context_snippet),
                is_primary = VALUES(is_primary)",
            $entity_id,
            $post_id,
            $confidence,
            $clean_context,
            $is_primary ? 1 : 0
        ));

        // Update mention count on entity
        $this->update_mention_count($entity_id);
    }

    /**
     * Update cached mention count for an entity.
     */
    private function update_mention_count(int $entity_id): void {
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->entities_table}
             SET mention_count = (
                 SELECT COUNT(*) FROM {$this->mentions_table} WHERE entity_id = %d
             )
             WHERE id = %d",
            $entity_id,
            $entity_id
        ));
    }

    /**
     * Fetch entities for Schema generation.
     */
    public function get_entities_for_post(int $post_id, float $min_confidence = 0.6): array {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT
                e.id,
                e.name,
                e.type,
                e.schema_type,
                e.same_as_url,
                e.wikidata_id,
                e.description,
                m.confidence,
                m.context_snippet,
                m.is_primary
             FROM {$this->mentions_table} m
             JOIN {$this->entities_table} e ON m.entity_id = e.id
             WHERE m.post_id = %d
               AND e.status NOT IN ('trash', 'rejected')
               AND m.confidence >= %f
             ORDER BY m.is_primary DESC, m.confidence DESC",
            $post_id,
            $min_confidence
        ));
    }

    /**
     * Merge duplicate entities into a canonical target.
     */
    public function merge_entities(int $target_id, array $source_ids): array {
        $affected_posts = [];

        foreach ($source_ids as $source_id) {
            if ($source_id === $target_id) continue;

            // Get posts affected by this merge
            $posts = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$this->mentions_table} WHERE entity_id = %d",
                $source_id
            ));
            $affected_posts = array_merge($affected_posts, $posts);

            // Transfer mentions (update or delete if duplicate)
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE IGNORE {$this->mentions_table}
                 SET entity_id = %d
                 WHERE entity_id = %d",
                $target_id,
                $source_id
            ));

            // Transfer aliases
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->aliases_table}
                 SET canonical_id = %d
                 WHERE canonical_id = %d",
                $target_id,
                $source_id
            ));

            // Create alias from merged entity name
            $source_name = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT name FROM {$this->entities_table} WHERE id = %d",
                $source_id
            ));
            if ($source_name) {
                $this->register_alias($target_id, $source_name);
            }

            // Delete source entity (cascades remaining mentions)
            $this->wpdb->delete($this->entities_table, ['id' => $source_id], ['%d']);
        }

        // Update target mention count
        $this->update_mention_count($target_id);

        return array_unique($affected_posts);
    }
}
```

---

## 9. The "Chain-Link" Cache Invalidation Strategy

### 9.1 The Problem

Updating an entity name ("Apple" → "Apple Inc.") invalidates the cached Schema for potentially thousands of posts.

### 9.2 The Solution: Recursive Self-Scheduling Jobs (Recursive Batching)

```php
<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs;

class PropagateEntityChangeJob {

    private const BATCH_SIZE = 50;
    private const JOB_HOOK = 'vibe_ai_propagate_entity';

    public static function schedule(int $entity_id, int $last_post_id = 0): void {
        // Set propagation flag
        set_transient("vibe_ai_propagating_{$entity_id}", true, HOUR_IN_SECONDS);

        as_schedule_single_action(
            time(),
            self::JOB_HOOK,
            ['entity_id' => $entity_id, 'last_post_id' => $last_post_id]
        );
    }

    public static function execute(int $entity_id, int $last_post_id): void {
        global $wpdb;

        $mentions_table = $wpdb->prefix . 'ai_mentions';

        // Fetch next batch of affected posts
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id
             FROM {$mentions_table}
             WHERE entity_id = %d AND post_id > %d
             ORDER BY post_id ASC
             LIMIT %d",
            $entity_id,
            $last_post_id,
            self::BATCH_SIZE
        ));

        if (empty($post_ids)) {
            // Done - clear propagation flag
            delete_transient("vibe_ai_propagating_{$entity_id}");
            do_action('vibe_ai_entity_propagation_complete', $entity_id);
            return;
        }

        // Regenerate Schema for each post
        $schema_generator = new \Vibe\AIIndex\Services\SchemaGenerator();
        foreach ($post_ids as $post_id) {
            $schema_generator->regenerate($post_id);
        }

        // Schedule next batch
        $max_post_id = max($post_ids);
        self::schedule($entity_id, $max_post_id);
    }
}
```

### 9.3 Triggering Propagation

```php
// Hook into entity updates
add_action('vibe_ai_entity_updated', function(int $entity_id, array $changes) {
    $propagation_triggers = ['name', 'schema_type', 'same_as_url', 'wikidata_id'];

    if (array_intersect($propagation_triggers, array_keys($changes))) {
        PropagateEntityChangeJob::schedule($entity_id);
    }
}, 10, 2);
```

---

## 10. Schema.org Output

### 10.1 JSON-LD Structure

```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Article",
      "@id": "https://example.com/post-slug/#article",
      "headline": "Post Title",
      "author": {
        "@type": "Person",
        "@id": "https://example.com/#/entity/john-doe"
      },
      "mentions": [
        {"@id": "https://example.com/#/entity/openai"},
        {"@id": "https://example.com/#/entity/sam-altman"}
      ]
    },
    {
      "@type": "Organization",
      "@id": "https://example.com/#/entity/openai",
      "name": "OpenAI",
      "sameAs": [
        "https://www.wikidata.org/wiki/Q51346",
        "https://en.wikipedia.org/wiki/OpenAI"
      ]
    },
    {
      "@type": "Person",
      "@id": "https://example.com/#/entity/sam-altman",
      "name": "Sam Altman",
      "sameAs": "https://www.wikidata.org/wiki/Q14897036"
    }
  ]
}
```

### 10.2 Frontend Injection

```php
add_action('wp_head', function() {
    if (!is_singular()) return;

    $schema = get_post_meta(get_the_ID(), '_vibe_ai_schema_cache', true);

    if (!$schema) return;

    printf(
        '<script type="application/ld+json">%s</script>' . "\n",
        $schema // Pre-sanitized during generation
    );
}, 1);
```

---

## 11. REST API Endpoints

### 11.1 Endpoint Summary

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/vibe-ai/v1/status` | GET | Pipeline status & progress |
| `/vibe-ai/v1/entities` | GET | List entities (paginated) |
| `/vibe-ai/v1/entities/{id}` | GET | Single entity detail |
| `/vibe-ai/v1/entities/{id}` | PATCH | Update entity |
| `/vibe-ai/v1/entities/merge` | POST | Merge duplicates |
| `/vibe-ai/v1/pipeline/start` | POST | Trigger extraction |
| `/vibe-ai/v1/pipeline/stop` | POST | Cancel running jobs |

### 11.2 Status Response Schema

```json
{
  "status": "running",
  "current_phase": "extraction",
  "progress": {
    "total": 500,
    "completed": 245,
    "failed": 3,
    "percentage": 49
  },
  "stats": {
    "total_entities": 1247,
    "total_mentions": 8934,
    "avg_confidence": 0.847
  },
  "last_activity": "2026-01-15T14:32:00Z",
  "propagating_entities": [42, 156]
}
```

---

## 12. React Admin Interface Specification

The Admin UI is a contained SPA within `wp-admin`. It has three primary views designed for calm, deterministic visibility into the AI's work.

### 12.1 Route Structure

**Base URL:** `/wp-admin/admin.php?page=vibe-ai-index`

| View | Route | Component | Purpose |
|------|-------|-----------|---------|
| Dashboard | `/` | `<Dashboard />` | Pipeline monitoring & stats |
| Entities | `/entities` | `<EntityManager />` | Entity grid & bulk operations |
| Entity Detail | `/entities/:id` | `<EntityDrawer />` | Single entity deep dive |
| Settings | `/settings` | `<Settings />` | Configuration & API keys |
| Logs | `/logs` | `<ActivityLog />` | Historical job execution |

### 12.2 View A: The Pipeline Dashboard (Home)

**Goal:** Calm, deterministic visibility into the AI's work.

#### The Phase Stepper

A horizontal timeline showing the 6 phases with distinct visual states:

| State | Visual | Description |
|-------|--------|-------------|
| Pending | Grey circle, muted text | Not yet started |
| Active | Pulsing blue circle, bold text | Currently processing |
| Complete | Green checkmark | Successfully finished |
| Error | Red exclamation | Failed, needs attention |

#### The "Pulse" Bar

Real-time progress indicator for the current phase:

```
┌────────────────────────────────────────────────────────────────┐
│  ████████████████████░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░  45%    │
│  Processing Item 342 of 1,200 • ETA: 8 min remaining           │
└────────────────────────────────────────────────────────────────┘
```

- Animated striped pattern during active processing
- Label format: `"Processing Item {n} of {total} ({percentage}%)"`
- ETA calculation based on rolling average processing time

#### Stats Cards

| Card | Value | Visual |
|------|-------|--------|
| Total Entities | Count + trend arrow | 📊 |
| Total Mentions | Count | 🔗 |
| Avg Confidence | Percentage (color-coded) | Green >0.85, Yellow >0.60, Red <0.60 |
| Posts Pending | Count of unprocessed | ⏳ |

#### Live Terminal

Console-style scrolling log window:

```
[10:42:05] [INFO]  Extracted 4 entities from 'Hello World'
[10:42:07] [API]   OpenRouter Call: 240ms (claude-3.5-sonnet)
[10:42:08] [WARN]  Low confidence entity skipped: "thing"
[10:42:10] [ERROR] Rate limit hit, backing off 5s
```

- Color-coded by severity: INFO (grey), API (blue), WARN (yellow), ERROR (red)
- Last 50 entries with auto-scroll
- Pause/resume scrolling toggle

### 12.3 View B: Entity Grid (The "Spreadsheet")

**Goal:** Rapid review and editing of the "Truth."

#### Table Implementation

**Library:** TanStack Table v8 (headless)

**Columns:**

| Column | Sortable | Filterable | Notes |
|--------|----------|------------|-------|
| ☑️ Checkbox | No | No | Bulk selection |
| Name | Yes | Text search | Editable inline |
| Type | Yes | Multi-select | Dropdown |
| Mentions | Yes | Range slider | Count badge |
| Status | Yes | Multi-select | Badge (Raw/Reviewed/Canonical) |
| Sync | No | No | Spinning icon if propagating |
| Schema Map | No | No | Link to sameAs URL |

#### Visual State: Sync Indicator

If `vibe_ai_is_propagating_{entity_id}` transient is `true`:
- Display spinning sync icon (🔄) next to entity name
- Row background: subtle blue highlight
- Tooltip: "Syncing Schema to {n} posts..."

#### Interactions

**Inline Editing:**
- Click entity name → Inline text input
- Click type → Inline dropdown
- Click status → Inline badge selector

**Bulk Actions:**

| Action | Trigger | Behavior |
|--------|---------|----------|
| Merge Selected | 2+ selected | Opens merge modal |
| Set Status | 1+ selected | Bulk status change |
| Delete | 1+ selected | Confirmation dialog |

#### Merge Mode

1. Select 2+ entities via checkboxes
2. Click "Merge Selected"
3. Modal appears: *"Which entity should be the Master?"*
4. Select canonical entity from radio list
5. Confirm → Background job merges aliases & redirects mentions

### 12.4 View C: The Entity Editor (Drawer)

**Goal:** Deep dive into a single entity without losing context.

**Trigger:** Clicking an entity name in the Grid opens a right-side Drawer (slide-over panel).

#### Section 1: Identity (Metadata)

| Field | Type | Notes |
|-------|------|-------|
| Name | Text input | Canonical name |
| Slug | Read-only | Auto-generated |
| Type | Select | PERSON, ORG, etc. |
| Status | Select | raw, reviewed, canonical, trash |

#### Section 2: Semantic Links (Schema.org)

| Field | Type | Notes |
|-------|------|-------|
| Wikipedia URL | URL input | "Fetch" button to auto-populate |
| Wikidata ID | Text input | Validation: `Q[0-9]+` |
| Description | Textarea | 500 char max, for Schema |

#### Section 3: Aliases

- List of registered aliases with delete (×) buttons
- "Add Alias" input with (+) button
- Visual: Pills/tags layout

#### Section 4: Mention Context

Top 5 snippets where this entity appears:

```
┌─────────────────────────────────────────────────────────────┐
│ 📄 "The Future of AI" (Post #1234)          Confidence: 94% │
│ "...Sam Altman announced that OpenAI would..."              │
├─────────────────────────────────────────────────────────────┤
│ 📄 "Tech Giants 2025" (Post #1198)          Confidence: 87% │
│ "...the CEO of OpenAI confirmed plans to..."                │
└─────────────────────────────────────────────────────────────┘
```

#### Action Buttons

| Button | Behavior |
|--------|----------|
| **Save** | Save changes, no propagation |
| **Save & Propagate** | Save + trigger Chain-Link job |
| **Force Sync** | Manually trigger propagation (even without changes) |
| **Delete** | Confirmation dialog → soft delete (status='trash') |

---

## 13. Security & Performance Guardrails

### 13.1 The "Panic Policy" (Row-Level Security for WordPress)

#### Capability Checks

Every REST endpoint requires `manage_options` capability:

```php
register_rest_route('vibe-ai/v1', '/entities', [
    'methods'  => 'GET',
    'callback' => [$this, 'get_entities'],
    'permission_callback' => function() {
        return current_user_can('manage_options');
    }
]);
```

#### Sanitization Rules

**Input Sanitization:**

| Field | Function | Purpose |
|-------|----------|---------|
| Entity name | `sanitize_text_field()` | Strip HTML, normalize whitespace |
| Context snippet | `wp_kses_post()` | Allow safe HTML subset |
| URLs | `esc_url_raw()` | Validate URL format |
| Slugs | `sanitize_title()` | URL-safe transformation |

**Output Escaping:**

| Context | Function |
|---------|----------|
| React props | `esc_html()` |
| JSON-LD Schema | `wp_json_encode()` with `JSON_HEX_TAG` |
| Admin URLs | `esc_url()` |

```php
// JSON-LD safe encoding prevents script injection via Schema
$safe_schema = wp_json_encode($schema_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
```

### 13.2 Secrets Management

```php
// wp-config.php (NEVER in database)
define('VIBE_AI_OPENROUTER_KEY', 'sk-or-...');
define('VIBE_AI_ENCRYPTION_KEY', 'random-32-byte-string');
```

**Rules:**
- API keys NEVER stored in database
- API keys NEVER exposed in REST responses
- API keys NEVER logged (even in debug mode)
- All admin endpoints require `manage_options` capability

### 13.3 Rate Limiting & Dynamic Batching

#### Dynamic Batch Sizing

Adaptive batch size based on processing performance:

```php
class BatchSizeManager {
    private const MIN_BATCH = 5;
    private const MAX_BATCH = 50;
    private const TARGET_TIME = 5.0; // seconds

    public function calculate_next_batch_size(float $avg_process_time, int $current_size): int {
        if ($avg_process_time < 2.0) {
            // Fast processing: increase batch
            return min(self::MAX_BATCH, $current_size + 5);
        } elseif ($avg_process_time > 10.0) {
            // Slow processing: decrease batch
            return max(self::MIN_BATCH, $current_size - 5);
        }
        return $current_size;
    }
}
```

**Batch Size Rules:**
- Start at 5 posts/batch
- If average processing time < 2s → Increase to 10
- If average processing time > 10s → Decrease
- Maximum: 50 posts/batch
- Minimum: 5 posts/batch

#### OpenRouter Rate Handling

```php
// Handle 429 errors with exponential backoff
try {
    $response = $this->client->extract($content);
} catch (RateLimitException $e) {
    // Action Scheduler catches this and applies backoff:
    // Retry 1: 1 minute
    // Retry 2: 5 minutes  
    // Retry 3: 1 hour
    throw $e;
}
```

### 13.4 Performance Targets

| Metric | Target | Implementation |
|--------|--------|----------------|
| Schema injection | 0ms | Pre-computed in `post_meta` |
| Admin page load | <500ms | React SPA with lazy loading |
| Batch processing | 5-50 posts/batch | Dynamic sizing |
| Entity search | <100ms | Indexed `slug` column |
| Propagation batch | 50 posts | Fixed for predictability |

---

## 14. WordPress Hooks & Filters

### 14.1 Actions

```php
// Fired after successful entity extraction
do_action('vibe_ai_entities_extracted', int $post_id, array $entities);

// Fired after entity update
do_action('vibe_ai_entity_updated', int $entity_id, array $changes);

// Fired after merge operation
do_action('vibe_ai_entities_merged', int $target_id, array $source_ids, array $affected_posts);

// Fired when propagation completes
do_action('vibe_ai_entity_propagation_complete', int $entity_id);

// Fired on pipeline phase change
do_action('vibe_ai_pipeline_phase_changed', string $phase, array $stats);
```

### 14.2 Filters

```php
// Modify AI system prompt
apply_filters('vibe_ai_system_prompt', string $prompt);

// Filter entities before storage
apply_filters('vibe_ai_extracted_entities', array $entities, int $post_id);

// Modify Schema.org output
apply_filters('vibe_ai_schema_json', array $schema, int $post_id);

// Filter post types to process
apply_filters('vibe_ai_post_types', array $post_types); // Default: ['post', 'page']

// Adjust confidence threshold
apply_filters('vibe_ai_confidence_threshold', float $threshold); // Default: 0.6
```

---

## 15. Error Handling

### 15.1 Error Codes

| Code | Meaning | Recovery |
|------|---------|----------|
| `E001` | API rate limit exceeded | Auto-retry with backoff |
| `E002` | Invalid JSON from AI | Log & skip post, flag for manual |
| `E003` | Database constraint violation | Log & investigate |
| `E004` | Entity not found | Return 404, no retry |
| `E005` | Propagation timeout | Resume from checkpoint |

### 15.2 Logging

```php
// Log levels: debug, info, warning, error
vibe_ai_log('info', 'Extraction complete', [
    'post_id' => 123,
    'entities_found' => 5,
    'duration_ms' => 1250
]);
```

Logs stored in: `wp-content/uploads/vibe-ai-logs/YYYY-MM-DD.log`

---

## 16. Development Roadmap

### Phase 1: The Core (Days 1-2)

| Task | Deliverables |
|------|--------------|
| Scaffold Plugin | `composer init`, `npm init`, directory structure |
| SQL Installer | `dbDelta` for custom tables |
| GraphRepository | `EntityRepository`, `AliasResolver` base classes |
| Action Scheduler | Vendor bundling, wrapper class |

### Phase 2: The Extractor (Days 3-4)

| Task | Deliverables |
|------|--------------|
| EntityExtractor | Service class with OpenRouter integration |
| AIClient | HTTP client, retry logic, response parsing |
| Phase 1 Logic | Post queuing, content preparation |
| Unit Tests | JSON parsing, entity normalization |

### Phase 3: The UI (Days 5-7)

| Task | Deliverables |
|------|--------------|
| React Dashboard | Recharts for stats, phase stepper |
| Entity Grid | TanStack Table, inline editing |
| REST Endpoints | `/status`, `/entities`, `/pipeline/*` |
| API Integration | Polling, optimistic updates |

### Phase 4: Schema & Invalidation (Days 8-9)

| Task | Deliverables |
|------|--------------|
| SchemaGenerator | JSON-LD builder, type mapping |
| CacheInvalidator | Chain-Link propagation logic |
| Frontend Injection | `wp_head` hook, safe encoding |

### Phase 5: The "Vibe" Polish (Day 10)

| Task | Deliverables |
|------|--------------|
| UX Polish | Loading states, animations, transitions |
| Error States | User-friendly error messages |
| Security Audit | Penetration testing, capability checks |
| Documentation | README, inline docs, API reference |

---

## 17. Future Considerations (Phase 2+)

| Feature | Priority | Complexity |
|---------|----------|------------|
| Entity-to-Entity Relationships | High | Medium |
| Vector Embeddings for Semantic Search | Medium | High |
| Wikidata Auto-Enrichment | Medium | Medium |
| Content Auto-Linking | Low | Medium |
| Multi-site Support | Low | High |
| GraphQL API | Low | Medium |

---

## Appendix A: Configuration Constants

```php
<?php
// config/constants.php

namespace Vibe\AIIndex;

class Config {
    // Processing
    public const BATCH_SIZE = 50;
    public const MAX_CONCURRENT_BATCHES = 3;
    public const PROPAGATION_BATCH_SIZE = 50;

    // AI
    public const DEFAULT_MODEL = 'anthropic/claude-3.5-sonnet';
    public const FALLBACK_MODEL = 'openai/gpt-4o-mini';
    public const MAX_TOKENS = 4096;
    public const TEMPERATURE = 0.1;

    // Thresholds
    public const CONFIDENCE_HIGH = 0.85;
    public const CONFIDENCE_MEDIUM = 0.60;
    public const CONFIDENCE_LOW = 0.40;
    public const SCHEMA_MIN_CONFIDENCE = 0.60;

    // Limits
    public const MAX_ENTITIES_PER_POST = 50;
    public const MAX_CONTEXT_LENGTH = 500;
    public const MAX_ALIASES_PER_ENTITY = 20;

    // Cache
    public const SCHEMA_CACHE_VERSION = 1;
    public const PROPAGATION_TIMEOUT = 3600; // 1 hour
}
```

---

## Appendix B: Sample AI Response

**Input Content:**
> "Sam Altman announced that OpenAI's latest model, GPT-5, will be released in San Francisco next month."

**AI Response:**
```json
{
  "entities": [
    {
      "name": "Sam Altman",
      "type": "PERSON",
      "confidence": 0.98,
      "context": "Sam Altman announced that OpenAI's latest model",
      "aliases": []
    },
    {
      "name": "OpenAI",
      "type": "ORG",
      "confidence": 0.99,
      "context": "OpenAI's latest model, GPT-5",
      "aliases": ["Open AI"]
    },
    {
      "name": "GPT-5",
      "type": "SOFTWARE",
      "confidence": 0.95,
      "context": "latest model, GPT-5, will be released",
      "aliases": ["GPT5", "GPT 5"]
    },
    {
      "name": "San Francisco",
      "type": "LOCATION",
      "confidence": 0.97,
      "context": "released in San Francisco next month",
      "aliases": ["SF", "San Fran"]
    }
  ]
}
```

---

## Final Deliverable Checklist

| Requirement | Status |
|-------------|--------|
| Spec-First | ✅ No code written yet |
| Strict SQL | ✅ Foreign keys, indexes defined |
| 6-Phase Pipeline | ✅ Sequential job runner specified |
| Chain-Link Invalidation | ✅ Recursive batching strategy |
| Security Audit | ✅ Panic Policy defined |
| UI Logic | ✅ Component states specified |
| Rate Limiting | ✅ Dynamic batching + backoff |
| Schema.org Mapping | ✅ Type mappings documented |
| Alias Resolution | ✅ Deduplication strategy |
| WordPress Integration | ✅ Hooks & filters defined |

---

**Document Status:** ✅ Final Review Complete  
**Architect Sign-off:** READY FOR IMPLEMENTATION
