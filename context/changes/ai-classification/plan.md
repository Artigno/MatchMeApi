# AI Classification Endpoint Implementation Plan

## Overview

Implements S-02: `POST /api/garments` — authenticated user uploads a garment photo, the API classifies it via Gemini 2.0 Flash (OpenRouter), saves the Garment row + attaches the photo via Spatie Media Library, and returns the full garment resource. Fields the AI cannot determine with high confidence are returned as `null` (lessons.md rule: never plausible-but-wrong).

## Current State Analysis

- `OPENROUTER_API_KEY`, `OPENROUTER_BASE_URL`, `AI_VISION_MODEL` already in `serverless.yml` via SSM ✅
- Garment model: `photos` single-file media collection, `$fillable` = `[category, brand, color, condition, description]`; `user_id` NOT in `$fillable` (IDOR fix) ✅
- Service layer pattern established: `app/Contracts/` + `app/Services/` + `app/Testing/Fake*` + `AppServiceProvider` binding ✅
- Guzzle 7 + Laravel `Http` facade available
- Spatie Media Library 11 installed, uses `FILESYSTEM_DISK` env (`local` dev, `s3` Lambda)
- Lambda web timeout: **28 seconds** (API Gateway hard limit: 29s)
- **S3 bucket not configured** — prerequisite for Lambda deploy (see Phase 4)

## Desired End State

`POST /api/garments` with a `photo` file and a valid Sanctum access token returns 200 with `{id, category, brand, color, condition, description, photo_url, created_at}`. Uncertain fields are `null`. AI timeout or failure returns 504. Garment NOT saved on failure.

### Key Discoveries

- `app/Contracts/SupabaseJwtVerifier.php` — contract interface pattern to follow (typed return array in docblock)
- `app/Services/SupabaseJwtVerifier.php` — service reads config via `config('services.supabase.jwt_secret')`
- `app/Testing/FakeSupabaseJwtVerifier.php` — fake accepts optional constructor payload, can throw
- `app/Providers/AppServiceProvider.php:13` — `$this->app->bind(Contract::class, Service::class)`
- Lambda `/tmp` is writable — uploaded files land there; Spatie `addMedia()` reads from real path ✅
- `serverless.yml:43` — `timeout: 28`; Guzzle timeout must be ≤ 25s to leave room for response handling

## What We're NOT Doing

- Async classification (SQS queue + polling) — synchronous is within 28s budget for MVP
- Confidence score parsing from Gemini — using prompt-level null instruction instead
- PATCH /api/garments/{id} — that's S-03
- GET /api/garments — that's S-04
- S3 bucket creation — documented as prerequisite in Phase 4, not automated here
- Retry logic on AI failure — client retries by re-uploading

---

## Phase 1: Service layer

### Overview

Introduce the `GarmentClassifier` contract, its real OpenRouter implementation, a fake for tests, wire into `AppServiceProvider`, and add the `openrouter` config entry.

### Changes Required

#### 1. Contract

**File**: `app/Contracts/GarmentClassifier.php`

**Intent**: Define the injectable classifier boundary so the controller and tests are decoupled from OpenRouter.

**Contract**:
```php
interface GarmentClassifier
{
    /**
     * @return array{category: string|null, brand: string|null, color: string|null,
     *               condition: string|null, description: string|null}
     * @throws \RuntimeException on API failure, timeout, or unexpected response shape
     */
    public function classify(string $base64Image, string $mimeType): array;
}
```

#### 2. Service

**File**: `app/Services/GarmentClassifierService.php`

**Intent**: Call OpenRouter `/chat/completions` with a base64-encoded image and a structured JSON prompt. Parse the response and enforce the null-for-uncertain rule by sanitising each field before returning.

**Contract**: Reads `config('services.openrouter.api_key')`, `config('services.openrouter.base_url')`, `config('services.openrouter.model')`. Uses Laravel `Http` facade with `->timeout(25)`. On non-2xx status or unparseable JSON → throw `\RuntimeException`. `condition` field accepted only if it's one of: `new`, `like new`, `good`, `fair`, `worn`; otherwise null. Other string fields: null if the parsed value is `null`, empty string, or literal `"null"`.

System prompt instructs Gemini to return valid JSON only — no markdown — with these 5 keys, and to return `null` for any field it cannot determine with high confidence.

#### 3. Fake

**File**: `app/Testing/FakeGarmentClassifier.php`

**Intent**: Test double for `GarmentClassifier`. Constructor accepts optional `?array $result` and `bool $shouldThrow`. Default result contains all five fields populated. Used by feature tests via `$this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier(...))`.

#### 4. Provider binding

**File**: `app/Providers/AppServiceProvider.php`

**Intent**: Add `$this->app->bind(GarmentClassifier::class, GarmentClassifierService::class)` alongside existing `SupabaseJwtVerifier` binding.

#### 5. Services config

**File**: `config/services.php`

**Intent**: Add `openrouter` key with `api_key`, `base_url`, `model` — reads from env vars already present in `serverless.yml`.

```php
'openrouter' => [
    'api_key'  => env('OPENROUTER_API_KEY'),
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'model'    => env('AI_VISION_MODEL', 'google/gemini-2.0-flash'),
],
```

#### 6. .env.example

**File**: `.env.example`

**Intent**: Add `OPENROUTER_API_KEY=`, `OPENROUTER_BASE_URL=https://openrouter.ai/api/v1`, `AI_VISION_MODEL=google/gemini-2.0-flash` so local developers know which vars are required.

### Success Criteria

#### Automated Verification

- `composer test` passes (no new tests yet, existing suite still green)
- `php artisan config:clear` succeeds (no config syntax errors)

#### Manual Verification

- `config('services.openrouter.api_key')` readable in tinker with OPENROUTER_API_KEY set in .env

---

## Phase 2: HTTP endpoint

### Overview

Add `GarmentController::classify()` and wire `POST /api/garments` inside the authenticated route group.

### Changes Required

#### 1. Controller

**File**: `app/Http/Controllers/Api/GarmentController.php`

**Intent**: Validate the uploaded photo, base64-encode it, call the classifier, save the Garment (setting `user_id` explicitly — IDOR guard), attach the photo via Spatie, and return the garment resource. On classifier `RuntimeException`, return 504 without saving.

**Contract**:
- Constructor injection: `private readonly GarmentClassifier $classifier`
- Validation: `photo` required, `image` MIME check, `max:10240` (10 MB)
- `user_id` set via `$garment->user_id = $request->user()->id` — NOT from `$fillable`
- Garment created with `new Garment($fields); $garment->save()` then `$garment->addMedia($file)->toMediaCollection('photos')`
- Response fields: `id`, `category`, `brand`, `color`, `condition`, `description`, `photo_url` (via `$garment->getFirstMediaUrl('photos')`), `created_at`
- Returns HTTP 200 on success, 504 on classifier failure, 422 on validation failure (Laravel default)

#### 2. Route

**File**: `routes/api.php`

**Intent**: Register `POST /api/garments` inside the existing `['auth:sanctum', CheckForAnyAbility::class.':access']` group, alongside `GET /user` and `GET /ping`. Import `GarmentController`.

### Success Criteria

#### Automated Verification

- `composer test` passes (existing tests unaffected; no new tests yet in this phase)
- `php artisan route:list | grep garments` shows the route with correct middleware

#### Manual Verification

- `POST /api/garments` with a valid access token and a real photo returns 200 with classification fields (some may be null) and a `photo_url`
- `POST /api/garments` without a token returns 401
- Response `id` is a valid integer; `photo_url` points to a stored file

---

## Phase 3: Feature tests

### Overview

Cover the happy path, null fields, auth guard, validation, and 504 failure using `FakeGarmentClassifier` and `Storage::fake('local')`.

### Changes Required

#### 1. Test file

**File**: `tests/Feature/GarmentClassifyTest.php`

**Intent**: 5 test cases exercising the endpoint end-to-end with faked classifier and faked storage.

**Test cases**:
1. `test_classify_creates_garment_and_returns_resource` — valid photo + fake classifier with full fields → 200, DB has garment row, response has all 8 fields
2. `test_classify_returns_null_fields_when_ai_uncertain` — fake classifier returns all-null result → 200, all 5 classification fields null in response
3. `test_classify_requires_authentication` — no token → 401
4. `test_classify_validates_photo_is_required` — missing photo field → 422
5. `test_classify_returns_504_when_classifier_throws` — `FakeGarmentClassifier(shouldThrow: true)` → 504, no Garment row in DB

**Contract**: Each test binds the fake via `$this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier(...))`. Uses `Storage::fake('local')` to avoid real file writes. Uses `UploadedFile::fake()->image('garment.jpg')`.

### Success Criteria

#### Automated Verification

- `composer test` passes (all 25 tests green including the 5 new ones)

#### Manual Verification

- All 5 test methods appear in output with ✓

---

## Phase 4: Prerequisites documentation

### Overview

Document the S3 bucket setup required for Lambda deploy and add missing env vars to `.env.example`. No code changes — pure documentation.

### Changes Required

#### 1. env-config.md

**File**: `context/foundation/env-config.md`

**Intent**: Add an "S3 Bucket (photo storage)" section with `aws s3api create-bucket` command, bucket policy (block all public access), and the two SSM parameters needed: `AWS_BUCKET` and `FILESYSTEM_DISK=s3`. Note that without this, `POST /api/garments` on Lambda will fail silently on photo attach.

### Success Criteria

#### Automated Verification

- `grep 'AWS_BUCKET' context/foundation/env-config.md` → match

#### Manual Verification

- env-config.md S3 section is readable and actionable before next Lambda deploy

---

## Critical Implementation Details

**Lambda `/tmp` and Spatie Media Library**: On Lambda, only `/tmp` is writable. Laravel's `local` disk writes to `storage/app/` — this fails silently on Lambda. Setting `FILESYSTEM_DISK=s3` (and having `AWS_BUCKET` configured) is the only supported path for Lambda. Tests use `Storage::fake('local')` which bypasses real filesystem entirely.

**Guzzle timeout ≤ 25s**: Lambda web timeout is 28s. The Http facade call to OpenRouter must use `->timeout(25)` to leave 3s for response serialisation and DB write before API Gateway cuts the connection at 29s.

**`user_id` not in `$fillable`**: The Garment model explicitly excludes `user_id` from fillable (IDOR fix). The controller MUST set it via `$garment->user_id = $request->user()->id` — not via mass assignment.

---

## References

- Roadmap S-02: `context/foundation/roadmap.md:118`
- Lessons (null rule): `context/foundation/lessons.md`
- Garment model: `app/Models/Garment.php`
- Service pattern: `app/Contracts/SupabaseJwtVerifier.php`, `app/Services/SupabaseJwtVerifier.php`
- Fake pattern: `app/Testing/FakeSupabaseJwtVerifier.php`
- Infrastructure: `context/foundation/infrastructure.md` (Lambda cold-start + timeout analysis)

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <sha>` when step lands.

### Phase 1: Service layer

#### Automated

- [x] 1.1 Create `app/Contracts/GarmentClassifier.php` — 3890802
- [x] 1.2 Create `app/Services/GarmentClassifierService.php` — 3890802
- [x] 1.3 Create `app/Testing/FakeGarmentClassifier.php` — 3890802
- [x] 1.4 Update `app/Providers/AppServiceProvider.php` — add binding — 3890802
- [x] 1.5 Update `config/services.php` — add openrouter entry — 3890802
- [x] 1.6 Update `.env.example` — add OPENROUTER vars — 3890802
- [x] 1.7 `composer test` passes — 3890802

#### Manual

- [x] 1.8 `config('services.openrouter.api_key')` readable in tinker — 3890802

### Phase 2: HTTP endpoint

#### Automated

- [x] 2.1 Create `app/Http/Controllers/Api/GarmentController.php` — fb74588
- [x] 2.2 Update `routes/api.php` — add POST /api/garments — fb74588
- [x] 2.3 `composer test` passes — fb74588
- [x] 2.4 `php artisan route:list | grep garments` shows route — fb74588

#### Manual

- [x] 2.5 POST /api/garments with real photo + access token → 200 with classification + photo_url — fb74588
- [x] 2.6 POST /api/garments without token → 401 — fb74588

### Phase 3: Feature tests

#### Automated

- [x] 3.1 Create `tests/Feature/GarmentClassifyTest.php` (5 test cases) — 8dc25b8
- [x] 3.2 `composer test` passes (25 total tests green) — 8dc25b8

#### Manual

- [x] 3.3 All 5 new test methods appear with ✓ in output — 8dc25b8

### Phase 4: Prerequisites documentation

#### Automated

- [x] 4.1 Update `context/foundation/env-config.md` — add S3 section
- [x] 4.2 `grep 'AWS_BUCKET' context/foundation/env-config.md` → match

#### Manual

- [x] 4.3 S3 section is readable and actionable
