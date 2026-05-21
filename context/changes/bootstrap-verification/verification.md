---
bootstrapped_at: 2026-05-21T21:53:00Z
starter_id: laravel
starter_name: Laravel
project_name: mirror-match
language_family: php
package_manager: composer
cwd_strategy: subdir-then-move
bootstrapper_confidence: verified
phase_3_status: ok
audit_command: "null"
---

## Hand-off

```yaml
---
starter_id: laravel
package_manager: composer
project_name: mirror-match
hints:
  language_family: php
  team_size: solo
  deployment_target: aws-lambda
  ci_provider: github-actions
  ci_default_flow: auto-deploy-on-merge
  bootstrapper_confidence: verified
  path_taken: standard
  quality_override: false
  self_check_answers: null
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: true
  has_background_jobs: false
---
```

### Why this stack

MirrorMatch's backend API is a solo-built, after-hours Laravel (PHP) project serving the Expo mobile client over HTTPS. Laravel is the vetted default for the `(api, php)` cell: Eloquent ORM, database migrations, Sanctum for API token auth (FR-007), and a mature HTTP client for external AI inference calls (FR-002). All four agent-friendly criteria pass (convention-based, popular in PHP training data, well-documented). Bootstrapper confidence is `verified`. Deployment targets AWS Lambda via Bref.sh or Laravel Vapor — this requires stateless session handling (Redis or DynamoDB), S3 for garment photo storage, and SQS for any queued jobs; wire these early to avoid refactoring later. GitHub Actions handles CI/CD with auto-deploy on merge to main.

## Pre-scaffold verification

| Signal      | Value                                       | Severity | Notes                                                        |
| ----------- | ------------------------------------------- | -------- | ------------------------------------------------------------ |
| npm package | not run                                     | —        | PHP starter; cmd_template uses composer, not npm             |
| GitHub repo | not run                                     | —        | docs_url is https://laravel.com/docs — not a GitHub URL      |

No recency signal available for this starter. Laravel is a mature, actively maintained project (v12.12.2 installed; v13.7.0 available but requires PHP 8.3 — your PHP 8.2 triggered the v12 fallback). Proceeding.

**PHP version note**: Laravel 13 requires PHP ≥ 8.3. Current environment: PHP 8.2.30. Consider upgrading PHP to unlock v13.

## Scaffold log

**Resolved invocation**: `composer create-project laravel/laravel .bootstrap-scaffold --no-interaction --prefer-dist`
**Strategy**: scaffold into temp directory then move files up
**Exit code**: 0
**Files moved**: 22 (5 hidden files + 7 root files + 10 directories)
**Conflicts (.scaffold siblings)**: none
**.gitignore handling**: moved silently (no .gitignore existed in cwd)
**.bootstrap-scaffold cleanup**: deleted

**Version installed**: laravel/laravel v12.12.2 (latest v13.7.0 requires PHP 8.3; your PHP 8.2 triggered automatic fallback to v12)

## Post-scaffold audit

**Tool**: skipped — no built-in audit tool for php
**Recommended external tool**: `composer audit` (built into Composer 2.4+; run from project root after `composer install`). Composer 2.8.10 is already installed — run `composer audit` manually now. Also consider `roave/security-advisories` as a dev dependency that blocks known-vulnerable packages at `composer update` time.

> Note: During scaffold, Composer itself ran a security check and reported: "No security vulnerability advisories found." This is the equivalent of `composer audit` and is a clean result for the initial dependency set.

## Hints recorded but not acted on

| Hint                    | Value                  |
| ----------------------- | ---------------------- |
| bootstrapper_confidence | verified               |
| quality_override        | false                  |
| path_taken              | standard               |
| self_check_answers      | null                   |
| team_size               | solo                   |
| deployment_target       | aws-lambda             |
| ci_provider             | github-actions         |
| ci_default_flow         | auto-deploy-on-merge   |
| has_auth                | true                   |
| has_payments            | false                  |
| has_realtime            | false                  |
| has_ai                  | true                   |
| has_background_jobs     | false                  |

## Next steps

Next: a future skill will set up agent context (CLAUDE.md, AGENTS.md). For now, your project is scaffolded and verified — happy hacking.

Useful manual steps in the meantime:
- `git init` (if you have not already) to start your own repo history.
- `composer audit` — run the security audit manually (the ecosystem has no automated audit in bootstrapper v1, but Composer 2.8 supports it natively).
- **AWS Lambda wiring** (non-standard deployment target): install Bref.sh (`composer require bref/bref`) and configure `serverless.yml`, or set up Laravel Vapor. This must happen before any Lambda deploy. Default database is SQLite (`.env` is already configured); swap to RDS/Aurora for Lambda.
- Review any `.scaffold` siblings — none were created this run.
