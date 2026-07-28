<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Server-side AI Classification

- **Plan**: context/changes/classify-via-laravel/plan.md
- **Scope**: Phase 1 of 4
- **Date**: 2026-06-15
- **Verdict**: NEEDS ATTENTION → all findings triaged (2 fixed, 1 accepted)
- **Findings**: 0 critical  2 warnings  1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | WARNING |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — Throttle keyed by proxy IP behind API Gateway/Lambda

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: routes/api.php (throttle:10,1) + bootstrap/app.php (no trustProxies)
- **Detail**: `/classify` is unauthenticated and rate-limited by client IP, but no `trustProxies()` was configured. On Bref/Lambda behind API Gateway, `$request->ip()` resolves to the proxy, collapsing the 10/min bucket to ~global and weakening the only abuse guard on an open AI endpoint.
- **Fix**: Added `$middleware->trustProxies(at: '*')` in bootstrap/app.php so `$request->ip()` is the real client.
- **Decision**: FIXED

### F2 — Unplanned AI_VISION_MODEL default bump

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: config/services.php:46, .env.example
- **Detail**: `google/gemini-2.0-flash` → `google/gemini-2.5-flash` bump was an in-flight fix for the live 404, not in the phase-1 plan change set. Benign, recorded in the commit body.
- **Fix**: Accept as documented (no code change).
- **Decision**: ACCEPTED

### F3 — Validation failure may redirect instead of 422 on raw multipart

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Http/Controllers/Api/ClassifyController.php:23 (API-wide, pre-existing)
- **Detail**: `$request->validate()` returns 422 only when `expectsJson()`. A raw multipart POST without `Accept: application/json` rendered a 302 redirect on validation failure; tests passed only because `postJson` sets Accept.
- **Fix**: Recorded as a lesson AND fixed — added `ForceJsonResponse` middleware prepended to the `api` group + regression test (`test_classify_validation_returns_422_without_accept_header`).
- **Decision**: FIXED + ACCEPTED-AS-RULE: "API: walidacja zwraca 422 tylko gdy request expectsJson()"
