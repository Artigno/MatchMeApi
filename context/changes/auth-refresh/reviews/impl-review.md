<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Token refresh endpoint — access/refresh token pair rotation

- **Plan**: context/changes/auth-refresh/change.md (no plan.md — spec from change.md intent)
- **Scope**: Full change
- **Date**: 2026-05-28
- **Verdict**: APPROVED (after triage fixes)
- **Findings**: 2 critical  4 warnings  1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS (fixed) |
| Architecture | PASS (fixed) |
| Pattern Consistency | PASS (fixed) |
| Success Criteria | PASS |

## Findings

### F1 — No rate limiting on login endpoint

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: routes/api.php:9
- **Detail**: POST /api/auth/login had no throttle middleware. Trivially brute-forceable on a mobile-facing API.
- **Fix**: Added `->middleware('throttle:5,1')` to login route.
- **Decision**: FIXED

### F2 — Refresh token accepted on all auth:sanctum routes

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: routes/api.php:11-24
- **Detail**: Any valid Sanctum token (including 30-day refresh tokens) authenticated against all business routes. Stolen refresh token = full API access for 30 days.
- **Fix A ⭐ Applied**: Added `CheckForAnyAbility::class.':access'` middleware to business routes group. Added `tokenCan('access')` guard in logout(). Updated SanctumSmokeTest to assertForbidden() for refresh-token-on-ping test.
- **Decision**: FIXED via Fix A

### F3 — Non-atomic token rotation

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Http/Controllers/Api/AuthController.php:46-48
- **Detail**: refresh() deleted token then performed two separate INSERTs. Process kill after delete left user locked out.
- **Fix A ⭐ Applied**: Wrapped delete + issueTokenPair in DB::transaction().
- **Decision**: FIXED via Fix A

### F4 — Logout had no ability check — refresh token could de-authenticate account

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architecture
- **Location**: app/Http/Controllers/Api/AuthController.php:51-55
- **Detail**: logout() called tokens()->delete() with no ability check; refresh token could trigger it.
- **Fix**: Added `tokenCan('access')` guard at top of logout() (applied as part of F2 fix).
- **Decision**: FIXED (with F2)

### F5 — SanctumSmokeTest refresh-token-passes-ping asserted insecure behavior

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/SanctumSmokeTest.php:31-38
- **Detail**: test_valid_refresh_token_returns_200 locked in the insecure behavior; would block F2 fix.
- **Fix**: Renamed to test_refresh_token_rejected_on_business_routes, inverted to assertForbidden() (applied with F2 fix).
- **Decision**: FIXED (with F2)

### F6 — Token count hardcoded in rotation test

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/AuthRefreshTest.php:61
- **Detail**: assertDatabaseCount('personal_access_tokens', 2) encodes exact pair count — fragile if pair grows.
- **Fix**: Replaced with `$user->fresh()->tokens()->where('name', 'access')->exists()` + refresh equivalent.
- **Decision**: FIXED
