# Public OpenAPI Docs on GitHub Pages — Implementation Plan

## Overview

Auto-generate an OpenAPI 3.1 spec from the typed Laravel controllers + FormRequest
validation rules using `dedoc/scramble` (no annotations), then publish a static
GitHub Pages site that serves both a human-facing Swagger UI and the raw
`openapi.json` for the mobile-app agent. Publishing runs in CI on every successful
prod deploy, so the contract never drifts from shipped code.

## Current State Analysis

- **No docs tooling exists.** `composer.json` `require-dev` has no OpenAPI generator.
- **One CI workflow:** `.github/workflows/deploy.yml` with three jobs — `test`
  (runs on PR + push), `deploy-dev` (PR only), `deploy-prod` (push to `main` only,
  `needs: test`). No docs job, no GitHub Pages wiring.
- **Routes** (`routes/api.php`): typed controllers under the `api` prefix —
  `AuthController`, `SupabaseController`, `UserController`, `GarmentController` —
  plus util routes `/up`, `/ping`. Sanctum bearer auth (`auth:sanctum` +
  `CheckForAnyAbility:access`). Scramble reads these signatures + FormRequests
  directly; no doc-blocks needed.
- **Stack:** Laravel 12 / PHP 8.2 — supported by current Scramble.
- **Deploy:** Bref on AWS Lambda, region `eu-central-1`, `httpApi: '*'`. The prod
  API Gateway base URL is auto-generated at deploy and is **not known statically**
  (the `servers`-block open item from `change.md`).
- **Repo `Artigno/MatchMeApi` is public** → GitHub Pages is free, no external
  account, no prod-API exposure.

### Key Discoveries:

- `deploy.yml:63-97` — `deploy-prod` job is the natural `needs:` anchor; docs must
  publish only after the live API they document is deployed.
- `deploy.yml:89` — prod job installs with `--no-dev`; Scramble is `require-dev`,
  so the docs job must run its own `composer install` **with** dev deps.
- `routes/api.php:10,23` — `/up` and `/ping` are closures under the `api` prefix;
  they will appear in the spec (accepted — "all routes" decision).
- Scramble publishes `config/scramble.php` via `vendor:publish --tag=scramble-config`
  and exports a spec with `php artisan scramble:export`.

## Desired End State

After merge to `main` and one manual Pages enablement:

- `https://artigno.github.io/MatchMeApi/` serves Swagger UI rendering the live spec.
- `https://artigno.github.io/MatchMeApi/openapi.json` serves the raw OpenAPI 3.1
  document for the mobile agent.
- Every push to `main` that deploys prod successfully refreshes both artifacts.
- The spec's `servers` block carries the prod API Gateway URL (via CI variable),
  with a safe placeholder when the variable is unset.

Verify: after the first post-merge run, both URLs load; `openapi.json` validates as
OpenAPI 3.1 and lists the garment + auth endpoints; Swagger UI "Try it out" targets
the configured server URL.

## What We're NOT Doing

- **No bidirectional sync tooling.** API→mobile is automatic; mobile→API is a
  process agreement (the spec is the handshake), explicitly out of scope.
- **No annotations / doc-blocks** added to controllers — Scramble infers from types.
- **No spec build on PRs** — generation runs only in the main publish job.
- **No route filtering** — `/up`, `/ping` stay in the spec.
- **No dev-stage docs site** — only prod is published.
- **No custom domain, auth, or access control** on the Pages site (repo is public).

## Implementation Approach

Three incremental phases: (1) add and configure the generator so a valid spec can
be produced locally; (2) commit the static Swagger UI shell that consumes the spec;
(3) wire a CI job that exports the spec, assembles the site, and deploys to Pages
after `deploy-prod`. The only human step is a one-click Pages enablement in repo
settings (the agent cannot do it).

## Critical Implementation Details

- **Dev-deps in the docs job.** The existing prod job uses `--no-dev`; the new
  `publish-docs` job must `composer install` *with* dev deps or `scramble:export`
  will not be available. It runs as a separate job, so this does not affect deploy.
- **Pages permissions are job-scoped.** The `publish-docs` job needs
  `pages: write` + `id-token: write`; do not widen these at workflow level (keeps
  the deploy jobs' token minimal). Add a `concurrency` group for `pages` to avoid
  overlapping deployments.
- **`scramble:export` needs a bootable app.** It boots Laravel to enumerate routes,
  so the job must `cp .env.example .env && php artisan key:generate` before export
  (mirrors the `test` job at `deploy.yml:24`).

## Phase 1: Install + configure Scramble

### Overview

Add the generator, publish its config, and set spec identity + server URL so a valid
`openapi.json` can be produced locally and in CI.

### Changes Required:

#### 1. Composer dependency

**File**: `composer.json` (+ `composer.lock`)

**Intent**: Add `dedoc/scramble` as a dev dependency so the spec generator and its
artisan commands are available in dev and in the docs CI job.

**Contract**: `composer require --dev dedoc/scramble`. Adds `dedoc/scramble` under
`require-dev`; updates `composer.lock`.

#### 2. Scramble config

**File**: `config/scramble.php` (new — via `php artisan vendor:publish --tag=scramble-config`)

**Intent**: Set the document identity (title, version) and a `servers` block sourced
from an env var with a placeholder fallback, so the published spec points at the
prod API Gateway URL when CI supplies it.

**Contract**: `api_path` stays `'api'` (default). `info.version` set (e.g. `'0.1.0'`).
`servers` keyed name→URL, reading `env('SCRAMBLE_SERVER_URL')` with a placeholder
default. Sketch (servers shape is the load-bearing part):

```php
'servers' => [
    'Production' => env('SCRAMBLE_SERVER_URL', 'https://api.example.invalid'),
],
```

#### 3. Env example

**File**: `.env.example`

**Intent**: Document the new `SCRAMBLE_SERVER_URL` knob so the placeholder source is
discoverable.

**Contract**: Append `SCRAMBLE_SERVER_URL=` with a short comment naming it as the
spec `servers` URL.

### Success Criteria:

#### Automated Verification:

- Dependency installs: `composer install` exits 0
- Config file exists: `config/scramble.php` present
- Spec generates: `php artisan scramble:export --path=openapi.json` exits 0 and
  writes `openapi.json`
- Spec is OpenAPI 3.1 and lists garment routes (e.g.
  `grep -q '"openapi": "3.1' openapi.json` and grep for `/api/garments`)
- Pint clean: `./vendor/bin/pint --test`

#### Manual Verification:

- Generated `openapi.json` shows the auth + garment endpoints with expected request
  fields derived from FormRequests
- `servers` block reflects `SCRAMBLE_SERVER_URL` when set in the local env

**Implementation Note**: After completing this phase and all automated verification
passes, pause for manual confirmation before proceeding.

---

## Phase 2: Static Swagger UI page

### Overview

Commit a static HTML shell that loads Swagger UI from CDN and renders the sibling
`openapi.json`. This is the human-facing page; CI will assemble it next to the spec.

### Changes Required:

#### 1. Swagger UI shell

**File**: `.github/pages/index.html` (new)

**Intent**: Provide a self-contained Swagger UI page that fetches `./openapi.json`
from the same Pages directory, so the human docs render without a build step.

**Contract**: Static HTML loading Swagger UI dist (CSS + `swagger-ui-bundle.js`)
from a pinned CDN version; `SwaggerUIBundle({ url: './openapi.json', dom_id: '#swagger-ui' })`.
Page `<title>` names the API.

### Success Criteria:

#### Automated Verification:

- File exists: `.github/pages/index.html` present
- References the spec by relative path: `grep -q "./openapi.json" .github/pages/index.html`
- Pins a CDN version (no bare `@latest`): `grep -qE "swagger-ui-dist@[0-9]" .github/pages/index.html`

#### Manual Verification:

- Serving `.github/pages/` with a Phase-1 `openapi.json` copied alongside renders
  the endpoint list in a browser (e.g. `php -S localhost:8080 -t .github/pages`)
- No console errors; "Try it out" shows the configured server URL

**Implementation Note**: After completing this phase and all automated verification
passes, pause for manual confirmation before proceeding.

---

## Phase 3: CI publish job + Pages enablement

### Overview

Add a `publish-docs` job to `deploy.yml` that, after a successful prod deploy,
exports the spec, assembles the site (`index.html` + `openapi.json`), and deploys to
GitHub Pages. Enable Pages once by hand.

### Changes Required:

#### 1. Publish-docs job

**File**: `.github/workflows/deploy.yml`

**Intent**: After `deploy-prod`, generate the spec with dev deps, assemble a `site/`
directory from the committed shell plus the exported spec, and deploy it to Pages
using the official Pages actions. Source the server URL from a repo variable.

**Contract**: New job `publish-docs` —
- `needs: deploy-prod`, same guard as prod (`github.ref == 'refs/heads/main' &&
  github.event_name == 'push'`).
- Job-level `permissions: { pages: write, id-token: write, contents: read }` and an
  `environment: github-pages`.
- Steps: checkout → setup-php 8.2 → `composer install` (WITH dev) →
  `cp .env.example .env && php artisan key:generate` →
  `php artisan scramble:export --path=site/openapi.json` →
  copy `.github/pages/index.html` to `site/index.html` →
  `actions/configure-pages` → `actions/upload-pages-artifact` (path `site`) →
  `actions/deploy-pages`.
- `SCRAMBLE_SERVER_URL: ${{ vars.SCRAMBLE_SERVER_URL }}` on the export step.
- Add a workflow-level `concurrency` group for Pages
  (`group: pages`, `cancel-in-progress: false`).

#### 2. Repo variable (manual, document in plan)

**Setting**: GitHub repo → Settings → Secrets and variables → Actions → Variables →
`SCRAMBLE_SERVER_URL` = prod API Gateway base URL (e.g.
`https://<id>.execute-api.eu-central-1.amazonaws.com`). Until set, the spec uses the
placeholder — non-blocking.

### Success Criteria:

#### Automated Verification:

- Workflow YAML is valid: `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/deploy.yml'))"`
- `publish-docs` job present with `needs: deploy-prod`:
  `grep -q "needs: deploy-prod" .github/workflows/deploy.yml`
- Pages actions referenced:
  `grep -q "actions/deploy-pages" .github/workflows/deploy.yml`
- Job grants Pages permissions:
  `grep -q "pages: write" .github/workflows/deploy.yml`

#### Manual Verification:

- **MANUAL GATE (user):** repo Settings → Pages → Source = "GitHub Actions"
  (one click; the agent cannot do it).
- (Optional) set repo variable `SCRAMBLE_SERVER_URL` to the prod API Gateway URL.
- After merge to `main`, the `publish-docs` job succeeds in Actions.
- `https://artigno.github.io/MatchMeApi/` loads Swagger UI.
- `https://artigno.github.io/MatchMeApi/openapi.json` returns the OpenAPI 3.1 doc.

**Implementation Note**: This phase ends with a human gate (Pages enablement) and a
post-merge verification. Confirm the live URLs before closing the change.

---

## Testing Strategy

### Unit Tests:

- None — this change adds no application code paths. Generation is verified by
  spec-output assertions, not unit tests.

### Integration Tests:

- Spec generation is the integration check: `scramble:export` must boot the app and
  enumerate all `api` routes without error (covered in Phase 1 automated criteria).

### Manual Testing Steps:

1. Run `php artisan scramble:export --path=openapi.json`; open the JSON and confirm
   garment + auth endpoints, request fields from FormRequests.
2. Copy the spec next to `.github/pages/index.html`, serve the dir, confirm Swagger
   UI renders.
3. After merge + Pages enablement, load both public URLs.

## Performance Considerations

Negligible. Docs run is an extra CI job on main pushes only; the Pages site is
static (CDN-served Swagger UI + one JSON file).

## Migration Notes

None — additive. No data, no schema, no runtime API change. Rollback = remove the
`publish-docs` job and the dev dependency.

## References

- Change identity: `context/changes/api-docs/change.md`
- Existing CI to mirror: `.github/workflows/deploy.yml:63-97`
- Routes documented: `routes/api.php`
- Lesson (null over plausible-but-wrong): `context/foundation/lessons.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Install + configure Scramble

#### Automated

- [x] 1.1 Dependency installs: `composer install` exits 0 — d373266
- [x] 1.2 Config file exists: `config/scramble.php` present — d373266
- [x] 1.3 Spec generates: `scramble:export --path=openapi.json` exits 0 and writes file — d373266
- [x] 1.4 Spec is OpenAPI 3.1 and lists garment routes — d373266
- [x] 1.5 Pint clean: `./vendor/bin/pint --test` — d373266

#### Manual

- [x] 1.6 Generated spec shows auth + garment endpoints with FormRequest fields — d373266
- [x] 1.7 `servers` block reflects `SCRAMBLE_SERVER_URL` when set locally — d373266

### Phase 2: Static Swagger UI page

#### Automated

- [x] 2.1 File exists: `.github/pages/index.html` present — 77aeb54
- [x] 2.2 References spec by relative path `./openapi.json` — 77aeb54
- [x] 2.3 Pins a CDN version (no bare `@latest`) — 77aeb54

#### Manual

- [x] 2.4 Serving the dir with a local spec renders the endpoint list, no console errors — 77aeb54
- [x] 2.5 "Try it out" shows the configured server URL — 77aeb54

### Phase 3: CI publish job + Pages enablement

#### Automated

- [x] 3.1 Workflow YAML valid (yaml.safe_load) — 0274bb9
- [x] 3.2 `publish-docs` job present with `needs: deploy-prod` — 0274bb9
- [x] 3.3 `actions/deploy-pages` referenced — 0274bb9
- [x] 3.4 Job grants `pages: write` — 0274bb9

#### Manual

- [ ] 3.5 MANUAL GATE: repo Settings → Pages → Source = GitHub Actions
- [ ] 3.6 (Optional) repo variable `SCRAMBLE_SERVER_URL` set to prod API Gateway URL
- [ ] 3.7 `publish-docs` job succeeds after merge to main
- [ ] 3.8 `https://artigno.github.io/MatchMeApi/` loads Swagger UI
- [ ] 3.9 `https://artigno.github.io/MatchMeApi/openapi.json` returns OpenAPI 3.1 doc
