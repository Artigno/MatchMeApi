<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Public OpenAPI Docs on GitHub Pages

- **Plan**: context/changes/api-docs/plan.md
- **Scope**: Phases 1–3 (all)
- **Date**: 2026-06-05
- **Verdict**: NEEDS ATTENTION → resolved (F1 fixed during triage)
- **Findings**: 0 critical, 1 warning, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING (F1, fixed) |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS (manual 3.5–3.9 pending post-merge) |

## Findings

### F1 — Empty SCRAMBLE_SERVER_URL ships http://localhost as prod server

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality (Reliability)
- **Location**: config/scramble.php:99
- **Detail**: `env('SCRAMBLE_SERVER_URL', '<placeholder>')` uses the default only when the key is ABSENT. CI sets the var from `${{ vars.SCRAMBLE_SERVER_URL }}`; when the repo variable is undefined, GitHub passes an empty string, so the default never applies and Scramble's url() helper resolves '' to APP_URL → published spec advertises `servers: [{ url: "http://localhost" }]` as Production. Verified empirically. Echoes the project lesson (emit a clear sentinel, not a plausible-but-wrong value).
- **Fix**: `'Production' => env('SCRAMBLE_SERVER_URL') ?: 'https://api.example.invalid/api'` — empty string now also falls back. Verified: empty → placeholder; set → passthrough; Pint passes.
- **Decision**: FIXED

### F2 — security_strategy enabled beyond plan contract

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Scope Discipline
- **Location**: config/scramble.php:167
- **Detail**: Plan Phase-1 contract named title/version/servers only; implementation also enabled `MiddlewareAuthSecurityStrategy`. Benign and correct — spec documents Sanctum bearer auth and marks public routes accordingly. Serves the "complete contract for the mobile agent" goal.
- **Decision**: ACCEPTED (documented in commit d373266)

### F3 — Pages concurrency is job-level, plan said workflow-level

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🔎 MEDIUM
- **Dimension**: Architecture
- **Location**: .github/workflows/deploy.yml (publish-docs.concurrency)
- **Detail**: Deliberate deviation. Workflow-level `group: pages` would serialize the deploy jobs too; job-scoped concurrency is correct for a shared workflow.
- **Decision**: ACCEPTED (intentional)

### F4 — 3 pre-existing symfony advisories (out of scope)

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🔎 MEDIUM
- **Dimension**: Safety & Quality
- **Location**: composer.lock (symfony/http-foundation, symfony/polyfill-intl-idn, symfony/routing)
- **Detail**: Surfaced by `composer require`. All Laravel transitive deps, present with `--no-dev` → NOT introduced by Scramble (dev-only). Separate housekeeping (`composer update symfony/*`); not part of this change.
- **Decision**: ACCEPTED (out of scope; tracked separately)
