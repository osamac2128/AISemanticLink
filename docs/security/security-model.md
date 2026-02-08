# Security Model

> Docs home: `docs/index.md`

## Access control

- Admin REST endpoints require `manage_options` through controller `permission_callback`s.
- Public endpoints are explicitly limited to AI publishing outputs:
  - `/kb/llms-txt`
  - `/kb/sitemap`
  - `/kb/feed`

## Secret handling

- OpenRouter key is loaded from `VIBE_AI_OPENROUTER_KEY` constant.
- Keys are not expected to be stored in plugin tables.
- Avoid logging request headers or raw credentials.

## Input/output controls

- REST params use typed args + sanitize/validate callbacks.
- SQL paths should use prepared queries in repositories.
- JSON-LD output is encoded via `wp_json_encode` with safe flags before injection.

## Prompt override risk

- `EntityExtractor` supports `vibe_ai_system_prompt` filter.
- The code checks capability context before applying prompt override hooks.
- Any plugin/theme with elevated execution can still influence behavior; treat this as trusted-admin scope.

## Recommended hardening

- Restrict admin access tightly.
- Rotate API keys periodically.
- Monitor logs for unusual extraction/search activity.
- Keep Action Scheduler and WordPress core up to date.
