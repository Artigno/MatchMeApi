# Server-side AI Classification — Plan Brief

> Full plan: `context/changes/classify-via-laravel/plan.md`

## What & Why

Move AI garment classification out of the authenticated `POST /garments` into a dedicated
`POST /classify` endpoint that is unauthenticated but app-secured (`X-App-Key` + throttle)
and stateless. `/garments` becomes persist-only. This lets a guest preview classification
without a per-user login while keeping the AI key server-side, and removes the two-job
overloading of `/garments`.

## Starting Point

The backend is already ~90% built and tested: `POST /garments` currently classifies **and**
persists (`GarmentController::classify` + `GarmentClassifierService` via OpenRouter), with
passing tests. The work is a refactor + reconciliation, not a greenfield build.

## Desired End State

`POST /classify` reads a photo with AI and returns the five listing fields, gated by an app
key, persisting nothing. `POST /garments` accepts those fields + the photo, persists the
row, hosts the image, and returns the `GarmentDto`. `brand` is no longer PATCHable, and
`photo_url` resolves in dev and prod. `openapi.json` and the test suite match.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Topology | `/classify` classify-only; `/garments` persists provided fields | One AI path; guest preview, account to save | Plan |
| `/classify` auth | Unauthenticated + `X-App-Key` header + `throttle:10,1` | "No authorization" requirement, but cap AI-cost abuse | Plan |
| `/classify` state | Pure stateless — fields only, no DB/storage | Matches "read data from the photo"; safe unauthenticated | Plan |
| `brand` in PATCH | Remove (align code to spec) | Spec doc is source of truth; client already omits brand | Plan |
| Classify errors | Split `504` timeout / `502` upstream | Fixes real timeout-escapes-as-500 bug | Plan |
| Storage | `public` dev / `s3` prod | `photo_url` must resolve for the LAN mobile client | Plan |

## Scope

**In scope:** new `/classify` endpoint + app-key middleware + error-split; slim `/garments`
to persist-only; drop `brand` from PATCH; media disk config; `openapi.json` sync; tests.

**Out of scope:** auth chain / user / list / show / delete / ping / up (already correct);
AI prompt/model changes; HMAC signing; mobile client code; CI/CD & deploy infra.

## Architecture / Approach

```
mobile --(X-App-Key, photo)--> POST /classify  --AI--> {category,brand,color,condition,description}
mobile --(Sanctum, fields+photo)--> POST /garments --persist+host--> GarmentDto
```

`GarmentClassifierService` is reused by `/classify`; the timeout-vs-upstream error split
moves into a clean boundary so `ConnectionException` → `504` and upstream failure → `502`.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. `/classify` | Unauth, app-keyed, stateless AI endpoint + error split | Getting `504` vs `502` mapping right (ConnectionException) |
| 2. Slim `/garments` | Persist-only store accepting fields + photo | Rewriting currently-passing `GarmentClassifyTest` |
| 3. Reconcile | Drop `brand` from PATCH; resolvable `photo_url` | `GarmentListingCardTest:46` PATCHes brand — must update |
| 4. Spec sync | `openapi.json` updated + companion divergence note | Spec drift if bodies don't match routes |

**Prerequisites:** OpenRouter key configured; `APP_CLIENT_KEY` chosen; `storage:link` for dev.
**Estimated effort:** ~1–2 sessions across 4 phases.

## Open Risks & Assumptions

- **Companion divergence:** this changes the topology in the mobile `classify-via-laravel`
  doc (AI was inside `/garments`). The client must update to call `/classify` then
  `/garments`, sending `X-App-Key`. openapi + client update together.
- **App secret in binary:** a static `X-App-Key` ships in the distributable and is
  extractable; `throttle` + per-IP limits are the mitigation, env-rotatable.
- Assumes `502` (added to `/classify`) is acceptable spec surface even though the original
  doc only listed `504` for the in-`/garments` path.

## Success Criteria (Summary)

- `/classify` returns fields for app-keyed callers, `403` otherwise, persists nothing.
- `/garments` persists provided fields + hosts a resolvable `photo_url`.
- `brand` no longer PATCHable; full suite green; `openapi.json` matches the routes.
