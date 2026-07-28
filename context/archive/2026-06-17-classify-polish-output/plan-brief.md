# Classify Output in Polish — Plan Brief

> Full plan: `context/changes/classify-polish-output/plan.md`

## What & Why

The mobile client is Polish; `POST /classify` returns English. Make the whole listing card
Polish — `color`, `description`, `category`, and `condition` — leaving only `brand` verbatim.
`condition` is a validated enum, so this localizes the canonical `Garment::CONDITIONS` source
end-to-end rather than just tweaking a prompt.

## Starting Point

`Garment::CONDITIONS` is English (`new…worn`) and drives validation at both write paths
(`store`, `PATCH`) plus classify normalization. An earlier prompt-only attempt localized
`color`/`description` but deliberately kept `condition`/`category` as English codes — the user
now wants those Polish too, which forces the enum change.

## Desired End State

`/classify` returns a fully Polish card (`brand` verbatim). `POST /garments` and `PATCH`
validate Polish `condition` values. `openapi.json` and `implemented-on-api.md` show the Polish
enum; the EN→PL client-mapping guidance is gone.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Scope of localization | All fields except `brand` | User wants a fully Polish card | Plan |
| `condition` canonical values | `nowy / jak nowy / dobry / średni / znoszony` | Natural PL, keeps 5-step scale | Plan |
| `category` localization | Polish (`góra/dół/buty/akcesorium/okrycie wierzchnie`) | Consistency; not enum-validated, so prompt+doc only | Plan |
| Casing in normalization | `mb_strtolower` | ASCII `strtolower` won't lowercase `Ś` in `Średni` | Plan |
| Stale EN rows | Ignore (dev-only) | No real data; string column, no migration | Plan |
| Locale mechanism | Hardcoded Polish | Single locale | Plan |

## Scope

**In scope:** flip `Garment::CONDITIONS` to Polish; `mb_strtolower` fix; rewrite
`systemPrompt()` (condition + category Polish); update ~7 test files; regenerate `openapi.json`;
update `implemented-on-api.md`.

**Out of scope:** multi-locale; field-name/shape changes; PL↔EN translation layer; backfilling
old rows; localizing `brand`; mobile-client edits.

## Architecture / Approach

`Garment::CONDITIONS` is the single lever — both `Rule::in(...)` validation sites follow it
with no edit. The prompt is rewritten for Polish output; normalization switches to
`mb_strtolower` so capitalized Polish values still match. `openapi.json` (Scramble-generated)
regenerates the enum from the validation rules — sync is "regenerate + verify", not hand-edit.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Localize enum + prompt | Polish condition enum, prompt, normalization, tests | A test or fixture still on an English literal → red suite |
| 2. Sync contract + doc | Regenerated openapi + updated client doc | Scramble enum not following the constant as expected |

**Prerequisites:** OpenRouter key + sample photo for the manual live check.
**Estimated effort:** ~1 session, 2 phases.

## Open Risks & Assumptions

- AI-output language can't be asserted deterministically (model not called in tests); the
  automated guard is prompt content, true language is a manual check.
- A capitalized Polish `condition` from the model relies on the `mb_strtolower` fix to match.
- Existing dev rows with English `condition` won't re-validate on edit (accepted).

## Success Criteria (Summary)

- `/classify` returns a fully Polish card; `brand` verbatim.
- `POST /garments` + `PATCH` validate Polish `condition`; out-of-set → `422`.
- Full suite green; `openapi.json` + `implemented-on-api.md` carry the Polish enum.
