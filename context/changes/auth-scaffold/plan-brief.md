---
change_id: auth-scaffold
plan_version: 1
complexity: LOW
phases: 3
estimated_files_changed: 5
---

# Plan Brief: Auth Scaffold (F-01)

## What & Why

Install `laravel/sanctum` and wire token-only API auth. Unlocks all protected routes — S-01 (register/login), S-02 (AI classification), S-04, S-05 cannot ship without this.

## Token Model

Two token types via Sanctum abilities + per-token `expires_at`:
- **access** — 5 min (`createToken('access', ['access'], now()->addMinutes(5))`)
- **refresh** — 30 days (`createToken('refresh', ['refresh'], now()->addDays(30))`)

No global expiration (`expiration: null` in `config/sanctum.php`). Sanctum 3.x checks `expires_at` automatically.

`EnsureFrontendRequestsAreStateful` **not** added — mobile token API, not SPA.

## Phases

| # | What | Key files |
|---|------|-----------|
| 1 | Sanctum install + configure | `composer.json`, `config/sanctum.php`, `app/Models/User.php` |
| 2 | Schema migrations | new `alter_users_make_name_nullable.php`, run `php artisan migrate` |
| 3 | Route scaffold + smoke test | `routes/api.php`, `tests/Feature/SanctumSmokeTest.php` |

## Done when

- `php artisan test --filter=SanctumSmokeTest` → 3 PASS
- `GET /api/ping` with valid Bearer → 200; expired/missing → 401
- `routes/api.php` has empty `auth:sanctum` group ready for S-01

## Risks

Low. Sole gotcha: never add `EnsureFrontendRequestsAreStateful` — causes 419 CSRF errors on token API.
