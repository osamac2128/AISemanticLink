=== AI Entity Index ===
Contributors: vibearchitect
Tags: seo, schema, entities, ai, knowledge-graph, json-ld, rag
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 1.0.0
License: Proprietary
License URI: LICENSE

Semantic Truth Layer for WordPress - Extract, normalize, and link named entities with Schema.org JSON-LD output. Includes RAG-ready Knowledge Base.

== Description ==

AI Entity Index transforms your WordPress content into a queryable knowledge graph, improving AI/LLM discoverability through explicit entity markup and enabling semantic search capabilities.

**Core Features:**

* **Entity Extraction** - Automatically extract named entities (People, Organizations, Locations, Products, Concepts) from your content using AI
* **Schema.org JSON-LD** - Inject structured data for better search engine and AI understanding
* **Knowledge Base (RAG)** - Build a retrieval-ready knowledge base with semantic search
* **Stable Citations** - Every chunk has a stable anchor for precise citations

**Phase 1: Entity Extraction**

* 6-phase pipeline: Preparation → Extraction → Deduplication → Linking → Indexing → Schema Build
* Support for 10 entity types with Schema.org mapping
* Alias resolution and deduplication
* Chain-link cache invalidation for efficient updates

**Phase 2: Knowledge Base (RAG)**

* High-quality semantic chunking by heading boundaries
* OpenRouter embeddings integration
* MySQL vector storage (adapter-ready for external DBs)
* Semantic search API for RAG applications
* AI publishing: llms.txt, AI sitemap, change feed

== Installation ==

1. Download the plugin zip file
2. Go to WordPress Admin → Plugins → Add New
3. Click "Upload Plugin" and select the zip file
4. Click "Install Now" and then "Activate"
5. Add your OpenRouter API key to wp-config.php:

`define('VIBE_AI_OPENROUTER_KEY', 'your-api-key-here');`

== Frequently Asked Questions ==

= What AI models are supported? =

The plugin uses OpenRouter as a model-agnostic gateway. Supported models include:
* Anthropic Claude 3.5 Sonnet (recommended)
* OpenAI GPT-4o Mini
* Anthropic Claude 3 Haiku

= Is an API key required? =

Yes, you need an OpenRouter API key. Add it to your wp-config.php file.

= Can I use external vector databases? =

Version 1.0 includes a MySQL-based vector store. The architecture is adapter-ready for external databases like pgvector, Qdrant, or Pinecone in future versions.

== Changelog ==

= 1.0.0 =
* Initial release
* Phase 1: Complete entity extraction pipeline
* Phase 2: Knowledge Base (RAG) module
* React admin interface
* REST API endpoints

== Upgrade Notice ==

= 1.0.0 =
Initial release of AI Entity Index.
