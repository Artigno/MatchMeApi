---
id: classify-polish-output
status: implemented
created: 2026-06-17
updated: 2026-06-17
---

# Classify output in Polish

Localize the `POST /classify` listing card to Polish for the Polish-speaking mobile client.
All fields except `brand` are Polish — including the validated `condition` enum, so the
canonical `Garment::CONDITIONS` source is localized end-to-end.

## Decisions (from planning)

- Polish hardcoded in the system prompt (single locale; no Accept-Language).
- `color`, `description` → Polish free text.
- `condition` → canonical Polish enum `nowy / jak nowy / dobry / średni / znoszony`
  (validation at store + PATCH follows the constant).
- `category` → Polish (`góra / dół / buty / akcesorium / okrycie wierzchnie`); not
  enum-validated, so prompt + doc only.
- `brand` → verbatim.
- Normalization uses `mb_strtolower` (Polish casing).
- Stale dev rows with English `condition` left as-is (no backfill).

See `plan.md` / `plan-brief.md`.
