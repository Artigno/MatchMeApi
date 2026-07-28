# Public OpenAPI Docs on GitHub Pages — Plan Brief

> Full plan: `context/changes/api-docs/plan.md`

## What & Why

Generate an OpenAPI 3.1 spec from the typed Laravel controllers + FormRequest rules
(via `dedoc/scramble`, no annotations) and publish a static GitHub Pages site with
Swagger UI for humans and a raw `openapi.json` for the mobile-app agent. CI republishes
on every prod deploy, so the contract never drifts from shipped code.

## Starting Point

No docs tooling. One CI workflow (`deploy.yml`: `test` → `deploy-dev` / `deploy-prod`).
Typed `api`-prefixed routes (auth + garments) under Sanctum. Bref/Lambda deploy where
the prod API Gateway URL is auto-generated and not known statically.

## Desired End State

`https://artigno.github.io/MatchMeApi/` serves Swagger UI; `.../openapi.json` serves
the raw OpenAPI 3.1 doc. Both refresh on each successful prod deploy. One-time manual
Pages enablement required.

## Key Decisions Made

| Decision        | Choice                                  | Why                                              | Source |
| --------------- | --------------------------------------- | ------------------------------------------------ | ------ |
| Generator       | `dedoc/scramble` (dev dep)              | OpenAPI 3.1 from types, no annotations           | Change |
| Publish target  | GitHub Pages (public repo)              | Free, no external account, no prod-API exposure  | Change |
| Workflow shape  | `publish-docs` job, `needs: deploy-prod`| Spec never published ahead of the live API       | Plan   |
| Server URL      | `SCRAMBLE_SERVER_URL` var → placeholder | No hardcoded URL; updatable when URL known       | Plan   |
| UI renderer     | Static Swagger UI from CDN              | Matches change.md; trivial static file           | Plan   |
| PR spec build   | None (main publish job only)            | Keep CI minimal                                  | Plan   |
| Route scope     | All `/api` routes                       | Complete contract for the mobile agent           | Plan   |

## Scope

**In scope:** Scramble install + config, static Swagger UI shell, CI publish job, manual Pages gate.

**Out of scope:** bidirectional sync tooling, controller annotations, PR spec builds, route filtering, dev-stage docs, custom domain/auth.

## Architecture / Approach

`scramble:export` boots Laravel, enumerates `api` routes → `openapi.json`. CI assembles
`site/` = committed `index.html` (Swagger UI CDN) + exported spec, deploys via official
Pages actions after `deploy-prod`. Server URL injected from a repo variable.

## Phases at a Glance

| Phase                       | Delivers                                   | Key risk                                         |
| --------------------------- | ------------------------------------------ | ------------------------------------------------ |
| 1. Install + configure      | Local `openapi.json` generation            | Scramble config/servers shape                    |
| 2. Static Swagger UI page   | Committed `.github/pages/index.html`       | CDN version pin / relative spec path             |
| 3. CI publish + Pages gate  | Live Pages site on prod deploy             | Dev-deps in job; Pages permissions; manual gate  |

**Prerequisites:** push access to `Artigno/MatchMeApi`; ability to enable Pages in repo settings.
**Estimated effort:** ~1 session across 3 phases.

## Open Risks & Assumptions

- Prod API Gateway URL unknown until set as `SCRAMBLE_SERVER_URL` var — spec uses placeholder until then (non-blocking).
- Manual Pages enablement (source = GitHub Actions) is required once; agent cannot do it.
- Util routes `/up`, `/ping` appear in the spec by design.

## Success Criteria (Summary)

- Both public URLs load (Swagger UI + raw spec) after merge.
- `openapi.json` validates as OpenAPI 3.1 and lists auth + garment endpoints.
- Docs refresh automatically on each prod deploy.
