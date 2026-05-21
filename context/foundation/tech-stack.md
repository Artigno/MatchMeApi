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

## Why this stack

MirrorMatch's backend API is a solo-built, after-hours Laravel (PHP) project serving the Expo mobile client over HTTPS. Laravel is the vetted default for the `(api, php)` cell: Eloquent ORM, database migrations, Sanctum for API token auth (FR-007), and a mature HTTP client for external AI inference calls (FR-002). All four agent-friendly criteria pass (convention-based, popular in PHP training data, well-documented). Bootstrapper confidence is `verified`. Deployment targets AWS Lambda via Bref.sh or Laravel Vapor — this requires stateless session handling (Redis or DynamoDB), S3 for garment photo storage, and SQS for any queued jobs; wire these early to avoid refactoring later. GitHub Actions handles CI/CD with auto-deploy on merge to main.
