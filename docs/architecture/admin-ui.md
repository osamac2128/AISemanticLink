# Admin UI Architecture

> Docs home: `docs/index.md`

## Technology stack

| Layer | Technology | Version |
|-------|-----------|---------|
| UI framework | React | 18.3 (createRoot API) |
| Routing | React Router DOM | v6 (HashRouter) |
| Server state | TanStack React Query | v5 |
| Data tables | TanStack React Table | v8 |
| Charts | Recharts | v2 |
| Styling | Tailwind CSS | 3.4 (JIT, scoped to `#vibe-ai-admin`) |
| Build | @wordpress/scripts | Webpack + Babel |
| Post-processing | PostCSS + Autoprefixer | — |

## Build pipeline

`@wordpress/scripts` provides the Webpack configuration and Babel transpilation. PostCSS runs Autoprefixer on the Tailwind output.

**Build output** in `admin/js/build/`:

| File | Contents |
|------|----------|
| `index.jsx.js` | Bundled JavaScript |
| `index.jsx.css` | Extracted stylesheet |
| `index.jsx.asset.php` | WordPress dependency manifest |

```bash
npm start        # Dev server with hot reload
npm run build    # Production build
npm run lint     # ESLint
npm run format   # Prettier
```

## Component architecture

```
index.jsx (mount point)
└── App.jsx (QueryClientProvider + HashRouter + Layout)
    ├── Header.jsx
    ├── Sidebar.jsx (collapsible navigation)
    └── Routes
        ├── Dashboard/
        │   ├── StatsCards.jsx
        │   ├── PhaseStepper.jsx
        │   ├── PulseBar.jsx
        │   └── LiveTerminal.jsx
        ├── EntityManager/
        │   ├── EntityTable.jsx (TanStack Table)
        │   ├── Filters.jsx
        │   ├── BulkActions.jsx
        │   └── MergeModal.jsx
        ├── EntityDrawer/ (right slide-over)
        │   ├── IdentitySection.jsx
        │   ├── SemanticLinksSection.jsx (Wikipedia API)
        │   ├── AliasesSection.jsx
        │   ├── MentionsSection.jsx
        │   └── ActionButtons.jsx
        ├── KnowledgeBase/
        │   ├── KBOverview.jsx
        │   ├── KBDocuments.jsx (TanStack Table)
        │   ├── KBTestSearch.jsx
        │   ├── KBSettings.jsx
        │   ├── KBLogs.jsx
        │   └── ChunkViewer.jsx
        ├── Settings/
        ├── ActivityLog/
        └── common/ (Badge, Button, Card, Spinner)
```

## Routing

HashRouter is used for WordPress admin compatibility (avoids conflicts with WP admin routing).

| Route | Component |
|-------|-----------|
| `/` | Redirect to `/dashboard` |
| `/dashboard` | Dashboard |
| `/entities` | EntityManager |
| `/entities/:id` | EntityManager with drawer open |
| `/settings` | Settings |
| `/logs` | ActivityLog |
| `/kb/` | KBOverview |
| `/kb/documents` | KBDocuments |
| `/kb/search` | KBTestSearch |
| `/kb/settings` | KBSettings |
| `/kb/logs` | KBLogs |

## State management

### Server state — TanStack React Query

- 5-minute stale time
- 1 retry on failure
- No refetch on window focus
- Query keys follow entity structure (e.g., `['entities', { page, filters }]`)

### UI state — React useState

- Sidebar collapse toggle
- Entity selection (opens drawer)
- Form inputs and local toggles

No global state store (Zustand, Redux, etc.) is used.

## API client

`api/client.js` is a base fetch wrapper:

- All requests include the `X-WP-Nonce` header for WordPress REST authentication.
- `Content-Type: application/json` is set **only** on requests with a body. This avoids ModSecurity 403 errors on GET/DELETE requests.
- All endpoint methods return parsed JSON.

## Custom hooks

| Hook | Purpose |
|------|---------|
| `useStatus` | Polls pipeline status at 2-second intervals when pipeline is running |
| `usePipeline` | Start/stop control with current phase tracking |
| `useEntities` | Paginated entity list with CRUD operations |
| `useKB` | React Query hooks for all KB operations (documents, search, settings, logs) |

## WordPress integration

### Mount point

The SPA mounts to `#vibe-ai-admin`, a container element rendered by `AdminRenderer.php`.

### Localized data

`wp_localize_script` injects configuration into `window.vibeAiData`:

| Property | Contents |
|----------|----------|
| `apiUrl` | REST API base URL |
| `nonce` | WordPress REST nonce |
| `adminUrl` | WP admin URL |
| `pluginUrl` | Plugin asset base URL |
| `version` | Plugin version string |
| `pollingInterval` | Default polling interval in ms |

### Known issue — inconsistent global variable naming

Two different global names are referenced across the codebase:

| Global | Used by |
|--------|---------|
| `window.vibeAiData` | `App.jsx`, `api/client.js` |
| `window.vibeAI` | `EntityManager`, `EntityDrawer`, `Settings`, `ActivityLog` |

Both are set by `AdminRenderer.php`, but this inconsistency should be resolved to a single name.

## Related docs

- Architecture overview: `docs/architecture/overview.md`
- Component map: `docs/architecture/component-map.md`
- REST API surface: `docs/api/rest-overview.md`
- Local setup: `docs/development/local-setup.md`
- Build and release: `docs/development/build-test-release.md`
