# Local Setup

> Docs home: `docs/index.md`

## Prerequisites

- PHP 8.1+
- Composer 2+
- Node.js 18+
- WordPress local environment

## Setup commands

```bash
composer install
npm install
npm start
```

Use `npm run build` for production admin assets.

## Tests

- PHPUnit bootstrap exists at `tests/bootstrap.php`.
- Config tests live in `tests/unit/ConfigTest.php`.
- Run via your PHP test runner setup (based on `phpunit.xml.dist`).

## Local validation checklist

- Plugin activates without notices.
- REST routes are registered (`/vibe-ai/v1/*`).
- Entity pipeline start/stop/status works.
- KB status endpoint responds and respects `kb_enabled` flag.
- Admin UI loads without React query errors.
