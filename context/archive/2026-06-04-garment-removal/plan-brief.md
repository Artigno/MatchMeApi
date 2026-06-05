# Garment Removal (S-05) — Plan Brief

> Full plan: `context/changes/garment-removal/plan.md`

## What & Why

Implement `DELETE /api/garments/{id}` (FR-005) so a user can remove a garment from their
wardrobe. Removal is a **hard delete** — the DB row and the S3 photo both go — recorded in
a `garment_deletions` audit snapshot, all inside one transaction.

## Starting Point

`GarmentController` has index/show/update/classify but no `destroy`; `routes/api.php:29`
has an S-05 placeholder. The `Garment` model already uses `SoftDeletes` + spatie media.
Prereqs F-01/F-02/S-01 all shipped. No audit infra yet.

## Desired End State

Owner `DELETE` → `204`, row + photo + media-row gone, audit row written. Other user → `404`,
nothing deleted. Unauth → `401`; unknown/already-deleted → `404`. S3 failure mid-delete →
`500`, transaction rolls back, nothing orphaned.

## Key Decisions Made

| Decision      | Choice                                   | Why                                              | Source |
| ------------- | ---------------------------------------- | ------------------------------------------------ | ------ |
| Delete mode   | Hard-delete (`forceDelete`) + S3 cleanup | No orphaned data/storage; truly gone             | Plan   |
| Atomicity     | `DB::transaction`, media-then-row, fail loud | Row + file live/die together; retry-able       | Plan   |
| Audit         | New `garment_deletions` table + snapshot | Forensic trail for an irreversible action        | Plan   |
| Restore       | None (MVP)                               | Out of scope for "remove"                        | Plan   |
| Response      | `204 No Content`                         | REST-conventional for DELETE                     | Plan   |
| Re-delete     | `404`                                    | Row gone / binding withoutTrashed; matches show/update | Plan |
| Tests         | 3 edge groups (happy+media+audit, owner-404, auth/unknown) | Locks the contract            | Plan   |

## Scope

**In scope:** audit table + model, DELETE route + `destroy`, transaction + S3 cleanup, feature tests.

**Out of scope:** restore endpoint, soft-delete semantics, SQS purge job, bulk delete, catalogue changes, audit-read endpoint.

## Architecture / Approach

`destroy` authorizes ownership (404 mirror), then `DB::transaction`: snapshot →
`GarmentDeletion::create` → `$garment->forceDelete()` (spatie's delete event purges the
S3 photo) → `204`. No catch — S3 failure propagates as 500 and rolls back.

## Phases at a Glance

| Phase                | What it delivers                          | Key risk                                       |
| -------------------- | ----------------------------------------- | ---------------------------------------------- |
| 1. Audit schema      | `garment_deletions` table + model         | Snapshot column shape / no FK on garment_id    |
| 2. DELETE endpoint   | Route + `destroy` (txn, audit, forceDelete, 204) | Using `delete()` not `forceDelete()` → orphaned S3 |
| 3. Feature tests     | `GarmentRemovalTest`, 3 edge groups       | Asserting media removal with faked storage     |

**Prerequisites:** F-01/F-02/S-01 done (they are).
**Estimated effort:** ~1 session across 3 phases.

## Open Risks & Assumptions

- `forceDelete()` is mandatory — `delete()` would soft-delete and leave the S3 photo (roadmap's orphaned-file risk).
- S3 delete is non-transactional: a file removed just before a throw can't roll back (rare, acknowledged).
- No async infra (SQS commented out) → S3 cleanup is synchronous.

## Success Criteria (Summary)

- Owner delete returns 204 with row + S3 photo gone and an audit row written.
- Cross-tenant delete is blocked (404, nothing removed).
- `composer test` green incl. new `GarmentRemovalTest`.
