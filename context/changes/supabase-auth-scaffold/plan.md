# Supabase Auth Scaffold Implementation Plan

## Overview

Add Supabase as the user management / identity layer. The Expo mobile client authenticates directly with Supabase; Laravel receives the resulting Supabase JWT and exchanges it for a Sanctum access+refresh token pair. Sanctum continues to protect all business routes unchanged.

## Current State Analysis

- Sanctum token-pair auth in place (F-01 + auth-refresh): `POST /api/auth/login` (password), `POST /api/auth/refresh`, `POST /api/auth/logout`
- `users` table: `id`, `name` (nullable), `email` (unique), `password`, timestamps — no Supabase identity field
- `firebase/php-jwt` not installed; no JWT verifier exists
- `AppServiceProvider::register()` is empty — ready for bindings
- `issueTokenPair()` is a private method on `AuthController` — must be extracted to a shared trait before a second controller can use it
- `name` nullable migration already applied (`2026_05_27_183555_alter_users_make_name_nullable`)

## Desired End State

After this plan:
- `POST /api/auth/supabase/exchange` (Bearer = Supabase JWT) → verifies JWT locally (HS256), finds-or-creates Laravel User by `supabase_id`, returns Sanctum access+refresh pair
- `POST /api/auth/login` (password) removed; Supabase handles all credential auth
- All existing `/api/auth/refresh` and `/api/auth/logout` behaviour unchanged
- `php artisan test` → all pass; `pint --test` → clean

## What We're NOT Doing

- No Supabase SDK on the Laravel side — only JWT verification
- No social auth configuration (Supabase manages OAuth providers)
- No webhook from Supabase (user sync is pull-based on first exchange)
- No email change synchronisation between Supabase and `users.email`
- No migration of existing test users from password-based to Supabase identity
- No Supabase Admin API calls

## Implementation Approach

Thin JWT verification layer: `firebase/php-jwt` decodes the HS256-signed Supabase JWT locally using `SUPABASE_JWT_SECRET`. No HTTP call to Supabase per request — pure local verification, Lambda-safe. `SupabaseJwtVerifier` is injected via a contract so tests swap in a fake without real JWT signing.

## Critical Implementation Details

**`email` required in exchange claims** — `users.email` is a unique NOT NULL column. If the Supabase JWT carries no `email` claim (can happen with certain social providers), the endpoint returns 422. This is acceptable for MVP (email-based auth targets the resale use case).

**Collision on first exchange** — if a User record already exists with the same `email` but `supabase_id IS NULL` (e.g. a seeded test user), `firstOrCreate` will create a second record and fail on the unique email constraint. Handle via `updateOrCreate(['supabase_id' => $sub], ['email' => $email])` which upserts correctly. This also handles the case where a user's Supabase ID was previously null.

---

## Phase 1: Migration + environment

### Overview

Add `supabase_id` to the `users` table and wire the environment variable.

### Changes Required:

#### 1. Migration: add `supabase_id` to users

**File**: new migration via `php artisan make:migration add_supabase_id_to_users_table`

**Intent**: Add a nullable, unique `supabase_id` string column to `users` so each Laravel user can be linked to a Supabase identity. Nullable because existing users (seeded / created before Supabase integration) have no Supabase ID yet.

**Contract**: `$table->string('supabase_id')->nullable()->unique()` in `up()`; `$table->dropColumn('supabase_id')` in `down()`.

#### 2. Environment: SUPABASE_JWT_SECRET

**File**: `.env.example`

**Intent**: Document the required env variable so any developer or CI environment knows what to set.

**Contract**: Append `SUPABASE_JWT_SECRET=` (empty value, to be filled per environment).

### Success Criteria:

#### Automated Verification:

- `php artisan migrate --pretend` shows the new `add_supabase_id_to_users_table` migration
- `php artisan migrate` applies without error
- `php artisan migrate:status` lists the migration as Ran
- `php artisan test` — no regressions

#### Manual Verification:

- `supabase_id` column exists in the `users` table with a unique index

---

## Phase 2: JWT verifier service

### Overview

Install `firebase/php-jwt`, define the `SupabaseJwtVerifier` contract, implement the real verifier and a test fake, and register the binding.

### Changes Required:

#### 1. Install `firebase/php-jwt`

**File**: `composer.json` (via `composer require firebase/php-jwt`)

**Intent**: Provide HS256 JWT decoding for Supabase token verification.

**Contract**: `"firebase/php-jwt": "^6.0"` appears in `require` block.

#### 2. Contract interface

**File**: `app/Contracts/SupabaseJwtVerifier.php` (new)

**Intent**: Define the verifiable surface so `SupabaseController` depends on an abstraction, not a concrete class.

**Contract**:
```php
interface SupabaseJwtVerifier
{
    /**
     * Verify a Supabase JWT and return its claims.
     *
     * @return array{sub: string, email: string|null}
     * @throws \RuntimeException on invalid signature, expiry, or malformed token
     */
    public function verify(string $token): array;
}
```

#### 3. Real implementation

**File**: `app/Services/SupabaseJwtVerifier.php` (new)

**Intent**: Decode and verify the HS256 Supabase JWT using the env secret, return `sub` and `email` claims.

**Contract**: Reads `SUPABASE_JWT_SECRET` from config/env. Calls `Firebase\JWT\JWT::decode($token, new Key($secret, 'HS256'))`. Catches `ExpiredException`, `SignatureInvalidException`, and `\Exception` — re-throws as `\RuntimeException`. Returns `['sub' => $decoded->sub, 'email' => $decoded->email ?? null]`.

#### 4. Fake for tests

**File**: `app/Testing/FakeSupabaseJwtVerifier.php` (new)

**Intent**: Injectable fake that returns a pre-configured claims array or throws on demand — lets feature tests run offline without a real Supabase secret.

**Contract**: Constructor accepts `?array $payload` and `?string $throwMessage`. When `$throwMessage` is non-null, `verify()` throws `\RuntimeException($throwMessage)`. Otherwise returns `$payload ?? ['sub' => '00000000-0000-0000-0000-000000000001', 'email' => 'test@supabase.local']`.

#### 5. Service container binding

**File**: `app/Providers/AppServiceProvider.php`

**Intent**: Bind the contract to the real implementation so the container resolves `SupabaseJwtVerifier` automatically everywhere except tests.

**Contract**: In `register()`, add `$this->app->bind(\App\Contracts\SupabaseJwtVerifier::class, \App\Services\SupabaseJwtVerifier::class)`.

### Success Criteria:

#### Automated Verification:

- `composer require firebase/php-jwt` succeeds
- `php artisan test` — no regressions

#### Manual Verification:

- `php artisan tinker` → `app(\App\Contracts\SupabaseJwtVerifier::class)` resolves to `App\Services\SupabaseJwtVerifier` instance

---

## Phase 3: Exchange endpoint + tests

### Overview

Extract `issueTokenPair` to a shared trait, create `SupabaseController` with the exchange action, wire the route, and write feature tests using the fake verifier.

### Changes Required:

#### 1. Extract `issueTokenPair` to trait

**File**: `app/Http/Controllers/Api/Concerns/IssuesTokenPairs.php` (new)

**Intent**: Move the private `issueTokenPair(User $user): array` method from `AuthController` into a reusable trait so `SupabaseController` can also produce Sanctum token pairs without duplication.

**Contract**: Trait contains exactly the `issueTokenPair(User $user): array` method body and the `ACCESS_TTL_MINUTES` / `REFRESH_TTL_DAYS` constants currently in `AuthController`.

#### 2. Use trait in `AuthController`

**File**: `app/Http/Controllers/Api/AuthController.php`

**Intent**: Replace the inline constants + private method with `use IssuesTokenPairs` and remove the duplicated code.

**Contract**: Add `use IssuesTokenPairs;`. Remove `private const ACCESS_TTL_MINUTES`, `private const REFRESH_TTL_DAYS`, and the `issueTokenPair()` method body.

#### 3. Create `SupabaseController`

**File**: `app/Http/Controllers/Api/SupabaseController.php` (new)

**Intent**: Handle `POST /api/auth/supabase/exchange` — extract Bearer token, verify via injected `SupabaseJwtVerifier`, find-or-create `User` by `supabase_id`, return Sanctum token pair.

**Contract**:
- Constructor injects `SupabaseJwtVerifier $verifier`
- `exchange(Request $request): JsonResponse`:
  1. `$token = $request->bearerToken()` — if null, return `401 {"message": "Token required."}`
  2. `$claims = $this->verifier->verify($token)` wrapped in try/catch `\RuntimeException` — on catch, return `401 {"message": "Invalid or expired token."}`
  3. If `$claims['email']` is null, return `422 {"message": "Email claim required."}`
  4. `User::updateOrCreate(['supabase_id' => $claims['sub']], ['email' => $claims['email']])`
  5. Return `response()->json($this->issueTokenPair($user), 200)`

#### 4. Register route

**File**: `routes/api.php`

**Intent**: Add the exchange endpoint outside any Sanctum guard, throttled like the existing auth endpoints.

**Contract**: Inside `Route::prefix('auth')->group(...)`, add:
```php
Route::post('supabase/exchange', [SupabaseController::class, 'exchange'])->middleware('throttle:5,1');
```

#### 5. Feature tests

**File**: `tests/Feature/SupabaseExchangeTest.php` (new)

**Intent**: Cover the four key paths: new user created on first exchange, existing user found on repeat exchange, missing token returns 401, invalid JWT returns 401.

**Contract**: 4 tests using `RefreshDatabase` + `$this->app->instance(SupabaseJwtVerifier::class, new FakeSupabaseJwtVerifier(...))`:
- `test_exchange_creates_new_user_and_returns_token_pair` — fake returns `{sub: uuid1, email: new@test.com}`; asserts `assertCreated()` or `assertOk()`, token pair in response, User created in DB
- `test_exchange_finds_existing_user_by_supabase_id` — create User with `supabase_id = uuid1`; fake returns same sub; asserts same user ID, no new User created
- `test_exchange_without_token_returns_401` — no Authorization header; asserts `assertUnauthorized()`
- `test_exchange_with_invalid_jwt_returns_401` — fake throws `\RuntimeException`; asserts `assertUnauthorized()`

### Success Criteria:

#### Automated Verification:

- `php artisan test --filter=SupabaseExchangeTest` → 4 tests PASS
- `php artisan test` → full suite PASS
- `./vendor/bin/pint --test` → PASS

#### Manual Verification:

- `POST /api/auth/supabase/exchange` with a real Supabase JWT (from the Expo app or Supabase dashboard token tool) returns a valid Sanctum token pair

---

## Phase 4: Remove password-based login

### Overview

Delete the `POST /api/auth/login` endpoint, remove its route and test coverage. Supabase now handles all credential-based auth.

### Changes Required:

#### 1. Remove `login()` from `AuthController`

**File**: `app/Http/Controllers/Api/AuthController.php`

**Intent**: Delete the password-based login action and its now-unused imports (`Auth` facade, `ValidationException`).

**Contract**: Remove `login()` method body. Remove `use Illuminate\Support\Facades\Auth;` and `use Illuminate\Validation\ValidationException;` imports.

#### 2. Remove login route

**File**: `routes/api.php`

**Intent**: The login endpoint no longer exists; remove its route declaration.

**Contract**: Remove `Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');` from the `prefix('auth')` group.

#### 3. Remove login tests from `AuthRefreshTest`

**File**: `tests/Feature/AuthRefreshTest.php`

**Intent**: Remove the two test methods that tested the now-deleted login endpoint.

**Contract**: Delete `test_login_returns_access_and_refresh_tokens()` and `test_login_fails_with_wrong_password()`.

### Success Criteria:

#### Automated Verification:

- `php artisan test` → full suite PASS (remaining 5 tests in `AuthRefreshTest` + 4 in `SupabaseExchangeTest` + all others)
- `./vendor/bin/pint --test` → PASS

#### Manual Verification:

- `POST /api/auth/login` returns 404 (route no longer exists)

---

## Testing Strategy

### Unit Tests:

None — business logic is thin (JWT decode, user find-or-create). Feature tests cover the meaningful paths.

### Integration Tests (Feature):

- `SupabaseExchangeTest` — 4 tests covering the exchange flow

### Manual Testing Steps:

1. Set `SUPABASE_JWT_SECRET` in `.env` to a Supabase project's JWT secret
2. Obtain a valid Supabase JWT (sign in via Supabase dashboard or Expo app)
3. `POST /api/auth/supabase/exchange` with `Authorization: Bearer <supabase-jwt>` → expect `{access_token, refresh_token, token_type, expires_in}`
4. Use returned `access_token` on `GET /api/ping` → expect 200
5. Confirm `POST /api/auth/login` returns 404

## References

- Sanctum ability enforcement: `routes/api.php` (already using `CheckForAnyAbility::class.':access'`)
- Existing token pair logic: `app/Http/Controllers/Api/AuthController.php::issueTokenPair`
- Firebase PHP JWT: https://github.com/firebase/php-jwt

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Migration + environment

#### Automated

- [x] 1.1 `php artisan migrate --pretend` shows add_supabase_id_to_users_table — 10c560c
- [x] 1.2 `php artisan migrate` applies without error — 10c560c
- [x] 1.3 `php artisan migrate:status` — migration Ran — 10c560c
- [x] 1.4 `php artisan test` — no regressions — 10c560c

#### Manual

- [x] 1.5 `supabase_id` column exists in users table with unique index — 10c560c

### Phase 2: JWT verifier service

#### Automated

- [x] 2.1 `composer require firebase/php-jwt` succeeds — cb9fccc
- [x] 2.2 `php artisan test` — no regressions — cb9fccc

#### Manual

- [x] 2.3 `app(\App\Contracts\SupabaseJwtVerifier::class)` resolves to real implementation in tinker — cb9fccc

### Phase 3: Exchange endpoint + tests

#### Automated

- [x] 3.1 `php artisan test --filter=SupabaseExchangeTest` — 4 tests PASS — 136635e
- [x] 3.2 `php artisan test` — full suite PASS — 136635e
- [x] 3.3 `./vendor/bin/pint --test` — PASS — 136635e

#### Manual

- [x] 3.4 Real Supabase JWT → POST /api/auth/supabase/exchange → valid Sanctum token pair returned — 136635e

### Phase 4: Remove password-based login

#### Automated

- [x] 4.1 `php artisan test` — full suite PASS
- [x] 4.2 `./vendor/bin/pint --test` — PASS

#### Manual

- [x] 4.3 `POST /api/auth/login` returns 404
