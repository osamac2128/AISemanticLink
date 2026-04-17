# AI Entity Index

**Semantic Truth Layer for WordPress**

![Version](https://img.shields.io/badge/version-1.0.8-blue) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple) ![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue) ![License](https://img.shields.io/badge/license-Proprietary-red)

Extract, normalize, and link named entities with Schema.org JSON-LD output. Build a RAG-ready Knowledge Base with semantic search and AI publishing.

## Overview

AI Entity Index is a **Semantic Truth Layer** for WordPress. It reads your content, identifies named entities, normalizes them into a structured index, and emits Schema.org JSON-LD — making your site legible to both search engines and AI agents.

The plugin has two major subsystems:

| Subsystem | Purpose |
|-----------|---------|
| **Entity Extraction** | Discovers and links named entities across all published content |
| **Knowledge Base** | Chunks, embeds, and indexes content for semantic search and AI consumption |

## Key Features

### Entity Extraction

- **6-phase extraction pipeline** — content is fetched, sent to AI, parsed, deduplicated, linked to posts, and injected as JSON-LD
- **10 entity types** — Person, Organization, Location, Event, Product, CreativeWork, Concept, Technology, Law, MedicalCondition — each mapped to Schema.org
- **Schema.org JSON-LD injection** — automatically added to page `<head>` for linked entities
- **Alias resolution and deduplication** — "OpenAI", "OpenAI Inc.", and "OpenAI, Inc." resolve to a single entity
- **Chain-link cache invalidation** — editing an entity propagates changes to every post that mentions it
- **Background processing** — powered by Action Scheduler for reliable, non-blocking extraction

### Knowledge Base (RAG)

- **Semantic chunking** — content is split by heading boundaries into coherent passages
- **Vector embeddings** — generated via OpenRouter, stored in MySQL with a `VectorStoreInterface` adapter pattern
- **Semantic search API** — query your content by meaning, not keywords
- **AI publishing** — automatic `llms.txt`, AI sitemap, and change feed endpoints make your content discoverable by AI agents

### Admin UI

- **React SPA** — modern single-page admin built with React, TanStack Query, and TanStack Table
- **Dashboard** — pipeline status, entity counts, recent activity
- **Entity Manager** — browse, search, merge, bulk-edit entities with an inline drawer
- **KB Manager** — Overview, Documents, Test Search, Settings, Logs
- **Settings** — API configuration, pipeline tuning, logging controls

## Requirements

| Requirement | Minimum Version |
|-------------|----------------|
| WordPress | 6.0+ |
| PHP | 8.1+ |
| MySQL / MariaDB | 8.0+ / 10.6+ |
| OpenRouter API key | Any plan |

## Installation

### Option 1: Download Release (Recommended)

1. Download the latest release zip from GitHub Releases
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Select the zip file and click **Install Now**
4. Activate the plugin
5. Add your API key to `wp-config.php`:

```php
define('VIBE_AI_OPENROUTER_KEY', 'sk-or-your-key-here');
```

### Option 2: Build from Source

```bash
git clone https://github.com/your-repo/ai-entity-index.git
cd ai-entity-index
./build.sh
# Zip file output: dist/ai-entity-index-1.0.8.zip
```

#### Build Requirements

- Composer 2.x
- Node.js 18+ and npm
- `zip` command

## Configuration

Add these constants to your `wp-config.php`:

```php
// Required: OpenRouter API key
define('VIBE_AI_OPENROUTER_KEY', 'sk-or-your-key-here');

// Optional: Encryption key for sensitive data
define('VIBE_AI_ENCRYPTION_KEY', 'random-32-byte-string');
```

## REST API

Base URL: `/wp-json/vibe-ai/v1/`

### Entity Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/status` | Pipeline status |
| GET | `/entities` | List entities (paginated, filterable) |
| PUT/PATCH | `/entities/{id}` | Update entity |
| DELETE | `/entities/{id}` | Delete entity |
| POST | `/entities/merge` | Merge entities |
| POST | `/pipeline/start` | Start extraction pipeline |
| POST | `/pipeline/stop` | Stop pipeline |
| GET | `/settings` | Get plugin settings |
| POST/PUT/PATCH | `/settings` | Update settings |
| GET | `/logs` | Get activity logs |

### Knowledge Base Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/kb/status` | KB pipeline status |
| GET | `/kb/docs` | List indexed documents |
| POST | `/kb/search` | Semantic search |
| POST | `/kb/reindex` | Trigger full reindex |
| POST/PUT/PATCH | `/kb/pinned-pages` | Update pinned pages |

### AI Publishing Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/llms.txt` | Machine-readable content summary |
| GET | `/ai-sitemap` | AI-optimized sitemap |
| GET | `/changes` | Change feed for AI crawlers |

## Architecture

```
Trigger (save_post / manual)
  → Pipeline Manager (orchestration)
    → Action Scheduler (background jobs)
      → AI Client (OpenRouter)
        → EntityExtractor / KB Chunker
          → Repository (MySQL)
            → JSON-LD / Vector Store
```

| Layer | Responsibility |
|-------|---------------|
| **Trigger** | Hook into WordPress publish/save events |
| **Pipeline** | Coordinate extraction phases, handle failures |
| **Action Scheduler** | Queue and run jobs asynchronously |
| **AI Client** | Communicate with OpenRouter, handle rate limits and retries |
| **Repository** | CRUD operations against custom tables |
| **Output** | JSON-LD injection, vector embeddings, AI publishing files |

## Development Setup

```bash
# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Start development server (watches for changes)
npm start

# Or build for production
npm run build
```

### Useful Commands

| Command | Purpose |
|---------|---------|
| `npm start` | Start WP Scripts dev server with hot reload |
| `npm run build` | Production build of React admin UI |
| `npm run lint` | Run ESLint on JS/JSX source |
| `./vendor/bin/phpunit` | Run PHP test suite |

## Documentation

Full documentation lives in the [`docs/`](docs/) directory:

| Document | Description |
|----------|-------------|
| [`docs/index.md`](docs/index.md) | Documentation home and navigation |
| [`docs/api/`](docs/api/) | REST API reference |
| [`docs/changelog/`](docs/changelog/) | Changelog and implementation drift |
| [`docs/architecture/`](docs/architecture/) | System architecture and design decisions |

## License

**PROPRIETARY SOFTWARE — ALL RIGHTS RESERVED**

Copyright (c) 2026 Vibe Architect. All Rights Reserved.

This software is licensed under a proprietary license. See the [LICENSE](LICENSE) file for details.

Unauthorized copying, modification, distribution, or use of this software is strictly prohibited.
