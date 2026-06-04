# Plan Brief: wardrobe-catalogue

S-04 — `GET /api/garments`: paginated wardrobe list.

## Contract

- Route: `GET /api/garments` (auth:sanctum + access ability)
- Scope: `WHERE user_id = auth()->id() AND deleted_at IS NULL`
- Order: `created_at DESC`
- Page size: fixed 20
- Item shape: `garmentResource()` — 9 fields (id, category, brand, color, condition, description, photo_url, created_at, updated_at)
- Envelope: `{ data: [...], meta: {current_page, last_page, per_page, total}, links: {first, last, prev, next} }`

## Phases

| Phase | What | Files |
|-------|------|-------|
| 1 | `index()` method + route | GarmentController.php, routes/api.php |
| 2 | 4 feature tests | tests/Feature/WardrobeCatalogueTest.php |

## Key notes

- `SoftDeletes` on `Garment` auto-scopes `deleted_at IS NULL` — no `whereNull` needed
- `$paginator->getCollection()->map(fn(Garment $g) => $this->garmentResource($g))->values()` — transform items after paginate
- No filters, no cursor, no per_page param
