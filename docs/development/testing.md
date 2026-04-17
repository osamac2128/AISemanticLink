# Testing Strategy

> Docs home: `docs/index.md`

## Overview

The plugin currently relies on fast unit-style PHPUnit coverage plus repo-level build checks:

- `phpunit` for PHP services, repositories, jobs, and schema behavior
- `php -l` for syntax validation across plugin PHP files
- `npm run lint` for the React admin
- `npm run lint:css` for CSS and Tailwind-facing styles
- `npm run build` for production admin bundles

This gives strong code-level confidence, but it is not a substitute for live WordPress verification. Browser E2E, real plugin interoperability checks, and integration tests against a running WordPress stack are still recommended before release.

## Test Layout

- PHP tests live in `tests/unit/`
- Bootstrap file: `tests/bootstrap.php`
- Namespace: `Vibe\AIIndex\Tests\Unit`
- Composer autoload maps production classes from `includes/`

The current suite covers:

- AI client retry and error handling
- entity/config helpers
- KB chunking and embedding jobs
- schema injection
- schema graph generation
- semantic health/reporting aggregation

## Test Harness

`tests/bootstrap.php` provides a lightweight WordPress shim so service-level code can be exercised without booting a real site.

Common mocked surfaces include:

- options: `get_option`, `update_option`, `delete_option`
- posts and users: `get_post`, `get_permalink`, `get_userdata`
- post meta: `get_post_meta`, `update_post_meta`, `delete_post_meta`
- schema helpers: `get_bloginfo`, `get_site_url`, `get_the_date`, `get_the_modified_date`
- media helpers: `has_post_thumbnail`, `get_the_post_thumbnail_url`, `get_site_icon_url`
- HTTP helpers: `wp_remote_post`, response extraction helpers
- Action Scheduler shim: `as_schedule_single_action`

Tests should keep using `$GLOBALS`-backed fixtures such as:

- `$GLOBALS['mock_options']`
- `$GLOBALS['mock_posts']`
- `$GLOBALS['mock_post_meta']`
- `$GLOBALS['mock_users']`
- `$GLOBALS['mock_bloginfo']`

## Writing Tests

Recommended approach:

1. Extend `PHPUnit\Framework\TestCase`.
2. Prefer mocks or narrow anonymous subclasses over broad fake environments.
3. Seed only the globals required for the behavior under test.
4. Clean up globals in `tearDown()`.
5. Keep each test focused on one behavior or one failure mode.

Good candidates for future coverage:

- REST controller response contracts
- `PipelineManager` and `KBPipelineManager` transitions
- public crawler endpoint cache and conditional request behavior
- WordPress compatibility with Yoast, Rank Math, and WooCommerce

## Running Checks

```bash
# PHP unit suite
php .\phpunit.phar -c phpunit.xml

# PHP syntax
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }

# Admin linting
npm run lint
npm run lint:css

# Production admin bundle
npm run build
```

## Current Gaps

These areas are still only partially verified:

- real WordPress activation/upgrade flows
- browser/admin smoke coverage
- SEO plugin interoperability in a live site
- end-to-end validation of `/llms.txt`, `/ai-sitemap`, and `/changes` through rewrites

Those gaps are product-verification work, not just unit-test work, and should be part of release readiness.
