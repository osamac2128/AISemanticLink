# AI Entity Index

**Semantic Truth Layer for WordPress** - Extract, normalize, and link named entities with Schema.org JSON-LD output. Includes RAG-ready Knowledge Base.

## Features

### Phase 1: Entity Extraction
- 6-phase pipeline with Action Scheduler
- 10 entity types (Person, Organization, Location, etc.)
- Schema.org JSON-LD injection
- Alias resolution and deduplication
- Chain-link cache invalidation

### Phase 2: Knowledge Base (RAG)
- Semantic chunking by heading boundaries
- OpenRouter embeddings integration
- MySQL vector storage (adapter-ready)
- Semantic search API
- AI publishing: llms.txt, AI sitemap, change feed

## Requirements

- WordPress 6.0+
- PHP 8.1+
- MySQL 8.0+ / MariaDB 10.6+
- OpenRouter API key

## Installation

### Option 1: Download Release (Recommended)

1. Download the latest release zip from GitHub Releases
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Select the zip file and click "Install Now"
4. Activate the plugin
5. Add your API key to `wp-config.php`:

```php
define('VIBE_AI_OPENROUTER_KEY', 'sk-or-your-key-here');
```

### Option 2: Build from Source

```bash
# Clone the repository
git clone https://github.com/your-repo/ai-entity-index.git
cd ai-entity-index

# Run the build script
./build.sh

# The zip file will be in dist/ai-entity-index-1.0.0.zip
```

#### Build Requirements

- Composer 2.x
- Node.js 18+ and npm
- zip command

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
| GET | `/entities` | List entities |
| POST | `/pipeline/start` | Start extraction |
| POST | `/pipeline/stop` | Stop pipeline |

### Knowledge Base Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/kb/search` | Semantic search |
| GET | `/kb/status` | KB status |
| GET | `/kb/docs` | List documents |
| POST | `/kb/reindex` | Trigger reindex |

## Directory Structure

```
ai-entity-index/
├── ai-entity-index.php      # Main plugin file
├── includes/
│   ├── Config.php           # Configuration
│   ├── Activator.php        # Activation/tables
│   ├── Plugin.php           # Main class
│   ├── Pipeline/            # Pipeline managers
│   ├── Jobs/                # Background jobs
│   ├── Repositories/        # Data access
│   ├── Services/            # Business logic
│   └── REST/                # API controllers
├── admin/js/src/            # React admin UI
├── vendor/                  # Composer dependencies
└── build.sh                 # Build script
```

## License

**PROPRIETARY SOFTWARE - ALL RIGHTS RESERVED**

Copyright (c) 2026 Vibe Architect. All Rights Reserved.

This software is licensed under a proprietary license. See the [LICENSE](LICENSE) file for details.

Unauthorized copying, modification, distribution, or use of this software is strictly prohibited.
