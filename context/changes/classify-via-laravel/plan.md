# Server-side AI Classification — Implementation Plan

## Overview

Split AI garment classification out of the authenticated `POST /garments` endpoint into
a new, dedicated `POST /classify` endpoint that is **unauthenticated but app-secured**
(static `X-App-Key` header + throttle) and **stateless** (reads the photo with AI, returns
the five listing fields, persists nothing). `POST /garments` becomes **persist-only**:
it accepts the already-classified fields plus the photo, stores the row, hosts the image,
and returns the full `GarmentDto`. Two smaller reconciliations ride along: `brand` is
removed from `PATCH /garments/{id}` (spec-aligned) and the media disk is configured so
`photo_url` actually resolves in dev (`public`) and prod (`s3`).

## Current State Analysis

The backend is already ~90% built and tested. Today, AI lives **inside** `POST /garments`:

- `GarmentController::classify()` (`app/Http/Controllers/Api/GarmentController.php`):
  validates `photo` (`image`, `max:10240`), base64-encodes it, calls the injected
  `GarmentClassifier`, creates+saves a `Garment`, attaches media to the `photos`
  collection, returns `garmentResource()`. On any `\RuntimeException` it returns `504`.
- `GarmentClassifierService` (`app/Services/GarmentClassifierService.php`): calls
  OpenRouter via `Http::timeout(25)`, parses a JSON object, normalises `condition`
  against `Garment::CONDITIONS`, returns the five-field array. Bound to the
  `GarmentClassifier` contract in `AppServiceProvider::register()`.
- `GarmentController::update()` validates and accepts `brand` (and category, color,
  condition, description).
- Media default disk = `FILESYSTEM_DISK` (`local` in `.env.example`).
- Routes (`routes/api.php`): `POST /garments` and the rest sit behind
  `auth:sanctum` + `CheckForAnyAbility:access`. `GET /up` is public; `GET /ping` is authed.
- Tests: `GarmentClassifyTest` (asserts `/garments` classifies + persists + 504s),
  `GarmentListingCardTest` (asserts `PATCH` mirrors `brand`, line 46), plus
  `FakeGarmentClassifier` (`app/Testing/FakeGarmentClassifier.php`).

### Key Discoveries:

- **Timeout contract is currently broken.** `Http::timeout(25)` throws
  `Illuminate\Http\Client\ConnectionException` (extends `\Exception`, **not**
  `\RuntimeException`) on a genuine network timeout, so the controller's
  `catch (\RuntimeException)` misses it → the request 500s instead of returning the
  spec's `504`. Conversely, API/parse errors throw `\RuntimeException` → labeled
  "Classification timed out" though they are not timeouts.
- **`brand` mismatch.** `GarmentController::update()` accepts `brand`; the spec doc says
  it is not PATCHable; the `openapi.json` `PATCH` request body is empty (`{}`).
  `GarmentListingCardTest:46` PATCHes `brand` and will break when brand is removed.
- **`photo_url` not resolvable in dev.** Media default disk = `local`, which stores under
  `storage/app` and is not web-served. The mobile client renders `photo_url` directly over
  LAN HTTP, so dev needs the `public` disk (+ `storage:link`); prod uses `s3`.
- No middleware aliases are registered in `bootstrap/app.php` (the `withMiddleware`
  closure is empty) — a new alias must be added there.
- CORS: Laravel 12's default `HandleCors` (`api/*`, origins `*`) already covers LAN dev —
  no change needed.
- `openapi.json` `POST /garments` body is `photo`-only with responses `200/504/422/401`;
  `PATCH` body is `{}`.

## Desired End State

- `POST /classify` exists, requires a valid `X-App-Key` header (not a Sanctum token),
  is rate-limited, accepts a `photo`, returns
  `{ category, brand, color, condition, description }` (all nullable) with no DB/storage
  side effects. Returns `504` on a real timeout, `502` on upstream API/parse failure,
  `422` on a missing/invalid/oversized photo, and `403` on a missing/wrong app key.
- `POST /garments` no longer calls AI. It accepts the five fields + `photo`, persists a
  user-owned `garments` row, hosts the photo, and returns the full `GarmentDto`. It no
  longer returns `504`.
- `PATCH /garments/{id}` no longer accepts `brand`.
- `photo_url` resolves in dev (`public` disk) and prod (`s3`).
- `openapi.json` matches all of the above; the mobile-companion divergence is recorded.
- Full test suite green, including new `/classify` coverage and rewritten `/garments`
  persist-only coverage.

**Verification:** `composer test` passes; `./vendor/bin/pint --test` clean; manual
`curl` against `/classify` with/without the app key behaves as specified; an image saved
via `/garments` renders from its `photo_url` in a browser.

## What We're NOT Doing

- Not rebuilding the auth chain (`exchange` / `refresh` / `logout`), `GET /user`,
  `GET /garments` pagination envelope, `GET /garments/{id}`, `DELETE`, `ping`, `up` —
  all present and correct; left untouched.
- Not changing the AI provider, prompt, model, or `GarmentClassifierService` internals
  beyond the error-mapping split.
- Not implementing HMAC signing for `/classify` (static shared key + throttle was chosen).
- Not hosting photos in `/classify` (stateless by decision).
- Not editing the mobile client in this repo (companion change owns that) — only recording
  the contract it must follow.
- Not authoring CI/CD or deployment infra.

## Implementation Approach

Extract AI first (Phase 1) so the `/garments` refactor (Phase 2) can lean on a known-good,
already-tested classification path. Then reconcile the two small spec gaps (Phase 3) and
finally sync the contract (Phase 4). Each phase is independently testable and commits
atomically. The `GarmentClassifier` contract + `GarmentClassifierService` are reused as-is
by `/classify`; only the error mapping moves from the controller into a clean
timeout-vs-upstream split.

## Critical Implementation Details

- **Error-mapping split.** Distinguish two failure classes at the boundary that calls the
  classifier: a real timeout (`Illuminate\Http\Client\ConnectionException`, or a
  `RuntimeException` the service explicitly raises for timeouts) → `504`; an upstream API
  non-2xx / unparseable response → `502`. The current single `catch (\RuntimeException)`
  cannot tell these apart — the service should surface them as distinct signals (either two
  exception types, or one typed exception carrying a reason) so the controller maps each to
  the right status. Do **not** let `ConnectionException` escape to a generic 500.
- **App-key middleware ordering.** The `X-App-Key` check must run before the throttle's
  cost is meaningful but the route should still carry `throttle` so a valid-key caller
  cannot hammer the AI either. Register the alias in `bootstrap/app.php`'s `withMiddleware`
  closure (currently empty).

---

## Phase 1: `POST /classify` — unauthenticated, app-secured, stateless AI

### Overview

Introduce the new classification endpoint, the app-key gate, and the corrected error
mapping. No persistence.

### Changes Required:

#### 1. App-key middleware

**File**: `app/Http/Middleware/EnsureAppKey.php` (new)

**Intent**: Reject any request whose `X-App-Key` header does not match the configured
server secret, before the controller runs. This is what limits `/classify` to the app.

**Contract**: Handle signature `handle(Request $request, Closure $next)`. Compares
`$request->header('X-App-Key')` to `config('services.app.client_key')` using
`hash_equals`. On mismatch/absence returns a `403` JSON `{ "message": "Forbidden." }`.
Registered as a route-middleware alias (e.g. `app.key`) in `bootstrap/app.php`.

#### 2. App-key config + env

**File**: `config/services.php`, `.env.example`

**Intent**: Surface the shared secret as configuration, never hard-coded.

**Contract**: Add `'app' => ['client_key' => env('APP_CLIENT_KEY')]` to `config/services.php`.
Add `APP_CLIENT_KEY=` to `.env.example` with a one-line comment that the mobile app sends
it as `X-App-Key`.

#### 3. Classify controller

**File**: `app/Http/Controllers/Api/ClassifyController.php` (new)

**Intent**: Validate the photo, run the shared classifier, return the five fields with no
side effects, mapping failures per the error split.

**Contract**: `__construct(private GarmentClassifier $classifier)`. Single action
`classify(Request $request): JsonResponse`. Validates `photo` => `['required','image','max:10240']`
(`422` on failure). Base64-encodes the file, calls `$this->classifier->classify(...)`,
returns `200` with `{ category, brand, color, condition, description }`. Maps a timeout
signal → `504` `{ "message": "Classification timed out, please retry." }`; an upstream
failure signal → `502` `{ "message": "Classification failed, please retry." }`.

#### 4. Error-mapping split in the classifier service

**File**: `app/Services/GarmentClassifierService.php`

**Intent**: Make timeout and upstream-failure distinguishable so the controller maps each
correctly, and stop a real `ConnectionException` from becoming a 500.

**Contract**: Catch `Illuminate\Http\Client\ConnectionException` from the `Http` call and
re-surface it as a timeout signal; keep the non-2xx / unparseable cases as an upstream
signal. Exact mechanism (two exception classes vs one typed exception with a reason enum)
is the implementer's call, but the controller must be able to branch `504` vs `502`.

#### 5. Route

**File**: `routes/api.php`

**Intent**: Expose `/classify` outside the Sanctum group, gated by the app key + throttle.

**Contract**: `Route::post('/classify', [ClassifyController::class, 'classify'])
->middleware(['app.key', 'throttle:10,1']);` placed outside the `auth:sanctum` group.

#### 6. Tests

**File**: `tests/Feature/ClassifyEndpointTest.php` (new)

**Intent**: Lock the new contract.

**Contract**: Cover — valid key + photo returns the five fields and writes **no** garment
row (`Garment::count() === 0`); missing/wrong `X-App-Key` → `403`; missing/invalid photo →
`422`; classifier timeout signal → `504`; classifier upstream signal → `502`. Use
`FakeGarmentClassifier` (extend it if needed to raise the two distinct signals). Set
`services.app.client_key` via `config()` in the test.

### Success Criteria:

#### Automated Verification:

- New + existing tests pass: `composer test`
- Classify suite passes: `php artisan test --filter=ClassifyEndpointTest`
- Style clean: `./vendor/bin/pint --test`

#### Manual Verification:

- `curl -F photo=@sample.jpg http://<host>/api/classify` without `X-App-Key` → `403`.
- Same with a correct `X-App-Key` → `200` and the five fields, no DB row created.
- Stopping the AI provider (or pointing at a dead URL) yields `504`/`502`, not `500`.

**Implementation Note**: After this phase and all automated verification passes, pause for
manual confirmation before Phase 2.

---

## Phase 2: Slim `POST /garments` to persist-only

### Overview

Remove AI from `POST /garments`; have it accept pre-classified fields + photo, persist,
host, and return the `GarmentDto`.

### Changes Required:

#### 1. Garments store action

**File**: `app/Http/Controllers/Api/GarmentController.php`

**Intent**: Replace the AI call with field validation + persistence; keep media hosting and
the `GarmentDto` response.

**Contract**: Rename/rework `classify()` → `store(Request $request)`. Validate
`category, brand, color, description` (`nullable|string`), `condition`
(`nullable|Rule::in(Garment::CONDITIONS)`), and `photo` (`required|image|max:10240`).
Create a `Garment` from the validated fields, set `user_id`, save, `addMedia($photo)
->toMediaCollection('photos')`, return `garmentResource()` with `200`. Remove the
`GarmentClassifier` constructor dependency and the `504` path (no AI here anymore).

#### 2. Route

**File**: `routes/api.php`

**Intent**: Point `POST /garments` at the renamed action.

**Contract**: `Route::post('/garments', [GarmentController::class, 'store']);` inside the
existing `auth:sanctum` + `access` group.

#### 3. Rewrite the garments-persist test

**File**: `tests/Feature/GarmentClassifyTest.php` → rename to
`tests/Feature/GarmentStoreTest.php`

**Intent**: Reflect persist-only behavior; AI is no longer exercised here.

**Contract**: Drop the `FakeGarmentClassifier` wiring and the `504`-on-throw test. Assert:
posting fields + photo with a valid Sanctum token persists a user-owned row with those
fields, hosts the photo (`photo_url` non-empty), and returns the full `GarmentDto`; auth is
still required (`401` without token); invalid `condition` → `422`; oversized/missing photo →
`422`.

### Success Criteria:

#### Automated Verification:

- Full suite passes: `composer test`
- Garments-store suite passes: `php artisan test --filter=GarmentStoreTest`
- Style clean: `./vendor/bin/pint --test`

#### Manual Verification:

- `POST /garments` (authed) with fields + photo returns the persisted `GarmentDto`.
- `GET /garments` lists the new row; `photo_url` is present.

**Implementation Note**: Pause for manual confirmation before Phase 3.

---

## Phase 3: Spec reconciliation — drop `brand` from PATCH + resolvable `photo_url`

### Overview

Two small spec/config fixes.

### Changes Required:

#### 1. Remove `brand` from PATCH validation

**File**: `app/Http/Controllers/Api/GarmentController.php`

**Intent**: Align `update()` to the spec — `brand` is classify-only, not user-editable.

**Contract**: Remove the `'brand' => [...]` rule from `update()`'s validation array so it
accepts only `category, color, condition, description`.

#### 2. Update the listing-card edit test

**File**: `tests/Feature/GarmentListingCardTest.php`

**Intent**: The test PATCHes `brand` (line ~46) and asserts it mirrors — that contract is
gone.

**Contract**: Change the PATCH-mirror assertions to use a PATCHable field (e.g. `category`
or `color`); ensure a `brand` value sent in PATCH is ignored (not persisted).

#### 3. Media disk configuration

**File**: `.env.example` (+ confirm `config/filesystems.php` / `config/media-library.php`)

**Intent**: Make `photo_url` resolvable: `public` disk in dev, `s3` in prod.

**Contract**: Set `FILESYSTEM_DISK=public` as the dev default in `.env.example` with a
comment to run `php artisan storage:link` and set an absolute `APP_URL`; document that prod
sets `FILESYSTEM_DISK=s3` with the existing `AWS_*` vars. Confirm the media library reads
the default disk (no per-collection override needed). Tests continue using `Storage::fake`.

### Success Criteria:

#### Automated Verification:

- Full suite passes: `composer test`
- Listing-card suite passes: `php artisan test --filter=GarmentListingCardTest`
- Style clean: `./vendor/bin/pint --test`

#### Manual Verification:

- `PATCH /garments/{id}` with `brand` in the body leaves `brand` unchanged.
- After `php artisan storage:link`, a photo saved via `/garments` opens directly from its
  `photo_url` in a browser over LAN.

**Implementation Note**: Pause for manual confirmation before Phase 4.

---

## Phase 4: `openapi.json` sync + companion-divergence note

### Overview

Bring the contract in line with the new topology and record the mobile divergence.

### Changes Required:

#### 1. Add `POST /classify`

**File**: `openapi.json`

**Intent**: Document the new endpoint.

**Contract**: Add path `/classify`: `multipart/form-data` body with `photo`
(binary, `maxLength: 10240`); responses `200` (object with the five nullable fields),
`403` (bad/missing app key), `422` (validation), `504` (timeout), `502` (upstream).
Document the `X-App-Key` header as a required security header (not bearer auth).

#### 2. Update `POST /garments` body + responses

**File**: `openapi.json`

**Intent**: `/garments` no longer classifies.

**Contract**: Change the `POST /garments` request body to `multipart/form-data` with
`photo` + the five fields (`category, brand, color, condition, description`, nullable;
`condition` enum). Responses become `200 / 422 / 401` (remove `504`).

#### 3. Fill `PATCH /garments/{garment}` body

**File**: `openapi.json`

**Intent**: The body is currently empty `{}`.

**Contract**: Define the PATCH body as `category, color, condition (enum), description`
(no `brand`). Confirm `GarmentDto` includes
`id, category, brand, color, condition, description, photo_url, created_at, updated_at`.

#### 4. Record the companion divergence

**File**: `context/changes/classify-via-laravel/change.md` (already notes it) — confirm the
note states the mobile client must call `/classify` then `POST /garments` with the returned
fields, and send `X-App-Key`.

### Success Criteria:

#### Automated Verification:

- `openapi.json` is valid JSON: `php -r "json_decode(file_get_contents('openapi.json'),true); echo json_last_error_message();"` prints `No error`.
- Full suite still green: `composer test`

#### Manual Verification:

- Spec paths/bodies match the implemented routes (spot-check `/classify`, `POST /garments`,
  `PATCH`).
- Mobile divergence note is clear enough for the client team to act on.

**Implementation Note**: Final phase — confirm the whole suite is green and the contract
reads correctly.

---

## Testing Strategy

### Unit / Feature Tests:

- `ClassifyEndpointTest` (new): app-key gate (`403`), validation (`422`), success shape +
  no persistence, `504` timeout signal, `502` upstream signal.
- `GarmentStoreTest` (rewritten from `GarmentClassifyTest`): persist-only success, auth
  required, condition enum validation, photo validation.
- `GarmentListingCardTest` (updated): PATCH no longer mirrors `brand`.
- Existing untouched suites (`AuthRefreshTest`, `SupabaseExchangeTest`, `WardrobeCatalogueTest`,
  `GarmentRemovalTest`, `GarmentSchemaTest`, `UserEndpointTest`, `SanctumSmokeTest`) must
  stay green — regression guard.

### Manual Testing Steps:

1. `curl` `/classify` without `X-App-Key` → `403`; with correct key + photo → `200` + fields,
   no DB row.
2. Authed `POST /garments` with fields + photo → persisted `GarmentDto`; `GET /garments`
   shows it.
3. Open the returned `photo_url` in a browser (after `storage:link`) → image loads.
4. `PATCH /garments/{id}` with `brand` → brand unchanged; with `category` → updated.
5. Kill the AI provider → `/classify` returns `504`/`502`, never `500`.

## Performance Considerations

`/classify` is unauthenticated, so the `throttle:10,1` limit is the primary guard against
AI-cost abuse; tune the rate if mobile UX needs more headroom. Classification is a single
synchronous OpenRouter call bounded by the 25s HTTP timeout.

## Migration Notes

No schema migrations. `brand` column stays (still set at create, still returned) — only the
PATCH write path drops it. Dev environments must run `php artisan storage:link` once after
switching `FILESYSTEM_DISK` to `public`.

## References

- Companion mobile change: `classify-via-laravel` (client must call `/classify` then
  `POST /garments`).
- Current AI path: `app/Http/Controllers/Api/GarmentController.php` (`classify()`),
  `app/Services/GarmentClassifierService.php`.
- Contract spec: `openapi.json` (MirrorMatch API 0.1.0).

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: POST /classify — unauthenticated, app-secured, stateless AI

#### Automated

- [x] 1.1 New + existing tests pass: `composer test` — d27e25b
- [x] 1.2 Classify suite passes: `php artisan test --filter=ClassifyEndpointTest` — d27e25b
- [x] 1.3 Style clean: `./vendor/bin/pint --test` — d27e25b

#### Manual

- [x] 1.4 `/classify` without `X-App-Key` → `403` — d27e25b
- [x] 1.5 `/classify` with correct key → `200` + five fields, no DB row — d27e25b
- [x] 1.6 Dead AI provider yields `504`/`502`, not `500` — d27e25b

### Phase 2: Slim POST /garments to persist-only

#### Automated

- [x] 2.1 Full suite passes: `composer test` — 5e3abcd
- [x] 2.2 Garments-store suite passes: `php artisan test --filter=GarmentStoreTest` — 5e3abcd
- [x] 2.3 Style clean: `./vendor/bin/pint --test` — 5e3abcd

#### Manual

- [x] 2.4 Authed `POST /garments` with fields + photo returns persisted `GarmentDto` — 5e3abcd
- [x] 2.5 `GET /garments` lists the new row with `photo_url` — 5e3abcd

### Phase 3: Spec reconciliation — drop brand from PATCH + resolvable photo_url

#### Automated

- [x] 3.1 Full suite passes: `composer test` — eaf5ecf
- [x] 3.2 Listing-card suite passes: `php artisan test --filter=GarmentListingCardTest` — eaf5ecf
- [x] 3.3 Style clean: `./vendor/bin/pint --test` — eaf5ecf

#### Manual

- [x] 3.4 `PATCH /garments/{id}` with `brand` leaves brand unchanged — eaf5ecf
- [x] 3.5 Saved photo opens directly from `photo_url` after `storage:link` — eaf5ecf

### Phase 4: openapi.json sync + companion-divergence note

#### Automated

- [x] 4.1 `openapi.json` is valid JSON
- [x] 4.2 Full suite still green: `composer test`

#### Manual

- [x] 4.3 Spec paths/bodies match implemented routes (spot-check)
- [x] 4.4 Mobile divergence note is clear for the client team
