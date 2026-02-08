# Configuration

> Docs home: `docs/index.md`

## Required constants

Add to `wp-config.php`:

```php
define('VIBE_AI_OPENROUTER_KEY', 'sk-or-...');
```

Optional:

```php
define('VIBE_AI_ENCRYPTION_KEY', 'random-32-byte-string');
```

## Runtime config model

- Hard defaults: `includes/Config.php`
- Operator overrides: `wp_options` (set by admin UI / REST)
- Request-time overrides: WordPress filters

## High-value options

- Entity pipeline:
  - `vibe_ai_model`
  - `vibe_ai_batch_size`
  - `vibe_ai_confidence_threshold`
  - `vibe_ai_post_types`
- KB pipeline:
  - `vibe_ai_kb_enabled`
  - `vibe_ai_kb_embedding_model`
  - `vibe_ai_kb_chunk_size`
  - `vibe_ai_kb_chunk_overlap`
  - `vibe_ai_kb_post_types`
  - `vibe_ai_kb_auto_index`

See `docs/data-model/options-and-meta.md` for full option and meta key coverage.
