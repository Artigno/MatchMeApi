# Listing Card Review / Edit — Plan Brief

> Full plan: `context/changes/listing-card-edit/plan.md`

## What & Why

S-03 adds `GET /api/garments/{id}` and `PATCH /api/garments/{id}` so the mobile user can review and correct the AI-generated listing card before copying it to Vinted. Without these endpoints the classified card is write-once — any AI mistake requires re-uploading the photo.

## Starting Point

`GarmentController` has one method (`classify()`). `routes/api.php` has a placeholder comment at line 27 marking where S-03 routes belong. The `Garment` model already has all 5 fillable classification fields and soft-delete support.

## Desired End State

A user can fetch any garment they own and see a 9-field listing card including `updated_at`. They can PATCH only the fields they want to change — absent keys are untouched, explicit null clears a field. Ownership is enforced: other users' garments return 404 (not 403).

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| PATCH null semantics | Present-key wins (null clears, absent = unchanged) | Matches roadmap contract note — mobile client sends only changed fields | Plan |
| Ownership violation response | 404 (not 403) | Avoids leaking resource existence — standard secure API pattern | Plan |
| GET/PATCH response shape | 9 fields (8 from POST + `updated_at`) | Client needs freshness signal after PATCH without a second round trip | Plan |
| PATCH success response | 200 with full resource | Avoids extra GET after edit on mobile connections | Plan |
| condition validation | Enum: new, like new, good, fair, worn, null | Same constraint as classifier service — free text would break Vinted export | Plan |
| Test count | 5 tests | GET/PATCH happy paths, auth, 404, ownership | Plan |

## Scope

**In scope:** `GET /api/garments/{id}`, `PATCH /api/garments/{id}`, 5 feature tests

**Out of scope:** Photo replacement via PATCH, bulk PATCH, changing POST response shape, wardrobe list (S-04), deletion (S-05)

## Architecture / Approach

Two new methods on `GarmentController` (`show`, `update`) plus a private `garmentResource()` helper that both share. Route model binding + SoftDeletes scope handles not-found automatically; an explicit `abort(404)` enforces ownership. `$garment->update($request->validated())` with `sometimes|nullable` rules delivers present-key PATCH semantics natively.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Controller + routes | `show()`, `update()`, `garmentResource()`, 2 routes | None — follows existing GarmentController pattern exactly |
| 2. Feature tests | 5 test cases in `GarmentListingCardTest.php` | Garment factory may not exist — direct model instantiation used instead |

**Prerequisites:** S-02 done (garments table + GarmentController exist) ✅
**Estimated effort:** ~1 session, 2 phases

## Open Risks & Assumptions

- No Garment factory exists — tests create garments directly via model instantiation (verified safe from S-02 test pattern)
- `photo_url` will be empty string in tests (no media attached) — acceptable for listing-card tests

## Success Criteria (Summary)

- `composer test` passes with 30 green tests (25 existing + 5 new)
- PATCH with `{"brand": null}` clears brand; PATCH without `brand` key leaves it unchanged
- Another user's garment returns 404 (not 403)
