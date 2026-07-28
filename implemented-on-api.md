# Implemented on API — MirrorMatch Backend (for the mobile app)

What the Laravel backend exposes today. Pair this with the copied `openapi.json` (machine
contract); this doc is the "how to integrate" layer. Source of truth = the code; if this doc
and `openapi.json` disagree, `openapi.json` wins (it is generated from the code).

## Base

- **Base URL** (prod): `https://pit93h9570.execute-api.eu-central-1.amazonaws.com/api`
- All routes are prefixed `/api`. JSON only — no HTML, no cookies, no sessions.
- **Every request MUST send `Accept: application/json`.** Without it, validation failures
  redirect (302) instead of `422`. The server force-JSONs the `api` group, but send it anyway.
- Auth = **Sanctum bearer tokens** (`Authorization: Bearer <token>`), except `/classify`
  (app key) and the public endpoints below.

## Language

The AI listing card is returned **in Polish**. `category`, `condition`, `color`, and
`description` are Polish; `brand` is verbatim (proper noun). `condition` and `category` are
fixed value sets — see below. There is **no** EN→PL mapping for the client to do; the values
the API emits are already Polish and are the same values you POST back to `/garments`.

## Auth model — two token types

`POST /auth/supabase/exchange` and `POST /auth/refresh` return a **token pair**:

```json
{
  "access_token": "…",   // ability: access — all business endpoints
  "refresh_token": "…",  // ability: refresh — ONLY /auth/refresh
  "token_type": "Bearer",
  "expires_in": 900       // access TTL in seconds (15 min)
}
```

- **access_token**: TTL 15 min. Bearer on `/user`, `/ping`, all `/garments*`.
- **refresh_token**: TTL 30 days. Bearer ONLY on `/auth/refresh`. Using it on a business
  endpoint → `403 {"message":"Invalid token type."}`.
- `refresh` rotates: deletes the current access token, issues a fresh pair. Store the new one.
- `logout` requires an **access** token; deletes ALL of the user's tokens.

Flow: Supabase login → `exchange` Supabase JWT for our pair → use access token → on
`401`/expiry call `refresh` → on logout call `logout`.

## Value sets

- **category** (Polish): `góra` · `dół` · `buty` · `akcesorium` · `okrycie wierzchnie` (or `null`)
- **condition** (Polish): `nowy` · `jak nowy` · `dobry` · `średni` · `znoszony` (or `null`)

These exact strings are what `/classify` returns AND what `POST /garments` / `PATCH` accept.
Posting any other `condition` value → `422`.

## Endpoints

### Public (no bearer)

| Method | Path | Notes |
|---|---|---|
| GET | `/up` | Health → `200 {"status":"ok"}`. |
| POST | `/auth/supabase/exchange` | Send the Supabase JWT as `Authorization: Bearer <supabase_jwt>` (no JSON body). Throttled `5/min`. Returns the token pair. |
| POST | `/classify` | App-key gated, see below. |

`exchange` responses: `200` pair · `401 {"message":"Token required."}` · `401 {"message":"Invalid or expired token."}` · `422 {"message":"Email claim required."}` · `409 {"message":"Email already in use."}`.

### POST /classify — AI classification (app-key, NOT bearer)

Stateless. Reads a photo, returns the five Polish listing fields. **Persists nothing.**

- **Header**: `X-App-Key: <shared secret>` (required). Wrong/missing → `403 {"message":"Forbidden."}`.
- **Body**: `multipart/form-data`, field `photo` (image, max 10 MB / `10240` KB).
- **Throttle**: named limiter `classify` — 10/min per client + 100/min global hard cap → `429`.
- **200** (all fields nullable, Polish except `brand`):

```json
{ "category": "góra", "brand": "Zara", "color": "niebieski", "condition": "dobry", "description": "Ładny niebieski top w dobrym stanie." }
```

`category` ∈ the category set; `condition` ∈ the condition set; `color`/`description` are free
Polish text; `brand` verbatim. Any field the AI can't determine confidently is `null` — never
a guessed value.
- **Errors**: `422` bad/missing/oversized photo · `502 {"message":"Classification failed, please retry."}` · `504 {"message":"Classification timed out, please retry."}`. Never `500` for these.

### Authenticated (access bearer + `access` ability)

| Method | Path | Notes |
|---|---|---|
| GET | `/user` | `{id, email, name, created_at}`. |
| GET | `/ping` | `{status:"ok", user_id}`. |
| POST | `/garments` | Persist-only (no AI). Idempotent via `client_ref`. See below. |
| GET | `/garments` | Paginated list of the caller's garments. |
| GET | `/garments/{id}` | One garment. Not yours → `404`. |
| PATCH | `/garments/{id}` | Edit fields (incl. `brand`). Optional optimistic-lock header. See below. |
| POST | `/garments/{id}/photo` | Replace the photo. See below. |
| DELETE | `/garments/{id}` | Hard-delete + audit snapshot → `204`. **Idempotent**: re-deleting a garment you already deleted → `204` again (not `404`). Someone else's / never-existed id → `404`. |

Missing/expired access token → `401`. Another user's garment → `404` (existence not leaked).

### POST /garments — persist a classified garment

Classify first via `/classify`, then send the returned fields + photo here.

- **Body**: `multipart/form-data`:
  - `photo` (required, image, max 10 MB)
  - `client_ref` (optional string, max 255) — client-supplied idempotency key, unique per
    user. Send your local garment id. If a garment with the same `(user, client_ref)`
    already exists, the API returns **the existing `GarmentDto`** (200) and creates
    nothing — safe to retry a create whose response was lost. Without `client_ref`,
    every POST creates a new row.
  - `category`, `brand`, `color`, `description` (nullable string; max 255 / description 5000)
  - `condition` (nullable, one of the condition set)
- **200**: full `GarmentDto` (includes `client_ref`). Invalid `condition` / bad photo → `422`.

### PATCH /garments/{id}

- **Body** (`application/json`): any of `category`, `brand`, `color`, `condition`, `description`.
- **`brand` IS editable** (nullable string, max 255 — same validation as create). `photo` is
  not editable here — use `POST /garments/{id}/photo`.
- `condition` ∈ the condition set. `200` → updated `GarmentDto`; invalid → `422`.
- **Optional conflict detection**: send `If-Unmodified-Since: <updated_at you last saw>`
  (ISO 8601 or HTTP-date). If the server copy was modified after that timestamp →
  `409 { "message": "…", "garment": GarmentDto }` (current server state, nothing written).
  Unparseable header value → `400`. Without the header, last-write-wins (as before).

### POST /garments/{id}/photo — replace the photo

- **Body**: `multipart/form-data`, field `photo` (required, image, max 10 MB) — same rules
  as create.
- **200**: updated `GarmentDto` with the new `photo_url`. The old file is deleted; `updated_at`
  is bumped. Not yours → `404`; bad photo → `422`.

## GarmentDto

```json
{
  "id": 1,
  "client_ref": "local-abc-123",
  "category": "góra",
  "brand": "Zara",
  "color": "niebieski",
  "condition": "dobry",
  "description": "Ładny niebieski top.",
  "photo_url": "https://…",
  "created_at": "2026-06-17T12:00:00.000000Z",
  "updated_at": "2026-06-17T12:00:00.000000Z"
}
```

`GET /garments` wraps a list: `{ "data": [GarmentDto, …], "meta": {current_page,last_page,per_page,total}, "links": {first,last,prev,next} }`. Page size **20**, newest-first.

## The classify → persist flow

```
1. POST /classify   (X-App-Key, multipart photo)            → { category, brand, color, condition, description }  // Polish
2. (user edits the card if they want)
3. POST /garments   (access Bearer, multipart photo + fields) → GarmentDto (persisted, photo hosted)
```

The values from step 1 are posted verbatim in step 3 — same Polish strings, no translation.

## Error shape

All errors: `{"message": "…"}`. Validation errors add `errors`:
`{ "message": "…", "errors": { "photo": ["The photo field is required."] } }`.

Status codes: `200` · `204` · `400` · `401` · `403` · `404` · `409` · `422` · `429` · `502` · `504`.

## Vocabulary (for the client's hard-coded lists)

`category` is a free string server-side (max **255**); the Polish category set above is the
AI classifier's allow-list, not a validation rule on `/garments`. No `/categories` endpoint
is planned — keep the client constant. `condition` IS validated against the condition set.

## Not yet implemented

Outfit recommendation (wardrobe + context → suggestion) is in the PRD but NOT built. Only
classification + wardrobe CRUD + auth exist today.
