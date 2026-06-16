<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Server-side AI Classification

- **Plan**: context/changes/classify-via-laravel/plan.md
- **Scope**: Full plan (Phases 1–4 of 4)
- **Date**: 2026-06-16
- **Verdict**: NEEDS ATTENTION → APPROVED (all 5 findings triaged + fixed 2026-06-16)
- **Findings**: 0 critical, 3 warnings, 2 observations — all FIXED

## Verdicts (post-triage)

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

Note: zero plan drift — every planned item is a MATCH with defensive hardening inside planned files. Two EXTRAs (ForceJsonResponse, trustProxies) fill real Lambda/multipart gaps and are commit-documented (bb390bf). Warnings are 1 real security tradeoff (F1) + pre-existing/out-of-scope noise.

## Findings

### F1 — /classify throttle defeatable via trustProxies(at:'*')

- **Severity**: ⚠️ WARNING
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Safety & Quality
- **Location**: bootstrap/app.php:29 + routes/api.php:15-16
- **Detail**: /classify is unauthenticated and calls a PAID AI provider, gated by a static X-App-Key (extractable from the distributed app binary) + throttle:10,1. The throttle keys on client IP, but trustProxies(at:'*') trusts client-supplied X-Forwarded-For — an attacker rotates that header to defeat the per-IP bucket, leaving only the extractable key. Net: cost-abuse surface on a paid endpoint.
- **Fix A ⭐ Recommended**: Pin trustProxies to the API Gateway CIDR + add a global cost ceiling on /classify.
  - Strength: Restores real per-IP limiting (XFF only honored from the gateway) and caps total AI spend regardless of IP spoofing.
  - Tradeoff: Need the gateway CIDR at deploy; global cap adds a knob to tune for mobile UX.
  - Confidence: MED — depends on Bref/API-Gateway ingress topology not yet wired in this repo.
  - Blind spot: Haven't confirmed prod ingress is solely API Gateway.
- **Fix B**: Accept as MVP risk, document the key as anti-casual-abuse only.
  - Strength: Zero work; plan explicitly chose static-key+throttle.
  - Tradeoff: Leaves the spoofable-XFF hole open in production.
  - Confidence: HIGH — matches the plan's stated MVP posture.
  - Blind spot: No alert if AI spend spikes.
- **Decision**: FIXED via Fix A (global-ceiling half) — added `classify` named limiter (10/IP + 100 global) in AppServiceProvider::boot(), route → `throttle:classify`. CIDR pin skipped as non-viable on API Gateway/Lambda.

### F2 — N+1 on media in GarmentController::index

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality (Performance)
- **Location**: app/Http/Controllers/Api/GarmentController.php:20-38
- **Detail**: getFirstMediaUrl('photos') called per row inside the resource mapper; spatie lazy-loads the media relation, so paginating 20 garments triggers an N+1 on the media table. Pre-existing in index (not part of this change's intent) but the file was touched.
- **Fix**: Add ->with('media') to the paginate query.
- **Decision**: FIXED — added `->with('media')` to index paginate query.

### F3 — Missing no-Accept 422 test for POST /garments

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/GarmentStoreTest.php
- **Detail**: lessons.md rule "API: walidacja zwraca 422 tylko gdy expectsJson()" mandates a no-Accept-header test for critical endpoints. /classify has it (test_classify_validation_returns_422_without_accept_header); the equally-critical multipart /garments store does not. ForceJsonResponse covers it at runtime, but the regression guard is absent.
- **Fix**: Add a test_store_returns_422_without_accept_header case posting multipart with no Accept header, asserting 422 (not 302).
- **Decision**: FIXED — added test_store_validation_returns_422_without_accept_header (raw multipart, no Accept, asserts 422 + no row).

### F4 — pint --test fails repo-wide on 3 out-of-scope files

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: app/Models/Garment.php, app/Http/Controllers/Api/SupabaseController.php, tests/Feature/WardrobeCatalogueTest.php
- **Detail**: Plan success criteria say "pint --test clean", but full-repo run fails on 3 files — NONE touched by this change (per-phase commits only ran pint on changed files, so this debt predates the change). The change's own files are pint-clean.
- **Fix**: Run ./vendor/bin/pint on the 3 files in a separate housekeeping commit (out of this change's scope).
- **Decision**: FIXED — ran pint on the 3 files; `pint --test` now clean repo-wide.

### F5 — getRealPath()/getMimeType() unguarded in ClassifyController

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality (Reliability)
- **Location**: app/Http/Controllers/Api/ClassifyController.php:28-32
- **Detail**: file_get_contents($file->getRealPath()) is (string)-cast so no fatal, but on a rare edge (temp file vanished) getRealPath() returns false → base64 of empty string shipped to the paid provider. 'image' validation runs first so likelihood is low.
- **Fix**: Guard — if getRealPath() === false, abort(422). Low priority.
- **Decision**: FIXED — added abort_if($path === false, 422) before base64 encode.
