# AI Entity Index - Claude Project Guide

## Project Overview

**AI Entity Index** is a WordPress plugin that creates a "Semantic Truth Layer" by extracting, normalizing, and linking named entities (People, Organizations, Locations, Products, Concepts) from content and injecting Schema.org JSON-LD for AI/LLM discoverability.

**Plugin Directory:** `/home/user/AISemanticLink/ai-entity-index/`

## License

**PROPRIETARY SOFTWARE - ALL RIGHTS RESERVED**

Copyright (c) 2026 Vibe Architect. All Rights Reserved.

This software is licensed under a proprietary license that:
- **PROHIBITS** copying, reproducing, or duplicating the software
- **PROHIBITS** modifying, adapting, or creating derivative works
- **PROHIBITS** distributing, sublicensing, or transferring to third parties
- **PROHIBITS** reverse engineering or decompiling
- **PROHIBITS** sharing code in public repositories

See the `LICENSE` file for complete terms.

## Tech Stack

| Layer | Technology | Version |
|-------|------------|---------|
| Backend | PHP | 8.1+ (strict_types) |
| Database | MySQL/MariaDB | 8.0+/10.6+ (InnoDB) |
| Queue | Action Scheduler | 3.7+ |
| Frontend | React | 18.x |
| Styling | Tailwind CSS | 3.x (JIT) |
| Tables | TanStack Table | 8.x |
| Charts | Recharts | 2.x |
| AI Gateway | OpenRouter API | Model-agnostic |

## Architecture

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
```

## Directory Structure

```
ai-entity-index/
├── ai-entity-index.php          # Main plugin entry point
├── composer.json                # PHP dependencies (Action Scheduler)
├── package.json                 # Node.js dependencies (React, Tailwind)
├── tailwind.config.js           # Tailwind JIT configuration
├── postcss.config.js            # PostCSS configuration
│
├── includes/
│   ├── Config.php               # Configuration constants
│   ├── Activator.php            # Database schema & activation
│   ├── Plugin.php               # Main plugin class
│   ├── Logger.php               # Logging utility
│   │
│   ├── Repositories/
│   │   ├── EntityRepository.php # Entity CRUD operations
│   │   └── MentionRepository.php# Mention operations
│   │
│   ├── Services/
│   │   ├── AIClient.php         # OpenRouter API client
│   │   ├── EntityExtractor.php  # Entity extraction from content
│   │   ├── BatchSizeManager.php # Dynamic batch sizing
│   │   ├── SchemaGenerator.php  # JSON-LD generation
│   │   ├── SchemaInjector.php   # wp_head injection
│   │   ├── CacheInvalidator.php # Cache invalidation coordination
│   │   └── Exceptions/
│   │       └── RateLimitException.php
│   │
│   ├── Pipeline/
│   │   └── PipelineManager.php  # 6-phase orchestration
│   │
│   ├── Jobs/
│   │   ├── PreparationJob.php   # Phase 1
│   │   ├── ExtractionJob.php    # Phase 2
│   │   ├── DeduplicationJob.php # Phase 3
│   │   ├── LinkingJob.php       # Phase 4
│   │   ├── IndexingJob.php      # Phase 5
│   │   ├── SchemaBuildJob.php   # Phase 6
│   │   └── PropagateEntityChangeJob.php # Chain-Link invalidation
│   │
│   └── REST/
│       └── RestController.php   # REST API endpoints
│
└── admin/js/src/
    ├── index.jsx                # React entry point
    ├── App.jsx                  # Main app with routing
    ├── api/
    │   └── client.js            # API client
    ├── hooks/
    │   ├── useStatus.js         # Pipeline status polling
    │   ├── useEntities.js       # Entity fetching
    │   └── usePipeline.js       # Pipeline control
    ├── styles/
    │   └── tailwind.css
    └── components/
        ├── Dashboard/           # Pipeline monitoring
        ├── EntityManager/       # Entity grid & management
        ├── EntityDrawer/        # Entity detail panel
        ├── Settings/            # Configuration
        ├── ActivityLog/         # Job execution logs
        ├── Layout/              # Header & Sidebar
        └── common/              # Badge, Button, Card, Spinner
```

## Database Schema

### wp_ai_entities (Canonical Truth)
```sql
id, name, slug (UNIQUE), type, schema_type, description,
same_as_url, wikidata_id, status, mention_count,
created_at, updated_at
```

### wp_ai_mentions (Entity-Post Edges)
```sql
id, entity_id (FK), post_id (FK), confidence, context_snippet,
is_primary, created_at
UNIQUE KEY: (entity_id, post_id)
```

### wp_ai_aliases (Surface Form Resolution)
```sql
id, canonical_id (FK), alias, alias_slug (UNIQUE), source, created_at
```

### Post Meta Keys
- `_vibe_ai_schema_cache` - Pre-computed JSON-LD
- `_vibe_ai_extracted_at` - Last extraction timestamp
- `_vibe_ai_schema_version` - Cache version

## Entity Types

| Internal Type | Schema.org Type | Example |
|---------------|-----------------|---------|
| `PERSON` | Person | Sam Altman |
| `ORG` | Organization | OpenAI |
| `COMPANY` | Corporation | Microsoft |
| `LOCATION` | Place | San Francisco |
| `COUNTRY` | Country | United States |
| `PRODUCT` | Product | iPhone |
| `SOFTWARE` | SoftwareApplication | WordPress |
| `EVENT` | Event | WWDC 2025 |
| `WORK` | CreativeWork | The Great Gatsby |
| `CONCEPT` | Thing | Machine Learning |

## Confidence Thresholds

| Tier | Range | Action |
|------|-------|--------|
| High | 0.85-1.0 | Auto-approve, include in Schema |
| Medium | 0.60-0.84 | Include in Schema, flag for review |
| Low | 0.40-0.59 | Store but exclude from Schema |
| Reject | 0.0-0.39 | Discard, do not store |

## REST API Endpoints

Base: `/wp-json/vibe-ai/v1/`

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/status` | GET | Pipeline status & progress |
| `/entities` | GET | List entities (paginated) |
| `/entities/{id}` | GET | Single entity detail |
| `/entities/{id}` | PATCH | Update entity |
| `/entities/{id}` | DELETE | Delete entity |
| `/entities/merge` | POST | Merge duplicates |
| `/entities/{id}/aliases` | POST | Add alias |
| `/entities/{id}/aliases/{alias_id}` | DELETE | Remove alias |
| `/pipeline/start` | POST | Start extraction |
| `/pipeline/stop` | POST | Cancel jobs |
| `/logs` | GET | Activity logs |

## WordPress Hooks

### Actions
```php
do_action('vibe_ai_entities_extracted', int $post_id, array $entities);
do_action('vibe_ai_entity_updated', int $entity_id, array $changes);
do_action('vibe_ai_entities_merged', int $target_id, array $source_ids, array $affected_posts);
do_action('vibe_ai_entity_propagation_complete', int $entity_id);
do_action('vibe_ai_pipeline_phase_changed', string $phase, array $stats);
```

### Filters
```php
apply_filters('vibe_ai_system_prompt', string $prompt);
apply_filters('vibe_ai_extracted_entities', array $entities, int $post_id);
apply_filters('vibe_ai_schema_json', array $schema, int $post_id);
apply_filters('vibe_ai_post_types', array $post_types);
apply_filters('vibe_ai_confidence_threshold', float $threshold);
```

## Configuration

### wp-config.php (Required)
```php
define('VIBE_AI_OPENROUTER_KEY', 'sk-or-...');
define('VIBE_AI_ENCRYPTION_KEY', 'random-32-byte-string');
```

### Key Constants (includes/Config.php)
```php
BATCH_SIZE = 50;
MAX_CONCURRENT_BATCHES = 3;
PROPAGATION_BATCH_SIZE = 50;
DEFAULT_MODEL = 'anthropic/claude-3.5-sonnet';
CONFIDENCE_HIGH = 0.85;
CONFIDENCE_MEDIUM = 0.60;
CONFIDENCE_LOW = 0.40;
MAX_ENTITIES_PER_POST = 50;
MAX_CONTEXT_LENGTH = 500;
MAX_ALIASES_PER_ENTITY = 20;
```

## Key Patterns

### Chain-Link Cache Invalidation
When an entity is updated, the `PropagateEntityChangeJob` recursively schedules batches of 50 posts to regenerate their Schema cache. Uses transients to track propagation status (`vibe_ai_propagating_{entity_id}`).

### Dynamic Batch Sizing
`BatchSizeManager` adjusts batch size between 5-50 based on processing time:
- < 2s average: increase by 5
- > 10s average: decrease by 5

### AI System Prompt
Located in `EntityExtractor.php`, the system prompt instructs the AI to:
1. Extract ONLY named entities (proper nouns)
2. Normalize names to canonical form
3. Resolve ambiguity using context
4. Return strict JSON format with name, type, confidence, context, aliases

## Development Commands

```bash
# Install PHP dependencies
cd ai-entity-index && composer install

# Install Node dependencies
npm install

# Build React admin
npm run build

# Development mode with watch
npm start
```

## Namespaces

All PHP classes use namespace `Vibe\AIIndex\*`:
- `Vibe\AIIndex\Config`
- `Vibe\AIIndex\Repositories\EntityRepository`
- `Vibe\AIIndex\Services\AIClient`
- `Vibe\AIIndex\Jobs\ExtractionJob`
- `Vibe\AIIndex\Pipeline\PipelineManager`
- `Vibe\AIIndex\REST\RestController`

## Security Checklist

- All endpoints require `manage_options` capability
- API keys NEVER in database, NEVER logged
- Input sanitization: `sanitize_text_field()`, `sanitize_title()`, `wp_kses_post()`
- Output escaping: `esc_html()`, `wp_json_encode()` with `JSON_HEX_TAG`
- Prepared statements for all SQL queries
- Nonce verification for React API calls

## Error Codes

| Code | Meaning | Recovery |
|------|---------|----------|
| E001 | API rate limit | Auto-retry with backoff |
| E002 | Invalid JSON from AI | Log & skip, flag for manual |
| E003 | Database constraint | Log & investigate |
| E004 | Entity not found | Return 404 |
| E005 | Propagation timeout | Resume from checkpoint |

## Logs

Location: `wp-content/uploads/vibe-ai-logs/YYYY-MM-DD.log`

Levels: debug, info, warning, error

API calls logged with duration for monitoring.
