# Plan Brief: Supabase Auth Scaffold

## What & Why

Add Supabase as the identity/user-management layer. The Expo client authenticates directly with Supabase; Laravel receives the resulting Supabase JWT and exchanges it for a Sanctum access+refresh token pair. Sanctum continues to protect all business routes unchanged.

Password-based login (`POST /api/auth/login`) is removed — Supabase handles all credentials.

## End State

- `POST /api/auth/supabase/exchange` (Bearer = Supabase JWT) → verifies JWT locally (HS256, no HTTP call), finds-or-creates Laravel User by `supabase_id`, returns Sanctum access+refresh pair
- `POST /api/auth/login` removed (returns 404)
- `POST /api/auth/refresh` and `POST /api/auth/logout` unchanged
- All tests pass, pint clean

## Phases

| # | Name | Key deliverables |
|---|------|-----------------|
| 1 | Migration + environment | `add_supabase_id_to_users_table` migration; `SUPABASE_JWT_SECRET=` in `.env.example` |
| 2 | JWT verifier service | `firebase/php-jwt`; `SupabaseJwtVerifier` contract + real impl + test fake; `AppServiceProvider` binding |
| 3 | Exchange endpoint + tests | `IssuesTokenPairs` trait; `SupabaseController::exchange()`; route `POST /api/auth/supabase/exchange`; 4 feature tests |
| 4 | Remove password-based login | Delete `login()`, its route, and its 2 tests from `AuthRefreshTest` |

## Key Design Decisions

- **Local JWT verification** — `firebase/php-jwt` decodes HS256 token with `SUPABASE_JWT_SECRET`. Zero Supabase HTTP calls per request. Lambda-safe.
- **Fake injected in tests** — `FakeSupabaseJwtVerifier` replaces the real verifier via `$this->app->instance(...)`. Tests run fully offline.
- **`updateOrCreate` not `firstOrCreate`** — handles the edge case where a seeded user has the same email but null `supabase_id`; avoids unique-constraint collision.
- **`email` required in claims** — 422 if Supabase JWT carries no `email` (acceptable MVP constraint for email-based auth).

## Files Touched

```
database/migrations/XXXX_add_supabase_id_to_users_table.php  (new)
.env.example                                                   (append)
app/Contracts/SupabaseJwtVerifier.php                          (new)
app/Services/SupabaseJwtVerifier.php                           (new)
app/Testing/FakeSupabaseJwtVerifier.php                        (new)
app/Providers/AppServiceProvider.php                           (edit)
app/Http/Controllers/Api/Concerns/IssuesTokenPairs.php         (new)
app/Http/Controllers/Api/AuthController.php                    (edit — use trait, remove login)
app/Http/Controllers/Api/SupabaseController.php                (new)
routes/api.php                                                  (edit — add exchange, remove login)
tests/Feature/SupabaseExchangeTest.php                         (new)
tests/Feature/AuthRefreshTest.php                               (edit — remove 2 login tests)
```
