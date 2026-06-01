# AI Classification Endpoint — Plan Brief

> Full plan: `context/changes/ai-classification/plan.md`

## What & Why

Implements S-02 (North Star): `POST /api/garments` lets an authenticated user upload a garment photo and receive a filled listing card in return. The endpoint calls Gemini 2.0 Flash via OpenRouter, saves the classified garment, attaches the photo via Spatie Media Library, and returns a full resource with `id`. This is the core product hypothesis: if AI can extract useful listing data from a photo, the product has a reason to exist.

## Starting Point

The garment schema (`garments` table + Garment model with `photos` media collection) is in place from F-02. The service contract pattern (`Contracts/`, `Services/`, `Testing/Fake*`) is established from the Supabase auth scaffold. `OPENROUTER_API_KEY`, `OPENROUTER_BASE_URL`, and `AI_VISION_MODEL` are already wired in `serverless.yml` via SSM.

## Desired End State

`POST /api/garments` with a photo file and a valid access token returns 200 with `{id, category, brand, color, condition, description, photo_url, created_at}`. Uncertain fields are `null`. AI failure returns 504 with no DB write. S3 bucket configured → photos persist on Lambda.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Save on classify | Yes — one request creates + classifies | S-03 requires garment ID; two-step would complicate the client | Plan |
| Image encoding | Base64 inline | No public S3 URL needed; Gemini 2.0 Flash supports inline base64 | Plan |
| Confidence enforcement | Prompt instructs null for uncertain | Aligns with lessons.md null-not-wrong rule; no confidence score parsing | Plan + lessons.md |
| AI timeout | 504, garment NOT saved | Clean failure; client retries; no partial DB state | Plan |
| Max upload | 10 MB | Covers all practical phone photos; keeps Lambda memory reasonable | Plan |
| HTTP client | Laravel Http facade (Guzzle wrapper) | Established pattern; no raw Guzzle needed | Research |
| Testing | FakeGarmentClassifier (no real API calls) | Matches FakeSupabaseJwtVerifier pattern; CI-safe | Plan |
| S3 setup | Prerequisite, not automated | Infrastructure concern separate from feature code | Plan |

## Scope

**In scope:** `GarmentClassifier` contract + `GarmentClassifierService` (OpenRouter) + `FakeGarmentClassifier` + `GarmentController::classify()` + route + 5 feature tests + S3 setup docs

**Out of scope:** Async classification, retry logic, PATCH/GET garments (S-03/S-04), S3 bucket creation automation, confidence scores

## Architecture / Approach

```
POST /api/garments (auth:sanctum + access)
  └─ GarmentController::classify()
       ├─ validate photo (image, max 10MB)
       ├─ base64-encode from /tmp
       ├─ GarmentClassifier::classify(base64, mimeType)  ← injectable
       │    └─ GarmentClassifierService → Http::post(OpenRouter /chat/completions)
       │         └─ Gemini 2.0 Flash → JSON {category, brand, color, condition, description}
       ├─ new Garment($fields); $garment->user_id = auth->id; $garment->save()
       ├─ $garment->addMedia($file)->toMediaCollection('photos')  → S3 / local
       └─ return {id, ...fields, photo_url, created_at}
```

On `RuntimeException` from classifier: return 504, no save.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Service layer | GarmentClassifier contract + OpenRouter service + fake | Gemini prompt returns unstructured output; nullableString() must be robust |
| 2. HTTP endpoint | POST /api/garments route + controller | user_id IDOR if set via mass-assignment; Lambda /tmp write |
| 3. Feature tests | 5 test cases, all green, CI-safe | Storage::fake() must prevent real disk writes |
| 4. Prerequisites doc | S3 setup documented in env-config.md | Lambda deploy silent-fails without bucket |

**Prerequisites:** F-01 ✅, F-02 ✅, S-01 ✅, `OPENROUTER_API_KEY` in SSM ✅  
**Estimated effort:** ~1-2 sessions across 4 phases

## Open Risks & Assumptions

- Gemini 2.0 Flash cold response time: roadmap notes 2–10s; combined with Lambda cold-start (~750ms) stays within 28s budget in nominal case — under load this margin shrinks
- S3 bucket must be created before Lambda deploy; local dev works without it
- `addMedia()` after `save()` — if S3 write fails, garment row is orphaned; acceptable for MVP, not atomic

## Success Criteria (Summary)

- `POST /api/garments` with a photo returns 200 with a garment `id` and classification fields (some `null` is normal)
- All 5 feature tests pass in CI without an OpenRouter API key
- `php artisan route:list` shows the route behind `auth:sanctum` + `access` ability guard
