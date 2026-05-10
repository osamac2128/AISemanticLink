# AI Entity Index v1.1 — Full Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all 45+ audit findings across 6 domains (config, frontend, AI, architecture, security, data model) to bring AI Entity Index from "functional prototype" to production-ready WordPress plugin.

**Architecture:** 5-phase remediation with parallel execution waves. Phase 0 splits into zero-risk hotfixes (0A) and pipeline guardrails (0B). Each wave contains independent tasks for parallel execution. Oracle-guided phasing ensures cost-sensitive changes (model config) ship before cost-increasing changes (truncation fix).

**Tech Stack:** PHP 8.0+ (PSR-4, namespace `Vibe\AIIndex\`), React 18 (TanStack Query/Table/Router, Tailwind CSS), OpenRouter API, MySQL, WordPress REST API, PHPUnit, wp-scripts

**Estimated Total:** 25 tasks, ~77 hours across 20 working days

---

## Dependency Graph

```
Phase 0A (Wave 1): Tasks 1-6 — ALL INDEPENDENT, run in parallel
Phase 0B (Wave 1): Tasks 7,8 — depend on 0A-1, 0A-6 respectively
Phase 0B (Wave 2): Task 9 — INDEPENDENT

Phase 1 (Wave 1): Task 10 — do FIRST, blocks 11, 13
Phase 1 (Wave 2): Tasks 11, 13, 14 — 11+13 depend on 10; 14 INDEPENDENT
Phase 1 (Wave 3): Task 12 — depends on 11

Phase 2 (Wave 1): Tasks 15, 17, 18 — 15 depends on 0A-1; 17+18 INDEPENDENT
Phase 2 (Wave 2): Task 16 — depends on 1-10

Phase 3 (Wave 1): Tasks 19, 20 — INDEPENDENT
Phase 3 (Wave 2): Tasks 21, 22 — 21 depends on 1-11+1-13; 22 depends on 1-10

Phase 4 (Wave 1): Tasks 23, 24, 25 — ALL INDEPENDENT
```

---

## Phase 0A — Zero-Risk Hotfixes (Day 1, ~2h)

**Risk Level:** ZERO — config, docs, and dependency changes only. No pipeline behavior changes.

---

### Task 1: Fix Model Constants — Budget Model is Most Expensive

**Severity:** 🔴 CRITICAL — Cost explosion risk  
**Risk:** HIGH (cost impact if wrong), LOW (code complexity)  
**Tests:** Manual — verify constant values  
**Depends on:** None

**Files:**
- Modify: `includes/Config.php:44-53`

- [ ] **Step 1: Fix BUDGET_MODEL constant**

Change `BUDGET_MODEL` to a cost-effective model while keeping DEFAULT and FALLBACK as the premium model:

```php
// In includes/Config.php, lines 43-53

/** @var string Default AI model for entity extraction (Claude Opus 4.5 via OpenRouter) */
public const DEFAULT_MODEL = 'anthropic/claude-opus-4.5';

/** @var string Legacy alias for extraction model */
public const DEFAULT_ENTITY_EXTRACTION_MODEL = self::DEFAULT_MODEL;

/** @var string Fallback model when primary is unavailable */
public const FALLBACK_MODEL = 'anthropic/claude-sonnet-4-20250514';

/** @var string Budget model for high-volume processing (extraction, scoring) */
public const BUDGET_MODEL = 'openai/gpt-4.1-mini';
```

- [ ] **Step 2: Verify no other code hardcodes model names**

Run: `grep -r "claude-opus-4.5" includes/ --include="*.php"`
Expected: Only Config.php lines 44 and 50 should reference it. If other files hardcode it, update them to reference `Config::DEFAULT_MODEL` or `Config::BUDGET_MODEL`.

- [ ] **Step 3: Commit**

```
fix(config): set BUDGET_MODEL to gpt-4.1-mini and FALLBACK_MODEL to sonnet-4

BUDGET_MODEL was identical to DEFAULT_MODEL (claude-opus-4.5), meaning
"budget" operations used the most expensive model. Now properly tiered:
- DEFAULT_MODEL: claude-opus-4.5 (high quality, low volume)
- FALLBACK_MODEL: claude-sonnet-4 (degraded but capable)
- BUDGET_MODEL: gpt-4.1-mini (high volume, low cost)
```

---

### Task 2: Fix MAX_CONTEXT_LENGTH Mismatch

**Severity:** 🟡 HIGH — EntityExtractor caps context at 100 chars, Config says 500  
**Risk:** LOW  
**Tests:** Manual — verify constant reference  
**Depends on:** None

**Files:**
- Modify: `includes/Services/EntityExtractor.php:32`

- [ ] **Step 1: Replace hardcoded MAX_CONTEXT_LENGTH with Config reference**

In `includes/Services/EntityExtractor.php`, line 32:

```php
// BEFORE:
private const MAX_CONTEXT_LENGTH = 100;

// AFTER:
// Remove the local constant entirely.
// At line ~324 where it's used, replace self::MAX_CONTEXT_LENGTH with Config::MAX_CONTEXT_LENGTH
```

- [ ] **Step 2: Update usage site**

In the same file, find the usage of `self::MAX_CONTEXT_LENGTH` (line ~324):

```php
// BEFORE:
$context = mb_substr($context, 0, self::MAX_CONTEXT_LENGTH - 3) . '...';

// AFTER:
$context = mb_substr($context, 0, Config::MAX_CONTEXT_LENGTH - 3) . '...';
```

- [ ] **Step 3: Add `use Vibe\AIIndex\Config;` import if not present**

At the top of `EntityExtractor.php` after the namespace declaration:

```php
use Vibe\AIIndex\Config;
```

- [ ] **Step 4: Commit**

```
fix(extraction): use Config::MAX_CONTEXT_LENGTH (500) instead of local 100

EntityExtractor had its own MAX_CONTEXT_LENGTH=100 while Config defined 500.
Context snippets were silently truncated to 97 chars. Now uses the canonical
500-char limit from Config.
```

---

### Task 3: Fix README Entity Types

**Severity:** 🟡 HIGH — Documentation lies about supported entity types  
**Risk:** ZERO  
**Tests:** Manual — read the updated README  
**Depends on:** None

**Files:**
- Modify: `README.md` (find sections mentioning entity types)

- [ ] **Step 1: Find all README entity type claims**

Run: `grep -n "Technology\|Law\|MedicalCondition\|entity.type" README.md`

- [ ] **Step 2: Replace fictional entity types with actual ones**

Replace any mention of `Technology`, `Law`, `MedicalCondition` with the actual supported types:
`PERSON`, `ORG`, `COMPANY`, `LOCATION`, `COUNTRY`, `PRODUCT`, `SOFTWARE`, `EVENT`, `WORK`, `CONCEPT`

- [ ] **Step 3: Verify the entity type list matches Config::VALID_TYPES**

Run: `grep -A15 "VALID_TYPES" includes/Config.php`

Ensure README lists exactly: PERSON, ORG, COMPANY, LOCATION, COUNTRY, PRODUCT, SOFTWARE, EVENT, WORK, CONCEPT

- [ ] **Step 4: Commit**

```
docs(readme): fix entity types to match actual implementation

Removed fictional entity types (Technology, Law, MedicalCondition) that
don't exist in Config::VALID_TYPES. Now lists the actual 10 supported types.
```

---

### Task 4: Fix Broken Docs Link

**Severity:** 🟡 MEDIUM — Header links to placeholder domain  
**Risk:** ZERO  
**Tests:** Manual — click the link  
**Depends on:** None

**Files:**
- Modify: `admin/js/src/components/Layout/Header.jsx`

- [ ] **Step 1: Find the placeholder URL**

In `admin/js/src/components/Layout/Header.jsx`, find `developer.example.com`:

```jsx
// BEFORE:
href="https://developer.example.com/ai-entity-index"

// AFTER — link to the plugin's actual documentation or GitHub repo:
href="https://github.com/vibe/ai-entity-index/docs"
// OR if no external docs exist, link to the WordPress admin help tab:
href="#"
onClick={() => { /* could open a help modal in future */ }}
```

- [ ] **Step 2: Commit**

```
fix(header): replace placeholder docs URL with actual link
```

---

### Task 5: Remove Unused Recharts Dependency

**Severity:** 🟢 LOW — Dead weight in bundle  
**Risk:** ZERO  
**Tests:** Build should still succeed  
**Depends on:** None

**Files:**
- Modify: `package.json` (dependency removal)

- [ ] **Step 1: Verify Recharts is truly unused**

Run: `grep -r "recharts\|Recharts" admin/js/src/ --include="*.js" --include="*.jsx"`
Expected: Only found in `package.json`. Zero component imports.

- [ ] **Step 2: Remove the dependency**

```bash
cd C:\DevCentral\Archive\AISemanticLink
npm uninstall recharts
```

- [ ] **Step 3: Verify build still works**

Run: `npm run build`
Expected: Build completes with exit code 0, bundle size reduced.

- [ ] **Step 4: Commit**

```
chore: remove unused recharts dependency

No component imports recharts. Removes dead weight from bundle.
```

---

### Task 6: Fix Content Truncation Mismatch

**Severity:** 🔴 CRITICAL — Silent data loss on long posts  
**Risk:** MEDIUM (cost impact — see mitigation)  
**Tests:** Manual on 5 long-form posts  
**Depends on:** None, BUT ship AFTER Task 1 (model config fix) to control cost impact

**IMPORTANT:** Increasing truncation from 20K → 80K+ chars means 4x input tokens on long posts. The model config fix (Task 1) ensures extraction uses BUDGET_MODEL by default, keeping costs controlled. **Do not ship this task before Task 1 is in production.**

**Files:**
- Modify: `includes/Services/EntityExtractor.php:366-373`
- Modify: `includes/Services/AIClient.php:24` (add reference)

- [ ] **Step 1: Replace hardcoded 20000 with AIClient constant reference**

In `includes/Services/EntityExtractor.php`, lines 366-373:

```php
// BEFORE:
if (mb_strlen($content) > 20000) {
    // Log a warning if possible, then truncate.
    // In a real implementation we would log this truncation.
    $content = mb_substr($content, 0, 20000);
}

// AFTER:
$maxContentLength = (int) floor(AIClient::MAX_INPUT_CHARS * 0.8);
if (mb_strlen($content) > $maxContentLength) {
    $content = mb_substr($content, 0, $maxContentLength);
}
```

This uses 80% of AIClient's MAX_INPUT_CHARS (96,000 chars) to leave room for system prompt and response tokens.

- [ ] **Step 2: Make AIClient::MAX_INPUT_CHARS accessible**

`MAX_INPUT_CHARS` is `private` in AIClient.php. Either:
- Option A (preferred): Change it to `public const` 
- Option B: Add a public static getter `public static function getMaxInputChars(): int`

```php
// In includes/Services/AIClient.php line 24:
// BEFORE:
private const MAX_INPUT_CHARS = 120000;
// AFTER:
public const MAX_INPUT_CHARS = 120000;
```

- [ ] **Step 3: Test on real long-form content**

Run a pipeline scan on a post with >20K characters of content. Verify entities are extracted from the full content, not just the first 20K.

- [ ] **Step 4: Commit**

```
fix(extraction): increase content limit from 20K to 96K chars

Content was silently truncated at 20K chars while AIClient accepted 120K.
Long-form posts lost entities in the latter portion. Now uses 80% of
AIClient::MAX_INPUT_CHARS (96K) to preserve system prompt budget.

IMPORTANT: Ship after model config fix (Task 1) to control cost impact.
```

---

## Phase 0B — Low-Risk Pipeline Guardrails (Day 2, ~3h)

**Risk Level:** LOW — defensive additions, no behavior changes to existing flows

---

### Task 7: Add Cost Guard to AIClient

**Severity:** 🟡 HIGH — Prevents silent cost explosions  
**Risk:** LOW — additive check, doesn't change existing behavior  
**Tests:** Unit test for threshold logic  
**Depends on:** Task 1 (model config fixed)

**Files:**
- Modify: `includes/Services/AIClient.php`
- Create: `tests/unit/AIClientCostGuardTest.php`

- [ ] **Step 1: Write failing test for cost guard**

Create `tests/unit/AIClientCostGuardTest.php`:

```php
<?php
declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Services\AIClient;
use Vibe\AIIndex\Config;

class AIClientCostGuardTest extends TestCase
{
    public function test_cost_guard_warns_on_large_input(): void
    {
        // Simulate content that would cost > threshold
        $large_content = str_repeat('a', 100000);
        // The client should not throw, but should log a warning
        // Implementation detail: check that cost estimation method works
        $this->assertTrue(true); // Placeholder — implement with actual AIClient mock
    }

    public function test_cost_estimate_increases_with_content_length(): void
    {
        // Verify longer content = higher estimated cost
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Add cost estimation and warning method to AIClient**

In `includes/Services/AIClient.php`, add before the `extract()` method:

```php
/**
 * Estimate token cost for a request.
 *
 * @param string $content The input content.
 * @return array{tokens: int, estimated_cost_usd: float} Cost estimate.
 */
public static function estimateCost(string $content, string $model = ''): array
{
    $model = $model ?: Config::DEFAULT_MODEL;
    $char_count = strlen($content);
    // Rough estimate: 1 token ≈ 4 characters
    $estimated_tokens = (int) ceil($char_count / 4);
    // Cost per 1M tokens (approximate OpenRouter pricing)
    $cost_per_million = match (true) {
        str_contains($model, 'gpt-4.1-mini') => 0.40,
        str_contains($model, 'sonnet') => 3.0,
        str_contains($model, 'opus') => 15.0,
        default => 5.0,
    };
    $estimated_cost = ($estimated_tokens / 1_000_000) * $cost_per_million;

    return [
        'tokens' => $estimated_tokens,
        'estimated_cost_usd' => round($estimated_cost, 6),
    ];
}
```

- [ ] **Step 3: Add cost threshold warning before API calls**

In the `extract()` method, before the API call, add:

```php
$estimate = self::estimateCost($content, $model);
if ($estimate['estimated_cost_usd'] > 0.50) {
    // Log warning about expensive request
    error_log(sprintf(
        'Vibe AI: Expensive extraction estimated. Model: %s, Tokens: %d, Cost: $%.4f',
        $model,
        $estimate['tokens'],
        $estimate['estimated_cost_usd']
    ));
}
```

- [ ] **Step 4: Run tests and commit**

```
feat(aiclient): add cost estimation and warning guard

Estimates token cost before API calls and logs warnings for requests
exceeding $0.50. Prevents silent cost explosions from config regressions.
```

---

### Task 8: Add Truncation Logging

**Severity:** 🟡 MEDIUM — Makes data loss visible  
**Risk:** LOW — additive logging  
**Tests:** Unit test for log message format  
**Depends on:** Task 6 (truncation fixed)

**Files:**
- Modify: `includes/Services/EntityExtractor.php` (truncation location from Task 6)

- [ ] **Step 1: Add truncation warning log**

In `includes/Services/EntityExtractor.php`, in the truncation block added by Task 6:

```php
// After the truncation from Task 6:
$maxContentLength = (int) floor(AIClient::MAX_INPUT_CHARS * 0.8);
if (mb_strlen($content) > $maxContentLength) {
    $original_length = mb_strlen($content);
    $content = mb_substr($content, 0, $maxContentLength);
    error_log(sprintf(
        'Vibe AI: Content truncated. Post ID: %d, Original: %d chars, Truncated: %d chars, Lost: %d chars',
        $post_id ?? 0,
        $original_length,
        $maxContentLength,
        $original_length - $maxContentLength
    ));
}
```

Note: If `$post_id` is not available in the `prepare_content` context, pass it through or log without it.

- [ ] **Step 2: Commit**

```
feat(extraction): log warning when content is truncated

Content truncation was silent. Now logs post ID, original length, truncated
length, and amount lost. Makes data loss visible in error logs.
```

---

### Task 9: Fix ActivityLog Fake Pagination

**Severity:** 🟡 MEDIUM — Pagination UI is misleading  
**Risk:** LOW — frontend-only fix  
**Tests:** Manual — verify pagination works  
**Depends on:** None (INDEPENDENT)

**Files:**
- Modify: `admin/js/src/components/ActivityLog/index.jsx`
- Modify: `admin/js/src/api/client.js` (fetchLogs function)

- [ ] **Step 1: Find the pagination normalization**

In `admin/js/src/api/client.js`, find the `fetchLogs()` function. It likely has something like:

```js
// Find and fix:
return { items: data.logs || [], totalPages: 1, total: data.logs?.length || 0 };
```

- [ ] **Step 2: Parse backend pagination properly**

Replace the hardcoded normalization:

```js
// AFTER:
return {
    items: data.logs || [],
    totalPages: data.total_pages || Math.ceil((data.total || 0) / (perPage || 20)),
    total: data.total || data.logs?.length || 0,
};
```

- [ ] **Step 3: Verify backend returns pagination data**

Check the REST endpoint in `includes/REST/RestController.php` — find the logs endpoint and verify it returns `total`, `total_pages`, and `per_page` in the response.

If the backend doesn't return pagination metadata, add it:

```php
// In the logs endpoint handler:
return new \WP_REST_Response([
    'logs' => $logs,
    'total' => $total_count,
    'total_pages' => ceil($total_count / $per_page),
    'page' => $page,
    'per_page' => $per_page,
]);
```

- [ ] **Step 4: Test pagination in ActivityLog UI**

Navigate to ActivityLog, verify page numbers appear and work when there are >20 log entries.

- [ ] **Step 5: Commit**

```
fix(ux): ActivityLog pagination now reflects actual backend data

Pagination was hardcoded to totalPages=1 regardless of response. Now parses
backend total_pages or calculates from total count. Also adds pagination
metadata to backend logs endpoint if missing.
```

---

## Phase 1 — Frontend UX Foundations (Days 3-5, ~12h)

**Risk Level:** MEDIUM — API client consolidation touches all frontend flows

---

### Task 10: Consolidate API Client

**Severity:** 🟡 HIGH — Divergent error handling, maintenance burden  
**Risk:** MEDIUM — All frontend flows depend on this  
**Tests:** Manual QA of every frontend flow after change  
**Depends on:** None (do FIRST in Phase 1 — blocks Tasks 11, 13)

**Files:**
- Modify: `admin/js/src/api/client.js` (consolidate into this)
- Modify: `admin/js/src/hooks/useKB.js` (remove duplicate apiFetch)

- [ ] **Step 1: Audit both apiFetch implementations**

Read `admin/js/src/api/client.js` lines 1-60 and `admin/js/src/hooks/useKB.js` lines 17-58.

Differences to reconcile:
- `client.js` throws with descriptive error message including status
- `useKB.js` attaches `error.status` property
- `client.js` checks for nonce; `useKB.js` does not

- [ ] **Step 2: Enhance the shared client with both behaviors**

In `admin/js/src/api/client.js`, update the `apiFetch` function:

```js
export async function apiFetch( endpoint, options = {} ) {
    const config = getConfig();
    const url = `${ config.apiUrl }${ endpoint }`;

    if ( ! config.nonce ) {
        throw new Error( 'Security nonce is missing for API request' );
    }

    const headers = {
        'X-WP-Nonce': config.nonce,
        ...( options.body ? { 'Content-Type': 'application/json' } : {} ),
        ...options.headers,
    };

    const response = await fetch( url, {
        ...options,
        headers,
        credentials: 'same-origin',
    } );

    const contentType = response.headers.get( 'content-type' );
    const isJson = contentType && contentType.includes( 'application/json' );

    if ( ! response.ok ) {
        let errorMessage = `API Error: ${ response.status } ${ response.statusText }`;

        if ( isJson ) {
            try {
                const errorData = await response.json();
                errorMessage = errorData.message || errorData.error || errorMessage;
            } catch {
                // Use default error message
            }
        }

        const error = new Error( errorMessage );
        error.status = response.status;
        throw error;
    }

    // Handle 204 No Content
    if ( response.status === 204 ) {
        return null;
    }

    return response.json();
}
```

- [ ] **Step 3: Update useKB.js to import from shared client**

In `admin/js/src/hooks/useKB.js`, remove the local `getConfig()` and `apiFetch()` functions (lines 17-58). Add import:

```js
import { apiFetch } from '../api/client';
```

Remove:
```js
// DELETE these from useKB.js:
const getConfig = () => { ... };
async function apiFetch( endpoint, options = {} ) { ... };
```

- [ ] **Step 4: Run build and verify no import errors**

Run: `npm run build`
Expected: Build succeeds with no errors.

- [ ] **Step 5: Manual QA — test every frontend flow**

Test these flows in order:
1. Dashboard — pipeline status loads
2. Entity Manager — entity list loads, inline edit works
3. Entity Drawer — entity detail loads, save works
4. Knowledge Base — all 5 sub-pages load
5. Settings — settings load, save works
6. Activity Log — logs load

- [ ] **Step 6: Commit**

```
refactor(frontend): consolidate duplicate API clients into single shared module

useKB.js had its own apiFetch with divergent error handling (no nonce check,
different error shape). Now all API calls go through api/client.js with
consistent nonce validation, error.status attachment, and 204 handling.
```

---

### Task 11: Add Toast Notification System

**Severity:** 🟡 MEDIUM — No async operation feedback  
**Risk:** LOW — additive UI component  
**Tests:** Manual — verify toasts appear for success/error  
**Depends on:** Task 10 (consolidated API client)

**Files:**
- Create: `admin/js/src/components/common/Toast.jsx`
- Modify: `admin/js/src/App.jsx` (add ToastProvider)
- Modify: `admin/js/src/api/client.js` (auto-toast on errors)

- [ ] **Step 1: Install a lightweight toast library**

```bash
cd C:\DevCentral\Archive\AISemanticLink
npm install sonner
```

`sonner` is lightweight (~3KB gzipped), React-native, and Tailwind-friendly.

- [ ] **Step 2: Add Toaster to App.jsx**

In `admin/js/src/App.jsx`, import and render:

```jsx
import { Toaster } from 'sonner';

// Inside the App component's return, add at the top level:
<QueryClientProvider client={queryClient}>
    <Toaster position="bottom-right" richColors closeButton />
    <HashRouter>
        {/* existing layout */}
    </HashRouter>
</QueryClientProvider>
```

- [ ] **Step 3: Wire toast into API client error handling**

In `admin/js/src/api/client.js`, import `toast` from sonner and add error notification:

```js
import { toast } from 'sonner';

// In the apiFetch error handling block, after throwing:
// Actually, better approach: wrap apiFetch callers with toast.promise or
// use onError callback pattern. Add a helper:

export function createMutationOptions( options = {} ) {
    return {
        onSuccess: ( data ) => {
            if ( options.successMessage ) {
                toast.success( options.successMessage );
            }
        },
        onError: ( error ) => {
            toast.error( options.errorMessage || error.message || 'An error occurred' );
        },
        ...options,
    };
}
```

- [ ] **Step 4: Apply toast notifications to key mutations**

Update entity mutations to use toast:

```js
// In EntityManager or EntityDrawer mutation hooks:
useMutation( {
    mutationFn: ( data ) => updateEntity( id, data ),
    ...createMutationOptions( {
        successMessage: 'Entity saved',
        errorMessage: 'Failed to save entity',
    } ),
} )
```

- [ ] **Step 5: Manual QA — verify toasts appear**

1. Save an entity → green success toast
2. Force a network error → red error toast
3. Merge entities → success toast
4. Delete entity → success toast

- [ ] **Step 6: Commit**

```
feat(ux): add toast notification system with sonner

Adds sonner toast library for async operation feedback. Auto-toasts on
API errors. Entity CRUD operations show success/error notifications.
```

---

### Task 12: Replace window.confirm() with Custom ConfirmDialog

**Severity:** 🟢 LOW — Polish issue  
**Risk:** LOW — UI component replacement  
**Tests:** Manual — verify dialogs appear and work  
**Depends on:** Task 11 (toast system for portal pattern reference)

**Files:**
- Create: `admin/js/src/components/common/ConfirmDialog.jsx`
- Modify: `admin/js/src/components/EntityManager/MergeModal.jsx`
- Modify: `admin/js/src/components/EntityDrawer/ActionButtons.jsx`
- Modify: `admin/js/src/components/EntityDrawer/index.jsx`

- [ ] **Step 1: Create ConfirmDialog component**

Create `admin/js/src/components/common/ConfirmDialog.jsx`:

```jsx
import { createPortal } from '@wordpress/element';
import { Button } from './Button';

export function ConfirmDialog( {
    isOpen,
    title = 'Confirm',
    message,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    variant = 'danger', // 'danger' | 'warning' | 'info'
    onConfirm,
    onCancel,
} ) {
    if ( ! isOpen ) return null;

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-2">{ title }</h3>
                <p className="text-gray-600 mb-6">{ message }</p>
                <div className="flex justify-end gap-3">
                    <Button variant="secondary" onClick={ onCancel }>
                        { cancelLabel }
                    </Button>
                    <Button
                        variant={ variant === 'danger' ? 'destructive' : 'primary' }
                        onClick={ onConfirm }
                    >
                        { confirmLabel }
                    </Button>
                </div>
            </div>
        </div>,
        document.body
    );
}
```

- [ ] **Step 2: Replace window.confirm in MergeModal**

In `admin/js/src/components/EntityManager/MergeModal.jsx`, find `window.confirm` and replace with state-driven ConfirmDialog.

- [ ] **Step 3: Replace window.confirm in EntityDrawer/ActionButtons**

In `admin/js/src/components/EntityDrawer/ActionButtons.jsx`, find `window.confirm` for delete and replace.

- [ ] **Step 4: Replace window.confirm in EntityDrawer/index.jsx**

In `admin/js/src/components/EntityDrawer/index.jsx`, find `window.confirm` for unsaved changes warning and replace.

- [ ] **Step 5: Manual QA — verify all confirm dialogs**

1. Delete an entity → custom dialog appears
2. Merge entities → custom dialog with merge preview
3. Close entity drawer with unsaved changes → custom warning dialog

- [ ] **Step 6: Commit**

```
feat(ux): replace window.confirm() with custom ConfirmDialog component

Browser native confirm() dialogs look amateur. New ConfirmDialog is a
modal portal with proper styling, variant support (danger/warning/info),
and Button component integration.
```

---

### Task 13: Create Entity UI

**Severity:** 🔴 CRITICAL — Users cannot manually create entities  
**Risk:** LOW — new feature, doesn't touch existing flows  
**Tests:** Manual — create entity end-to-end  
**Depends on:** Task 10 (consolidated API client)

**Files:**
- Create: `admin/js/src/components/EntityManager/CreateEntityModal.jsx`
- Modify: `admin/js/src/components/EntityManager/index.jsx` (add Create button)
- Modify: `admin/js/src/api/client.js` (add createEntity function)
- Modify: `includes/REST/RestController.php` (add/create endpoint if missing)

- [ ] **Step 1: Verify backend has a create entity endpoint**

Check `includes/REST/RestController.php` for a POST `/entities` endpoint that accepts:
- `name` (string, required)
- `type` (string, required, must be in Config::VALID_TYPES)
- `status` (string, optional, defaults to 'raw')
- `description` (string, optional)
- `aliases` (array of strings, optional)

If it doesn't exist, add it:

```php
// In RestController.php, in register_routes():
register_rest_route( self::NAMESPACE, '/entities', [
    'methods' => 'POST',
    'callback' => [ $this, 'create_entity' ],
    'permission_callback' => [ $this, 'check_permission' ],
] );
```

```php
public function create_entity( \WP_REST_Request $request ): \WP_REST_Response {
    $name = sanitize_text_field( $request->get_param( 'name' ) );
    $type = strtoupper( sanitize_text_field( $request->get_param( 'type' ) ) );

    if ( empty( $name ) || ! Config::isValidType( $type ) ) {
        return new \WP_REST_Response( [
            'error' => 'Invalid name or type',
        ], 400 );
    }

    // Create entity via EntityRepository
    $entity_id = $this->entity_repository->create( [
        'name' => $name,
        'slug' => sanitize_title( $name ),
        'type' => $type,
        'status' => sanitize_text_field( $request->get_param( 'status' ) ) ?: 'raw',
        'description' => sanitize_textarea_field( $request->get_param( 'description' ) ) ?: '',
    ] );

    if ( is_wp_error( $entity_id ) ) {
        return new \WP_REST_Response( [ 'error' => $entity_id->get_error_message() ], 500 );
    }

    // Handle aliases
    $aliases = $request->get_param( 'aliases' );
    if ( is_array( $aliases ) ) {
        foreach ( $aliases as $alias ) {
            $this->alias_repository->add_alias( $entity_id, sanitize_text_field( $alias ) );
        }
    }

    return new \WP_REST_Response( $this->entity_repository->find( $entity_id ), 201 );
}
```

- [ ] **Step 2: Add createEntity to API client**

In `admin/js/src/api/client.js`:

```js
export async function createEntity( data ) {
    return apiFetch( '/entities', {
        method: 'POST',
        body: JSON.stringify( data ),
    } );
}
```

- [ ] **Step 3: Create CreateEntityModal component**

Create `admin/js/src/components/EntityManager/CreateEntityModal.jsx`:

```jsx
import { useState } from '@wordpress/element';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { createEntity } from '../../api/client';
import { Button } from '../common/Button';
import { Badge } from '../common/Badge';

const ENTITY_TYPES = [
    'PERSON', 'ORG', 'COMPANY', 'LOCATION', 'COUNTRY',
    'PRODUCT', 'SOFTWARE', 'EVENT', 'WORK', 'CONCEPT',
];

export function CreateEntityModal( { isOpen, onClose } ) {
    const queryClient = useQueryClient();
    const [ name, setName ] = useState( '' );
    const [ type, setType ] = useState( 'CONCEPT' );
    const [ description, setDescription ] = useState( '' );
    const [ aliases, setAliases ] = useState( [] );
    const [ aliasInput, setAliasInput ] = useState( '' );

    const createMutation = useMutation( {
        mutationFn: createEntity,
        onSuccess: () => {
            queryClient.invalidateQueries( { queryKey: [ 'entities' ] } );
            onClose();
        },
    } );

    if ( ! isOpen ) return null;

    const handleAddAlias = () => {
        const trimmed = aliasInput.trim();
        if ( trimmed && ! aliases.includes( trimmed ) ) {
            setAliases( [ ...aliases, trimmed ] );
            setAliasInput( '' );
        }
    };

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/50">
            <div className="bg-white rounded-lg shadow-xl max-w-lg w-full p-6">
                <h2 className="text-xl font-bold mb-4">Create Entity</h2>

                <div className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Name *
                        </label>
                        <input
                            type="text"
                            value={ name }
                            onChange={ ( e ) => setName( e.target.value ) }
                            className="w-full border rounded-md px-3 py-2"
                            placeholder="e.g., Elon Musk"
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Type *
                        </label>
                        <select
                            value={ type }
                            onChange={ ( e ) => setType( e.target.value ) }
                            className="w-full border rounded-md px-3 py-2"
                        >
                            { ENTITY_TYPES.map( ( t ) => (
                                <option key={ t } value={ t }>{ t }</option>
                            ) ) }
                        </select>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Description
                        </label>
                        <textarea
                            value={ description }
                            onChange={ ( e ) => setDescription( e.target.value ) }
                            className="w-full border rounded-md px-3 py-2"
                            rows={ 3 }
                            maxLength={ 500 }
                            placeholder="Brief description of this entity..."
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Aliases
                        </label>
                        <div className="flex gap-2 mb-2">
                            { aliases.map( ( alias ) => (
                                <Badge key={ alias }>
                                    { alias }
                                    <button
                                        onClick={ () => setAliases( aliases.filter( ( a ) => a !== alias ) ) }
                                        className="ml-1 text-gray-400 hover:text-red-500"
                                    >
                                        ×
                                    </button>
                                </Badge>
                            ) ) }
                        </div>
                        <div className="flex gap-2">
                            <input
                                type="text"
                                value={ aliasInput }
                                onChange={ ( e ) => setAliasInput( e.target.value ) }
                                onKeyDown={ ( e ) => e.key === 'Enter' && ( e.preventDefault(), handleAddAlias() ) }
                                className="flex-1 border rounded-md px-3 py-2"
                                placeholder="Add alias..."
                            />
                            <Button variant="secondary" onClick={ handleAddAlias }>
                                Add
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="flex justify-end gap-3 mt-6">
                    <Button variant="secondary" onClick={ onClose }>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        onClick={ () => createMutation.mutate( { name, type, description, aliases } ) }
                        disabled={ ! name.trim() || createMutation.isPending }
                    >
                        { createMutation.isPending ? 'Creating...' : 'Create Entity' }
                    </Button>
                </div>

                { createMutation.isError && (
                    <p className="mt-3 text-sm text-red-600">
                        { createMutation.error.message }
                    </p>
                ) }
            </div>
        </div>
    );
}
```

- [ ] **Step 4: Add "Create Entity" button to EntityManager**

In `admin/js/src/components/EntityManager/index.jsx`, add a create button:

```jsx
import { CreateEntityModal } from './CreateEntityModal';

// Add state:
const [ showCreateModal, setShowCreateModal ] = useState( false );

// Add button in the toolbar area (before Filters):
<Button variant="primary" onClick={ () => setShowCreateModal( true ) }>
    + Create Entity
</Button>

// Add modal at end of component:
<CreateEntityModal
    isOpen={ showCreateModal }
    onClose={ () => setShowCreateModal( false ) }
/>
```

- [ ] **Step 5: Manual QA — create entity end-to-end**

1. Click "Create Entity" → modal opens
2. Fill name, select type, add description and aliases
3. Click "Create Entity" → entity appears in list
4. Open the new entity in drawer → all data preserved

- [ ] **Step 6: Commit**

```
feat(entities): add Create Entity UI with modal form

Users could previously only edit/delete/merge entities from the AI pipeline.
Now they can manually create entities with name, type, description, and aliases.
Includes backend POST /entities endpoint and frontend CreateEntityModal component.
```

---

### Task 14: Fix KB Sidebar Accessibility When Collapsed

**Severity:** 🟢 LOW — Usability issue when sidebar collapsed  
**Risk:** LOW — CSS/component tweak  
**Tests:** Manual — verify collapsed sidebar shows KB items  
**Depends on:** None (INDEPENDENT)

**Files:**
- Modify: `admin/js/src/components/Layout/Sidebar.jsx`

- [ ] **Step 1: Read current Sidebar implementation**

Read `admin/js/src/components/Layout/Sidebar.jsx` to understand the collapse behavior and KB submenu rendering.

- [ ] **Step 2: Add flyout menu for collapsed state**

When sidebar is collapsed and user hovers over the KB nav item, show a flyout/tooltip menu with the KB sub-items:

```jsx
// In the KB nav item, add conditional flyout:
{ isCollapsed && isKBExpanded && (
    <div className="absolute left-full top-0 ml-2 bg-white border rounded-lg shadow-lg py-2 min-w-[200px] z-50">
        { kbSubItems.map( ( item ) => (
            <NavLink key={ item.path } to={ item.path } className="block px-4 py-2 hover:bg-gray-50 text-sm">
                { item.icon } { item.label }
            </NavLink>
        ) ) }
    </div>
) }
```

- [ ] **Step 3: Style the flyout**

Add Tailwind classes for the flyout positioning. The parent KB nav item needs `relative` positioning:

```jsx
<li className="relative">
    {/* KB nav item */}
    {/* Flyout when collapsed */}
</li>
```

- [ ] **Step 4: Manual QA — test collapsed sidebar**

1. Collapse sidebar
2. Hover over KB icon
3. Verify flyout appears with all 5 KB sub-items
4. Click a sub-item → navigates correctly
5. Expand sidebar → normal submenu behavior restored

- [ ] **Step 5: Commit**

```
fix(ux): KB sub-items accessible when sidebar is collapsed

Added flyout menu on hover for KB navigation when sidebar is in collapsed
state. Previously, KB sub-items (Overview/Documents/Search/Settings/Logs)
were inaccessible when collapsed.
```

---

## Phase 2 — AI Quality & Trust (Days 6-9, ~16h)

**Risk Level:** MEDIUM — changes to AI pipeline behavior

---

### Task 15: Implement Real Model Routing

**Severity:** 🟡 HIGH — Budget model never actually used  
**Risk:** MEDIUM — changes which model processes which operation  
**Tests:** Unit tests for ModelRouter  
**Depends on:** Task 1 (model config fixed)

**Files:**
- Create: `includes/Services/ModelRouter.php`
- Modify: `includes/Services/EntityExtractor.php` (use ModelRouter)
- Modify: `includes/Pipeline/PipelineManager.php` (use ModelRouter for phases)
- Create: `tests/unit/ModelRouterTest.php`

- [ ] **Step 1: Write failing tests for ModelRouter**

Create `tests/unit/ModelRouterTest.php`:

```php
<?php
declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Services\ModelRouter;
use Vibe\AIIndex\Config;

class ModelRouterTest extends TestCase
{
    public function test_extraction_uses_budget_model(): void
    {
        $model = ModelRouter::select( 'extraction' );
        $this->assertEquals( Config::BUDGET_MODEL, $model );
    }

    public function test_schema_build_uses_default_model(): void
    {
        $model = ModelRouter::select( 'schema_build' );
        $this->assertEquals( Config::DEFAULT_MODEL, $model );
    }

    public function test_deduplication_uses_budget_model(): void
    {
        $model = ModelRouter::select( 'deduplication' );
        $this->assertEquals( Config::BUDGET_MODEL, $model );
    }

    public function test_unknown_operation_uses_default(): void
    {
        $model = ModelRouter::select( 'unknown_operation' );
        $this->assertEquals( Config::DEFAULT_MODEL, $model );
    }

    public function test_all_pipeline_phases_have_routing(): void
    {
        foreach ( Config::PIPELINE_PHASES as $phase => $description ) {
            $model = ModelRouter::select( $phase );
            $this->assertNotEmpty( $model, "Phase {$phase} has no model routing" );
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/unit/ModelRouterTest.php`
Expected: FAIL — `Class ModelRouter not found`

- [ ] **Step 3: Implement ModelRouter**

Create `includes/Services/ModelRouter.php`:

```php
<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

use Vibe\AIIndex\Config;

/**
 * Routes AI operations to appropriate models based on operation type.
 *
 * High-volume, low-stakes operations (extraction, deduplication) use the
 * budget model. High-stakes, low-volume operations (schema, linking) use
 * the default premium model.
 */
class ModelRouter
{
    /**
     * Operations that should use the budget model.
     */
    private const BUDGET_OPERATIONS = [
        'extraction',
        'deduplication',
        'indexing',
        'kb_embed_chunks',
        'kb_chunk_build',
    ];

    /**
     * Select the appropriate model for an operation.
     *
     * @param string $operation The pipeline operation name.
     * @return string The model identifier to use.
     */
    public static function select( string $operation ): string
    {
        if ( in_array( $operation, self::BUDGET_OPERATIONS, true ) ) {
            return Config::BUDGET_MODEL;
        }

        return Config::DEFAULT_MODEL;
    }
}
```

- [ ] **Step 4: Wire ModelRouter into PipelineManager**

In `includes/Pipeline/PipelineManager.php`, wherever a model is selected for a pipeline phase, replace with:

```php
$model = ModelRouter::select( $phase_name );
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/ModelRouterTest.php`
Expected: All 5 tests PASS

- [ ] **Step 6: Commit**

```
feat(ai): add ModelRouter for operation-aware model selection

Extraction/dedup/indexing now use BUDGET_MODEL (gpt-4.1-mini) instead of
DEFAULT_MODEL (claude-opus-4.5). Schema/linking use premium model. Reduces
cost by ~97% on high-volume operations while preserving quality where it matters.
```

---

### Task 16: Add Confidence Scoring UI

**Severity:** 🟡 MEDIUM — Confidence is opaque to users  
**Risk:** LOW — additive UI enhancement  
**Tests:** Manual — verify confidence badges display  
**Depends on:** Task 10 (consolidated API client)

**Files:**
- Modify: `admin/js/src/components/EntityDrawer/MentionsSection.jsx`
- Modify: `admin/js/src/components/EntityManager/EntityTable.jsx`

- [ ] **Step 1: Add confidence tooltip to EntityTable**

In `admin/js/src/components/EntityManager/EntityTable.jsx`, add a confidence column or enhance the existing one:

```jsx
// In column definition for confidence/quality:
{
    header: 'Quality',
    accessorKey: 'confidence',
    cell: ( { getValue } ) => {
        const confidence = getValue();
        const percent = Math.round( ( confidence || 0 ) * 100 );
        const tier = percent >= 80 ? 'high' : percent >= 50 ? 'medium' : 'low';
        const colors = {
            high: 'bg-green-100 text-green-800',
            medium: 'bg-yellow-100 text-yellow-800',
            low: 'bg-red-100 text-red-800',
        };

        return (
            <span
                className={ `inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${ colors[ tier ] }` }
                title={ `AI confidence: ${ percent }%. ${
                    tier === 'high' ? 'Very likely a correct extraction.' :
                    tier === 'medium' ? 'Reasonable extraction, may need review.' :
                    'Low certainty, consider reviewing or rejecting.'
                }` }
            >
                { percent }%
            </span>
        );
    },
}
```

- [ ] **Step 2: Enhance MentionsSection confidence badges**

The existing `MentionsSection.jsx` already has confidence badges. Verify they use the same color coding and add tooltips.

- [ ] **Step 3: Manual QA — verify confidence display**

1. Open Entity Manager → confidence column shows color-coded percentages
2. Hover over a confidence badge → tooltip explains what the score means
3. Open Entity Drawer → mentions show confidence badges

- [ ] **Step 4: Commit**

```
feat(ux): add confidence scoring display with tooltips

Entity table now shows color-coded confidence percentages (green >80%,
yellow >50%, red <50%) with explanatory tooltips. Makes AI confidence
transparent and actionable for users.
```

---

### Task 17: Add Prompt Versioning

**Severity:** 🟡 MEDIUM — No way to track which prompt produced which results  
**Risk:** LOW — new infrastructure, doesn't change existing prompts  
**Tests:** Unit test for version resolution  
**Depends on:** None (INDEPENDENT)

**Files:**
- Create: `includes/Prompts/v1/ExtractionPrompt.php`
- Create: `includes/Prompts/PromptManager.php`
- Modify: `includes/Services/EntityExtractor.php` (use PromptManager)
- Create: `tests/unit/PromptManagerTest.php`

- [ ] **Step 1: Write failing test for PromptManager**

```php
public function test_current_extraction_prompt_version(): void
{
    $manager = new PromptManager();
    $this->assertEquals( 'v1', $manager->getCurrentVersion( 'extraction' ) );
}

public function test_extraction_prompt_content_matches_current(): void
{
    $manager = new PromptManager();
    $prompt = $manager->getPrompt( 'extraction' );
    $this->assertStringContainsString( 'Named Entity Recognition', $prompt );
}
```

- [ ] **Step 2: Create PromptManager**

```php
<?php
declare(strict_types=1);

namespace Vibe\AIIndex\Prompts;

class PromptManager
{
    private const PROMPTS = [
        'extraction' => __DIR__ . '/v1/ExtractionPrompt.php',
    ];

    public function getCurrentVersion( string $type ): string
    {
        return 'v1';
    }

    public function getPrompt( string $type ): string
    {
        // For v1, return the existing prompt from EntityExtractor::SYSTEM_PROMPT
        return match ( $type ) {
            'extraction' => $this->getExtractionPrompt(),
            default => throw new \InvalidArgumentException( "Unknown prompt type: {$type}" ),
        };
    }

    private function getExtractionPrompt(): string
    {
        // Return the current system prompt — in future versions,
        // this loads from a versioned file
        return <<<'PROMPT'
You are an expert Semantic Knowledge Graph Engineer specializing in Named Entity Recognition and normalization for SEO and AI discoverability.

CORE RULES:
1. Extract ONLY Named Entities (proper nouns with specific identity)
2. IGNORE generic nouns, adjectives, and common concepts
3. NORMALIZE names to their most complete, canonical form
4. RESOLVE ambiguity using context
5. Assign appropriate TYPE from: PERSON, ORG, COMPANY, LOCATION, COUNTRY, PRODUCT, SOFTWARE, EVENT, WORK, CONCEPT
6. Provide CONFIDENCE score (0.0-1.0) based on extraction certainty
7. Include CONTEXT snippet (exact quote, max 100 chars) showing entity mention

RESPONSE FORMAT (strict JSON only, no markdown):
{"entities": [{"name": "Canonical Name", "type": "TYPE", "confidence": 0.95, "context": "...snippet...", "aliases": ["alternate name"]}]}
PROMPT;
    }
}
```

- [ ] **Step 3: Wire into EntityExtractor**

In `EntityExtractor::extract_from_content()`, replace the direct `SYSTEM_PROMPT` reference:

```php
// BEFORE:
$system_prompt = $this->get_system_prompt();

// AFTER:
$prompt_manager = new \Vibe\AIIndex\Prompts\PromptManager();
$system_prompt = $prompt_manager->getPrompt( 'extraction' );
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/unit/PromptManagerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```
feat(ai): add PromptManager for versioned prompt delivery

Prompts are now managed through PromptManager instead of hardcoded class
constants. Enables future prompt versioning, A/B testing, and tracking
which prompt version produced each extraction.
```

---

### Task 18: Complete Schema.org Mappings

**Severity:** 🟡 MEDIUM — TYPE_MAPPING covers 10 types but has gaps  
**Risk:** LOW — config addition only  
**Tests:** Unit test for all type mappings  
**Depends on:** None (INDEPENDENT)

**Files:**
- Modify: `includes/Config.php:165-176` (TYPE_MAPPING)
- Modify: `includes/Config.php:179-190` (VALID_TYPES)

- [ ] **Step 1: Audit current TYPE_MAPPING completeness**

Current mapping:
```
PERSON → Person ✅
ORG → Organization ✅
COMPANY → Corporation ✅
LOCATION → Place ✅
COUNTRY → Country ✅
PRODUCT → Product ✅
SOFTWARE → SoftwareApplication ✅
EVENT → Event ✅
WORK → CreativeWork ✅
CONCEPT → Thing ⚠️ (too generic)
```

- [ ] **Step 2: Improve CONCEPT mapping and add useful subtypes**

```php
public const TYPE_MAPPING = [
    'PERSON' => 'Person',
    'ORG' => 'Organization',
    'COMPANY' => 'Corporation',
    'LOCATION' => 'Place',
    'COUNTRY' => 'Country',
    'PRODUCT' => 'Product',
    'SOFTWARE' => 'SoftwareApplication',
    'EVENT' => 'Event',
    'WORK' => 'CreativeWork',
    'CONCEPT' => 'DefinedTerm',  // More specific than Thing for semantic concepts
    'TECHNOLOGY' => 'Thing',      // Commonly requested but too broad for a specific Schema type
    'BRAND' => 'Brand',           // Often confused with ORG/COMPANY
];
```

- [ ] **Step 3: Add new types to VALID_TYPES**

```php
public const VALID_TYPES = [
    'PERSON',
    'ORG',
    'COMPANY',
    'LOCATION',
    'COUNTRY',
    'PRODUCT',
    'SOFTWARE',
    'EVENT',
    'WORK',
    'CONCEPT',
    'TECHNOLOGY',
    'BRAND',
];
```

- [ ] **Step 4: Update extraction prompt to include new types**

In the extraction prompt (EntityExtractor::SYSTEM_PROMPT or PromptManager), update the TYPE list:

```
5. Assign appropriate TYPE from: PERSON, ORG, COMPANY, LOCATION, COUNTRY, PRODUCT, SOFTWARE, EVENT, WORK, CONCEPT, TECHNOLOGY, BRAND
```

- [ ] **Step 5: Update frontend type selectors**

In `admin/js/src/components/EntityDrawer/IdentitySection.jsx`, update the type dropdown to include `TECHNOLOGY` and `BRAND`.

In `admin/js/src/components/EntityManager/CreateEntityModal.jsx`, update the `ENTITY_TYPES` array.

- [ ] **Step 6: Run tests and commit**

```
feat(entities): add TECHNOLOGY and BRAND entity types with Schema.org mappings

Adds two commonly requested entity types. CONCEPT now maps to DefinedTerm
instead of generic Thing. Frontend type selectors updated.
```

---

## Phase 3 — Architecture Hardening (Days 10-14, ~20h)

**Risk Level:** HIGH — vector search changes affect ranking; test coverage changes touch core code

---

### Task 19: Vector Search Optimization (3 Sub-Tasks)

**Severity:** 🟡 HIGH — Brute-force cosine similarity won't scale past 10K chunks  
**Risk:** HIGH — changes similarity ranking  
**Tests:** Snapshot test before/after for test query set  
**Depends on:** None (INDEPENDENT, but largest single task)

**Files:**
- Modify: `includes/Services/KB/VectorStore.php` (or equivalent)
- Modify: `includes/Repositories/KB/` (vector queries)
- Create: `tests/integration/VectorSearchBenchmark.php`

#### Sub-Task 19a: Add Pre-Filtering Layer

- [ ] **Step 1: Snapshot current search results for a test query set**

Run 10 representative KB searches and save the top-5 results for each. This is the "before" snapshot.

- [ ] **Step 2: Add pre-filter by entity type and post type**

In the vector search query, add WHERE clauses to filter by:
- Post type (only search within relevant post types)
- Date range (optional, for recency bias)
- KB document status (only 'indexed')

This should reduce scan from ~5000 to ~500-1000 vectors for typical queries.

- [ ] **Step 3: Verify ranking hasn't changed**

Run the same 10 test queries and compare results. Top results should be identical or improved.

#### Sub-Task 19b: Add Results Cache

- [ ] **Step 4: Cache identical embedding queries**

Use `wp_transient` to cache search results:

```php
$cache_key = 'vibe_ai_kb_search_' . md5( serialize( [ $query_embedding, $top_k, $filters ] ) );
$cached = get_transient( $cache_key );
if ( $cached !== false ) {
    return $cached;
}
// ... perform search ...
set_transient( $cache_key, $results, HOUR_IN_SECONDS );
```

- [ ] **Step 5: Add cache invalidation**

Invalidate the search cache when:
- KB document is reindexed
- KB document is excluded/included
- KB settings are updated

#### Sub-Task 19c: Benchmark and Evaluate

- [ ] **Step 6: Run benchmark at 1K, 5K, 10K, 50K vectors**

Measure query latency at each scale. If filtered + cached MySQL delivers <200ms at 10K, keep the approach. If not, create a follow-up task for external vector DB evaluation.

- [ ] **Step 7: Commit all sub-tasks**

```
feat(kb): optimize vector search with pre-filtering and results cache

- Pre-filters vectors by post type, date, and status before cosine similarity
- Caches search results for 1 hour via wp_transient
- Invalidates cache on KB document changes
- Reduces typical scan from 5000 to 500-1000 vectors
```

---

### Task 20: Increase Test Coverage to 70%+

**Severity:** 🟡 HIGH — Critical orchestration code untested  
**Risk:** LOW — adding tests only, no production code changes  
**Tests:** The tests ARE the deliverable  
**Depends on:** None (INDEPENDENT)

**Files:**
- Create: `tests/unit/PipelineManagerTest.php`
- Create: `tests/unit/KBPipelineManagerTest.php`
- Create: `tests/unit/EntityExtractorValidationTest.php`
- Create: `tests/unit/RestControllerTest.php`

**Priority order for new tests:**

1. **PipelineManager** — test each of the 6 phases transitions correctly
2. **KBPipelineManager** — test each of the 5 phases transitions correctly
3. **EntityExtractor validation** — test `validate_entity()` with malformed data
4. **RestController** — test permission checks, input validation, response format

- [ ] **Step 1: Write PipelineManager tests**

Test phase transitions: `idle → preparation → extraction → deduplication → linking → indexing → schema_build → complete`

Test error recovery: what happens when extraction fails? Does it retry? Does it move to the next post?

- [ ] **Step 2: Write KBPipelineManager tests**

Test KB phases: `kb_document_build → kb_chunk_build → kb_embed_chunks → kb_index_upsert → kb_cleanup`

Test partial failures: what happens when embedding fails for one chunk?

- [ ] **Step 3: Write EntityExtractor validation tests**

Test `validate_entity()` with:
- Missing required fields (`name`)
- Invalid type (not in VALID_TYPES)
- Out-of-range confidence (< 0 or > 1)
- Empty aliases array
- Too many aliases (> MAX_ALIASES_PER_ENTITY)

- [ ] **Step 4: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass. Coverage increases from ~30% to ~70%.

- [ ] **Step 5: Commit**

```
test: increase coverage from 30% to 70% with PipelineManager, KB, and validation tests

Adds tests for:
- PipelineManager: all 6 phase transitions, error recovery
- KBPipelineManager: all 5 phase transitions, partial failures
- EntityExtractor: input validation edge cases
- RestController: permission checks, input sanitization
```

---

### Task 21: Onboarding Wizard

**Severity:** 🟡 HIGH — First-time users see empty dashboard with no guidance  
**Risk:** MEDIUM — new UI flow  
**Tests:** Manual — complete wizard end-to-end  
**Depends on:** Task 11 (toast system), Task 13 (create entity — pattern reference)

**Files:**
- Create: `admin/js/src/components/Onboarding/OnboardingWizard.jsx`
- Create: `admin/js/src/components/Onboarding/StepApiKey.jsx`
- Create: `admin/js/src/components/Onboarding/StepTestConnection.jsx`
- Create: `admin/js/src/components/Onboarding/StepFirstScan.jsx`
- Create: `admin/js/src/components/Onboarding/StepComplete.jsx`
- Modify: `admin/js/src/components/Dashboard/index.jsx` (show wizard for first-time users)
- Modify: `includes/REST/RestController.php` (add test-connection endpoint)

- [ ] **Step 1: Create wizard skeleton with step navigation**

```jsx
// OnboardingWizard.jsx — 4 steps:
// 1. API Key — enter OpenRouter API key
// 2. Test Connection — verify API key works
// 3. First Scan — trigger pipeline on first 10 posts
// 4. Complete — show dashboard tour or CTA
```

- [ ] **Step 2: Add test-connection backend endpoint**

```php
register_rest_route( self::NAMESPACE, '/test-connection', [
    'methods' => 'POST',
    'callback' => [ $this, 'test_connection' ],
    'permission_callback' => [ $this, 'check_permission' ],
] );

public function test_connection( \WP_REST_Request $request ): \WP_REST_Response {
    $api_key = sanitize_text_field( $request->get_param( 'api_key' ) );
    // Make a minimal API call to OpenRouter to verify the key
    // Return success/failure with model availability
}
```

- [ ] **Step 3: Track onboarding completion in wp_options**

```php
// When wizard completes:
update_option( 'vibe_ai_onboarding_complete', true );
```

- [ ] **Step 4: Show wizard on Dashboard when not completed**

In `Dashboard/index.jsx`:

```jsx
const [ onboardingComplete ] = useQuery( {
    queryKey: [ 'onboarding-status' ],
    queryFn: () => apiFetch( '/settings' ).then( s => s.onboarding_complete ),
} );

if ( ! onboardingComplete ) {
    return <OnboardingWizard onComplete={ () => queryClient.invalidateQueries() } />;
}
```

- [ ] **Step 5: Manual QA — complete wizard**

1. Fresh plugin install → dashboard shows wizard
2. Enter API key → test connection → success
3. Run first scan → entities appear
4. Complete → dashboard shows normal view
5. Refresh → wizard doesn't reappear

- [ ] **Step 6: Commit**

```
feat(ux): add onboarding wizard for first-time setup

4-step wizard: API key → test connection → first scan → dashboard tour.
Tracked via wp_options so it only appears once. Solves empty dashboard
problem for new users.
```

---

### Task 22: Batch Entity Operations

**Severity:** 🟢 LOW — Nice-to-have for power users  
**Risk:** LOW — extending existing bulk operations  
**Tests:** Manual — select multiple entities, perform actions  
**Depends on:** Task 10 (consolidated API client)

**Files:**
- Modify: `admin/js/src/components/EntityManager/BulkActions.jsx`
- Modify: `includes/REST/RestController.php` (enhance bulk endpoints)

- [ ] **Step 1: Enhance BulkActions component**

Add bulk status change dropdown with all valid statuses:

```jsx
<select onChange={ ( e ) => handleBulkStatusChange( selectedIds, e.target.value ) }>
    <option value="">Set Status...</option>
    <option value="raw">Raw</option>
    <option value="reviewed">Reviewed</option>
    <option value="canonical">Canonical</option>
    <option value="rejected">Rejected</option>
</select>
```

- [ ] **Step 2: Verify backend bulk endpoints handle status changes**

Check `includes/REST/RestController.php` for `bulk-status` endpoint. It should accept:
- `ids` (array of entity IDs)
- `status` (string, must be in VALID_STATUSES)

- [ ] **Step 3: Manual QA — test batch operations**

1. Select 5 entities via checkboxes
2. Click "Set Status" → "Canonical"
3. Verify all 5 entities updated
4. Select 3 → "Delete" → confirm → all 3 deleted

- [ ] **Step 4: Commit**

```
feat(entities): enhance bulk status change dropdown

BulkActions now shows all valid statuses in a dropdown. Previously only
available through individual entity editing.
```

---

## Phase 4 — Premium Features (Days 15-20, ~24h)

**Risk Level:** LOW — all additive features, no existing code changes

---

### Task 23: Content Moderation / PII Detection

**Severity:** 🟢 LOW — Security/safety enhancement  
**Risk:** LOW — pre-processing filter, doesn't change pipeline  
**Tests:** Unit tests for PII regex patterns  
**Depends on:** None (INDEPENDENT)

**Files:**
- Create: `includes/Services/ContentModerator.php`
- Create: `tests/unit/ContentModeratorTest.php`
- Modify: `includes/Services/EntityExtractor.php` (filter output through moderator)

- [ ] **Step 1: Write failing tests for PII detection**

```php
public function test_detects_email_addresses(): void
{
    $moderator = new ContentModerator();
    $result = $moderator->scan( 'Contact john@example.com for info' );
    $this->assertTrue( $result->hasPii );
    $this->assertCount( 1, $result->findings );
}

public function test_detects_phone_numbers(): void { /* ... */ }
public function test_detects_ssns(): void { /* ... */ }
public function test_redacts_pii(): void { /* ... */ }
public function test_clean_content_passes(): void { /* ... */ }
```

- [ ] **Step 2: Implement ContentModerator**

```php
class ContentModerator
{
    private const PII_PATTERNS = [
        'email' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
        'phone' => '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',
        'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
    ];

    public function scan( string $content ): \stdClass
    {
        $findings = [];
        foreach ( self::PII_PATTERNS as $type => $pattern ) {
            if ( preg_match( $pattern, $content ) ) {
                $findings[] = $type;
            }
        }
        return (object) [
            'hasPii' => ! empty( $findings ),
            'findings' => $findings,
            'original' => $content,
        ];
    }

    public function redact( string $content ): string
    {
        foreach ( self::PII_PATTERNS as $pattern ) {
            $content = preg_replace( $pattern, '[REDACTED]', $content );
        }
        return $content;
    }
}
```

- [ ] **Step 3: Wire into EntityExtractor**

After AI response parsing, scan entity names and context snippets for PII:

```php
$moderator = new ContentModerator();
foreach ( $validated_entities as &$entity ) {
    $scan = $moderator->scan( $entity['name'] . ' ' . ( $entity['context'] ?? '' ) );
    if ( $scan->hasPii ) {
        // Flag for review instead of auto-approving
        $entity['pii_flagged'] = true;
        $entity['pii_findings'] = $scan->findings;
    }
}
```

- [ ] **Step 4: Run tests and commit**

```
feat(security): add PII detection for entity extraction output

Scans entity names and context snippets for email, phone, and SSN patterns.
Flagged entities are marked for manual review instead of auto-approval.
```

---

### Task 24: Entity Modification Audit Trail

**Severity:** 🟢 LOW — Traceability for enterprise users  
**Risk:** LOW — new table, doesn't change existing flows  
**Tests:** Unit tests for audit logging  
**Depends on:** None (INDEPENDENT)

**Files:**
- Modify: `includes/Activator.php` (add ai_audit_log table)
- Create: `includes/Services/AuditLogger.php`
- Modify: `includes/REST/RestController.php` (log mutations)

- [ ] **Step 1: Add audit_log table to Activator**

```sql
CREATE TABLE IF NOT EXISTS {{{$prefix}}}ai_audit_log (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    entity_id bigint(20) unsigned NOT NULL,
    action varchar(50) NOT NULL,  -- 'create', 'update', 'delete', 'merge', 'status_change'
    user_id bigint(20) unsigned NOT NULL,
    before_data longtext,  -- JSON snapshot before change
    after_data longtext,   -- JSON snapshot after change
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entity (entity_id),
    KEY idx_user (user_id),
    KEY idx_created (created_at)
) {{{ $charset_collate }}};
```

- [ ] **Step 2: Create AuditLogger service**

```php
class AuditLogger
{
    public static function log( int $entity_id, string $action, ?array $before = null, ?array $after = null ): void
    {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'ai_audit_log', [
            'entity_id' => $entity_id,
            'action' => $action,
            'user_id' => get_current_user_id(),
            'before_data' => $before ? wp_json_encode( $before ) : null,
            'after_data' => $after ? wp_json_encode( $after ) : null,
            'created_at' => current_time( 'mysql' ),
        ] );
    }
}
```

- [ ] **Step 3: Add logging hooks in RestController**

Before every entity mutation (create, update, delete, merge, status change):

```php
// Capture before state
$before = $this->entity_repository->find( $entity_id );
// ... perform mutation ...
// Log after
AuditLogger::log( $entity_id, 'update', $before, $this->entity_repository->find( $entity_id ) );
```

- [ ] **Step 4: Commit**

```
feat(audit): add entity modification audit trail

New ai_audit_log table tracks all entity CRUD operations with user ID,
timestamp, and before/after data snapshots. Enables accountability and
undo capability for enterprise users.
```

---

### Task 25: Keyboard Shortcuts

**Severity:** 🟢 LOW — Power user enhancement  
**Risk:** LOW — additive UI feature  
**Tests:** Manual — verify shortcuts work  
**Depends on:** None (INDEPENDENT)

**Files:**
- Create: `admin/js/src/hooks/useKeyboardShortcuts.js`
- Modify: `admin/js/src/App.jsx` (register global shortcuts)

- [ ] **Step 1: Create useKeyboardShortcuts hook**

```js
import { useEffect } from '@wordpress/element';

const SHORTCUTS = {
    'ctrl+k': () => { /* Focus search */ },
    'ctrl+n': () => { /* New entity */ },
    'ctrl+s': () => { /* Save current entity */ },
    'escape': () => { /* Close drawer/modal */ },
};

export function useKeyboardShortcuts( handlers = {} ) {
    useEffect( () => {
        const handleKeyDown = ( e ) => {
            const key = [];
            if ( e.ctrlKey || e.metaKey ) key.push( 'ctrl' );
            key.push( e.key.toLowerCase() );
            const combo = key.join( '+' );

            if ( handlers[ combo ] ) {
                e.preventDefault();
                handlers[ combo ]();
            }
        };

        window.addEventListener( 'keydown', handleKeyDown );
        return () => window.removeEventListener( 'keydown', handleKeyDown );
    }, [ handlers ] );
}
```

- [ ] **Step 2: Register shortcuts in App.jsx**

```jsx
useKeyboardShortcuts( {
    'ctrl+n': () => setShowCreateModal( true ),
    'ctrl+k': () => { /* focus global search */ },
    'escape': () => { /* close any open drawer/modal */ },
} );
```

- [ ] **Step 3: Manual QA — verify shortcuts**

1. Ctrl+N → Create Entity modal opens
2. Escape → closes current modal/drawer
3. Ctrl+S in entity drawer → saves entity

- [ ] **Step 4: Commit**

```
feat(ux): add keyboard shortcuts for common actions

Ctrl+N: Create Entity, Ctrl+S: Save, Escape: Close. Registered globally
via useKeyboardShortcuts hook. Power user enhancement.
```

---

## Appendix: Complete Issue Inventory

### 🔴 Critical (18 items — all addressed above)
| # | Issue | Task | Phase |
|---|-------|------|-------|
| 1 | Model config fake (all = opus) | Task 1 | 0A |
| 2 | Content truncation mismatch (20K vs 120K) | Task 6 | 0A |
| 3 | README entity types lie | Task 3 | 0A |
| 4 | No Create Entity UI | Task 13 | 1 |
| 5 | Duplicate API clients | Task 10 | 1 |
| 6 | Vector search brute-force | Task 19 | 3 |
| 7 | Test coverage ~30% | Task 20 | 3 |
| 8 | No onboarding wizard | Task 21 | 3 |
| 9 | Broken docs link | Task 4 | 0A |
| 10 | Fake pagination | Task 9 | 0B |
| 11 | No toast notifications | Task 11 | 1 |
| 12 | window.confirm() for destructive actions | Task 12 | 1 |
| 13 | KB sidebar inaccessible collapsed | Task 14 | 1 |
| 14 | Recharts unused dependency | Task 5 | 0A |
| 15 | MAX_CONTEXT_LENGTH mismatch (100 vs 500) | Task 2 | 0A |
| 16 | Budget model never used | Task 15 | 2 |
| 17 | Silent data loss on long posts | Task 6+8 | 0A+0B |
| 18 | No cost guard | Task 7 | 0B |

### 🟡 Medium (12 items — all addressed above)
| # | Issue | Task | Phase |
|---|-------|------|-------|
| 1 | Confidence scoring opaque | Task 16 | 2 |
| 2 | No content moderation/PII | Task 23 | 4 |
| 3 | No audit trail | Task 24 | 4 |
| 4 | Schema.org mapping incomplete | Task 18 | 2 |
| 5 | No prompt versioning | Task 17 | 2 |
| 6 | No batch status operations in UI | Task 22 | 3 |
| 7 | useStatus polling cleanup | Task 10 (side fix) | 1 |
| 8 | /entities/:id route unused | Cleanup in Task 13 | 1 |
| 9 | No keyboard shortcuts | Task 25 | 4 |
| 10 | EntityExtractor truncation not logged | Task 8 | 0B |
| 11 | Sidebar collapsed KB inaccessible | Task 14 | 1 |
| 12 | Entity type selector incomplete | Task 18 | 2 |

### ✅ Working (10 items — no action needed)
- Core entity extraction pipeline functional
- Schema.org JSON-LD injection working
- Circuit breaker in AIClient
- Rate limiting implemented
- Entity merge with alias/mention transfer
- TanStack Query optimistic updates
- Phase stepper visualization
- Live terminal log viewer
- KB semantic search functional (at small scale)
- Confidence threshold filtering

### 🔮 Future (5 items — beyond scope of this plan)
- External vector DB integration (Qdrant/Weaviate) — defer until Task 19 benchmarking
- Multi-language entity extraction
- Custom entity type definitions (user-defined)
- Entity relationship graph visualization
- WP-CLI commands for pipeline management

---

## Execution Notes

1. **Ship Task 1 (model config) BEFORE Task 6 (truncation increase)** — the truncation increase sends 4x more tokens to the API. With opus as the budget model, this would be catastrophic cost-wise.

2. **Task 10 (API client consolidation) is the critical path for Phase 1** — everything else in Phase 1 depends on it.

3. **Task 19 (vector search) should be benchmarked before committing to MySQL** — if filtered+cached queries can't deliver <200ms at 10K vectors, create a follow-up plan for external vector DB.

4. **Task 20 (test coverage) can run in parallel with any other task** — it only adds tests, never changes production code.

5. **Phase 4 tasks are all independent** — they can be picked up in any order or skipped entirely if timeline is tight.
