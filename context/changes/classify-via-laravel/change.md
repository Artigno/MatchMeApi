---
id: classify-via-laravel
status: impl_reviewed
created: 2026-06-13
updated: 2026-06-15
---

# Backend Changes — Server-side AI Classification

Companion to the mobile change `classify-via-laravel`. Split AI classification out of
the authed `POST /garments` into a new unauthenticated-but-app-secured `POST /classify`
endpoint. `/garments` becomes persist-only (accepts pre-classified fields + photo).

## Architecture (decided in planning)

- **`POST /classify`** — no Sanctum auth; gated by `X-App-Key` header (static shared
  secret in server env) + throttle. Multipart `photo` → AI → returns the five listing
  fields only. Stateless: no DB row, no media upload. Error split: `504` real timeout,
  `502/503` upstream API/parse failure.
- **`POST /garments`** — keeps Sanctum; stops calling AI; accepts pre-classified fields
  + `photo`, persists + hosts media, returns `GarmentDto`.
- `brand` removed from `PATCH /garments/{id}` (spec-aligned).
- Media disk: `public` in dev (resolvable `photo_url`), `s3` in prod.

## Divergence note

This plan changes the topology defined in the companion mobile doc (which had AI inside
`POST /garments`). `openapi.json` and the mobile client must be updated together — the
client now calls `/classify` first, then `POST /garments` with the returned fields.

See `plan.md` / `plan-brief.md`.
