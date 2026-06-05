<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Supabase Auth Scaffold

- **Plan**: context/changes/supabase-auth-scaffold/plan.md
- **Scope**: All phases (1–4 of 4)
- **Date**: 2026-05-29
- **Verdict**: NEEDS ATTENTION → FIXED
- **Findings**: 1 critical  4 warnings  3 observations (all fixed)

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | WARNING |
| Safety & Quality | FAIL |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Findings

### F1 — Empty JWT secret accepted as valid

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Services/SupabaseJwtVerifier.php:17
- **Detail**: When SUPABASE_JWT_SECRET is absent or blank (misconfigured Lambda env, fresh developer checkout), firebase/php-jwt accepts any token signed with an empty string as valid HS256. Silent auth bypass — anyone can forge a token in a misconfigured environment.
- **Fix**: Add empty-secret guard at top of verify(): `if (empty($secret)) { throw new \RuntimeException('Supabase JWT secret is not configured.'); }`
  - Strength: Fails fast with a clear message instead of silently accepting forged tokens.
  - Tradeoff: Minimal — one guard line.
  - Confidence: HIGH — firebase/php-jwt Key constructor accepts empty string without error.
  - Blind spot: None significant.
- **Decision**: FIXED

### F2 — Unplanned random password stored on every exchange

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Scope Discipline / Safety & Quality
- **Location**: app/Http/Controllers/Api/SupabaseController.php:38
- **Detail**: Plan specified `updateOrCreate(['supabase_id' => $sub], ['email' => $email])`. Actual adds `'password' => bcrypt(str()->random(40))` to values array. This: (1) runs bcrypt on every exchange including existing users — bcrypt is intentionally slow; (2) overwrites email on every exchange, which throws unhandled QueryException 500 if another user already owns that email (unique constraint on users.email).
- **Fix A ⭐ Recommended**: Only set password on creation; catch email collision — move password to firstOrCreate pattern or check existence before upsert. Also add QueryException catch around updateOrCreate to return 409 on email collision instead of 500.
  - Strength: Bcrypt only runs on new users; email collision returns a proper error response.
  - Tradeoff: Slightly more code in the controller.
  - Confidence: HIGH — the collision risk is real; users.email has a unique index from original migration.
  - Blind spot: None significant.
- **Fix B**: Make password column nullable via migration — add `$table->string('password')->nullable()->change();`, remove password from updateOrCreate entirely.
  - Strength: Cleanest schema — Supabase users never need a password.
  - Tradeoff: New migration + confirms password-less users are intentionally supported.
  - Confidence: MED — need to verify UserFactory and seeders don't break.
  - Blind spot: Need to check UserFactory and any seeders.
- **Decision**: FIXED (Fix A)

### F3 — Missing sub claim allows null supabase_id match

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Services/SupabaseJwtVerifier.php:29
- **Detail**: `$decoded->sub` returned without checking it exists. A valid HS256 token missing sub claim yields `['sub' => null, ...]`. That null reaches `updateOrCreate(['supabase_id' => null], ...)` which matches every existing user whose supabase_id IS NULL — potentially updating a random user.
- **Fix**: Add: `if (empty($decoded->sub)) { throw new \RuntimeException('Token missing required sub claim.'); }` before returning.
  - Strength: Closes null-sub path; mirrors how email is guarded in the controller (422 on null email).
  - Tradeoff: One extra check in the verifier.
  - Confidence: HIGH — supabase_id column allows NULL and has no per-row guard.
  - Blind spot: None significant.
- **Decision**: FIXED

### F4 — Token pair creation not wrapped in DB::transaction

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Http/Controllers/Api/SupabaseController.php:36-41
- **Detail**: updateOrCreate + issueTokenPair (two INSERTs) are not in a transaction. A process kill between access and refresh token writes leaves the user with a half-issued pair. AuthController::refresh() wraps the same pattern in DB::transaction() — the exchange endpoint should match.
- **Fix**: Wrap in `DB::transaction()` the same way refresh() does.
  - Strength: Matches the established pattern in AuthController.php:38.
  - Tradeoff: Trivial — one wrapping closure.
  - Confidence: HIGH — directly analogous to AuthController::refresh().
  - Blind spot: None significant.
- **Decision**: FIXED

### F5 — dropColumn without explicit dropUnique in down()

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: database/migrations/2026_05_29_153820_add_supabase_id_to_users_table.php:21
- **Detail**: down() calls `dropColumn('supabase_id')` without first dropping the unique index. Works on SQLite (dev/test) but fails on MySQL/Aurora (production target per CLAUDE.md) where you must drop the index before the column.
- **Fix**: Add `$table->dropUnique(['supabase_id']);` before `dropColumn('supabase_id')`.
  - Strength: Safe on all drivers including Aurora (production).
  - Tradeoff: One extra line.
  - Confidence: HIGH — Aurora is the stated production target.
  - Blind spot: None significant.
- **Decision**: FIXED

### F6 — Missing test for null email claim (422 branch untested)

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/SupabaseExchangeTest.php
- **Detail**: Plan called for 4 tests. The 422 (null email claim) branch in SupabaseController.php:32-34 has no test. Reachable in production (Supabase magic-link user with unconfirmed email).
- **Fix**: Add `test_exchange_without_email_claim_returns_422()` using `FakeSupabaseJwtVerifier(['sub' => 'uuid', 'email' => null])`.
- **Decision**: FIXED

### F7 — declare(strict_types=1) absent from controllers and trait

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Http/Controllers/Api/SupabaseController.php, app/Http/Controllers/Api/Concerns/IssuesTokenPairs.php
- **Detail**: New infrastructure files (Contracts/, Services/, Testing/) all have declare(strict_types=1). New controllers and the trait do not. Existing controllers also lack it — consistent with siblings — but new service layer set a stricter standard.
- **Fix**: Add `declare(strict_types=1);` to SupabaseController.php and IssuesTokenPairs.php.
- **Decision**: FIXED

### F8 — bind() instead of singleton() for stateless service

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Providers/AppServiceProvider.php
- **Detail**: bind() instantiates a new SupabaseJwtVerifier per resolution. The service is stateless — singleton() is more appropriate and avoids unnecessary object creation per request.
- **Fix**: Change `bind()` to `singleton()` in AppServiceProvider.
- **Decision**: FIXED
