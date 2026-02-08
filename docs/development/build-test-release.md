# Build, Test, and Release

> Docs home: `docs/index.md`

## Frontend scripts

From `package.json`:

- `npm run start` - dev watch build
- `npm run build` - production build
- `npm run lint` - JS lint
- `npm run lint:css` - CSS lint
- `npm run format` - formatting

## PHP dependencies

From `composer.json`:

- Runtime: `woocommerce/action-scheduler`
- Dev: `phpunit/phpunit`

## Distribution build

Use `build.sh` to produce distributable zip with:

- production Composer deps (`--no-dev`)
- built admin assets
- runtime files only

Output: `dist/ai-entity-index-{version}.zip`

## Release checklist

1. Build frontend assets.
2. Run automated tests/lint checks.
3. Validate activation + basic REST smoke test.
4. Generate zip via `build.sh`.
5. Install zip in clean WordPress instance and re-test critical flows.

## Metadata consistency check

Before release, verify license/version consistency across:

- `ai-entity-index.php`
- `composer.json`
- `readme.txt`
- `README.md`
