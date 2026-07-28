# Mobile client sync contract — verification requests for the backend (Laravel API)

Audience: the backend agent working on the MirrorMatch Laravel API. This document
describes how the mobile client updates garments today, why it works the way it
does, and which API gaps we ask you to verify or close. Written against
`implemented-on-api.md` / `openapi.json` (MirrorMatch API 0.1.0) as of 2026-07-02.

## 1. How the client updates a garment (current flow)

The client is **local-first**: every garment lives in on-device storage
(AsyncStorage) with a client-generated string `id`. The server is a durable
mirror, not the source of truth for the editing session.

Edit flow (`src/app/garment/[id].tsx` → `src/services/sync-service.ts`):

```
1. User saves the edit form.
2. Local store is updated immediately with { ...fields, synced: false }.
3. If a session exists, pushGarment(garment) runs:
   a. garment.remoteId == null  → POST /garments (multipart photo + category,
      brand, color, condition, description). Response id is stored as remoteId,
      synced: true.
   b. garment.remoteId != null  → PATCH /garments/{remoteId} with
      { category, color, condition, description } (JSON). brand is NOT sent —
      the API ignores it on PATCH. synced: true only after a 2xx.
4. On failure the garment stays synced: false; a background catch-up
   (syncPending) re-runs pushGarment for every unsynced garment on the next
   app start / sign-in.
```

Client-side guards you should know about:

- `condition` outside the enum `new | like new | good | fair | worn` is dropped
  from the PATCH body (and from create parameters) so the request never 422s on
  free text. `condition: null` IS sent and is expected to clear the value.
- Empty-string fields are sent as `null` on PATCH; on create, `null` fields are
  omitted from the multipart parameters entirely.
- One transparent `401 → POST /auth/refresh → retry` per request.
- `GET /garments` pagination is followed via `links.next` until exhausted.

## 2. What `remoteId` is and why it exists

- The local `id` is a device-generated string. It must exist before any network
  call (guest mode, offline, instant save) and never changes.
- `remoteId` is the server's integer garment id, stored on the local row after
  the first successful `POST /garments`.
- It is the join key between a local row and its server twin. It decides
  create-vs-patch in `pushGarment` (without it, every re-push would create a
  duplicate server row), it lets the pull/reconcile flow match incoming DTOs to
  existing local rows, and it targets `DELETE /garments/{id}`.
- A garment with `remoteId == null` has never been mirrored; `synced: false`
  with a `remoteId` means "mirrored once, has unpushed local edits".

## 3. Requests to verify / implement on the API side

Ordered by impact on the client.

### 3.1 `brand` on PATCH — decide and document (HIGH)

Today `PATCH /garments/{id}` ignores `brand`. Consequence: a user can edit brand
locally forever, but the server copy is frozen at whatever the first
`POST /garments` carried. After a reinstall (pull from server) the user's brand
edits are silently lost.

Request: either
- (preferred) accept `brand` (nullable string, max 255) in PATCH, same
  validation as create, or
- confirm in the OpenAPI description that brand is immutable by design, so the
  client can lock the field in the edit UI instead of showing a "local only"
  hint.

### 3.2 Idempotent create — protect against duplicate rows (HIGH)

`POST /garments` is not idempotent. If the server persists the row but the
client never sees the response (timeout, connection drop), the client still has
`remoteId == null` and the next catch-up POSTs again → duplicate server row
with a re-uploaded photo.

Request: accept an optional client-supplied key, e.g. multipart field
`client_ref` (string, unique per user, the client would send its local id).
On a duplicate `(user_id, client_ref)` return the existing GarmentDto (200)
instead of creating a new row. Expose `client_ref` in GarmentDto so the client
can also re-link rows after reinstall.

### 3.3 Idempotent delete (MEDIUM)

`DELETE /garments/{id}` returns 404 when the row is already gone (or was never
visible to this user). The client treats that as a failure and may retry
forever. Request: return 204 for an already-deleted row belonging to the caller,
or document that 404 after a successful earlier delete is expected so the client
can treat 404 as success.

### 3.4 Conflict detection on PATCH (MEDIUM)

The client PATCHes blindly; the pull flow is "server wins". Two devices editing
the same garment lose the earlier write with no signal. Request (pick one):

- support `If-Unmodified-Since: <updated_at>` (or an `updated_at`/`version`
  field in the PATCH body) and answer `409` on mismatch, or
- confirm last-write-wins is acceptable for MVP and document it.

The client can adopt either; it already stores `updated_at` from the DTO.

### 3.5 Photo replacement (LOW)

Neither PATCH nor any other endpoint lets the client replace the photo of an
existing garment. If a user retakes the photo locally, the server copy keeps
the old image forever. Request: `POST /garments/{id}/photo` (multipart, same
10 MB limit) or accept `photo` on PATCH via multipart. Low priority — the
client UI does not offer retake yet, but the store layer already supports it.

### 3.6 Vocabulary endpoints (LOW)

The client ships hard-coded category slugs (`tops`, `bottoms`, `dresses`,
`outerwear`, `knitwear`, `footwear`, `bags`, `accessories`) and the condition
enum. `category` is a free string server-side. Request: confirm max length 255
and no server-side vocabulary is planned; if a `/categories` endpoint ever
lands, the client constant is built to be swapped for it.

## 4. Reference — current client-side type mapping

| API field | client field | editable in app | mirrored on PATCH |
|---|---|---|---|
| `id` (int) | `remoteId` | no | — (path param) |
| `category` | `category` (slug) | yes (chip picker) | yes |
| `brand` | `brand` | yes (text) | **no — API gap 3.1** |
| `color` | `color` (free text) | yes (text) | yes |
| `condition` | `condition` (enum) | yes (chip picker) | yes (enum-guarded) |
| `description` | `description` | yes (multiline) | yes |
| `photo_url` | `photoUri` | no | **no — API gap 3.5** |
| `created_at` | `createdAt` (epoch ms) | no | no |
| — | `id` (local string) | no | candidate for `client_ref` (3.2) |
| — | `synced` (bool) | no | client-only state |

Client sources, if you need to read the exact behavior:
`src/services/sync-service.ts` (push/reconcile), `src/services/sync.types.ts`
(DTO mapping), `src/services/garment-sync-client.ts` (HTTP layer, guards).
