---
change_id: api-docs
title: Public OpenAPI docs on GitHub Pages, auto-published from CI
status: archived
created: 2026-06-04
updated: 2026-06-05
archived_at: 2026-06-05T17:23:43Z
---

## Notes

Generate API documentation and publish to a public URL so the mobile-app agent
(separate Expo repo) and humans can both consume the live API contract.

Locked decisions (from discovery 2026-06-04):

- **Generator:** `dedoc/scramble` (require-dev) — OpenAPI 3.1 auto-generated from
  typed controllers + FormRequest validation rules. No annotations. Best fit for
  this typed Laravel codebase.
- **Publish target:** GitHub Pages. Repo `Artigno/MatchMeApi` is public → free,
  no external account, no prod-API exposure.
  - Public URL: `https://artigno.github.io/MatchMeApi/`
  - Raw spec for agents: `https://artigno.github.io/MatchMeApi/openapi.json`
  - Serves both: Swagger UI (human) + raw `openapi.json` (mobile agent).
- **Sync:** CI auto-publish on merge to `main`. Docs never drift from shipped code.
  API → mobile direction is automatic. True bidirectional ("vice versa") is a
  process agreement (spec is the handshake), NOT tooling — out of scope here.

Work involved:
1. `composer require --dev dedoc/scramble`
2. `config/scramble.php` — title, version, `api` path prefix, dev/prod server URLs.
3. New `.github/workflows/docs.yml` — on push to `main`: install → `php artisan
   scramble:export` → build static Swagger UI + spec → deploy to Pages.
4. MANUAL GATE (user): enable GitHub Pages in repo settings (source = GitHub
   Actions). One click; agent cannot do it.

Open items for /10x-plan:
- Dev/prod server URLs: auto-generated AWS API Gateway URLs from serverless — need
  actual values (or a placeholder/var) for the spec `servers` block.
- Whether docs.yml is a standalone workflow or a job appended to deploy.yml.
