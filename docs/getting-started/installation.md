# Installation

> Docs home: `docs/index.md`

## Requirements

- WordPress `6.0+`
- PHP `8.1+`
- MySQL `8.0+` or MariaDB `10.6+`
- OpenRouter API key (`VIBE_AI_OPENROUTER_KEY`)

## Install from source

1. Place plugin code in your WordPress plugins directory.
2. Install PHP dependencies:

```bash
composer install
```

3. Install frontend dependencies and build admin assets:

```bash
npm install
npm run build
```

4. Activate plugin in WordPress admin.

## Dependency behavior

- The bootstrap checks `vendor/autoload.php` before loading (`ai-entity-index.php`).
- If dependencies are missing, the plugin exits early and shows an admin notice.

## Activation behavior

On activation (`includes/Activator.php`):

- Core tables are created or updated.
- KB tables are created or updated.
- Defaults are seeded in `wp_options`.
- Log directory is created under uploads.
- Daily cleanup cron is scheduled.
