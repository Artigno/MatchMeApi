# Deploy Plan: MirrorMatch API — AWS Lambda + Bref.sh

## Context

Deploying the Laravel 12 / PHP 8.2 MirrorMatch API to AWS Lambda using Bref.sh v3 (already installed). A `serverless.yml` exists but needs APP_KEY moved to SSM before first deploy. Database: Neon PostgreSQL (free tier). No `routes/api.php` and no GitHub Actions workflow exist yet.

**Critical files to create/modify:**
- `serverless.yml` — service name, region, runtime, SSM references for secrets
- `routes/api.php` — create with health-check route (smoke test target)
- `package.json` / `package-lock.json` — for serverless-lift npm dependency
- `.github/workflows/deploy.yml` — CI/CD pipeline

---

## Phase 1 — Prerequisites & AWS Setup

- [ ] **1.1 Verify AWS CLI identity**
  ```bash
  aws sts get-caller-identity
  ```
  Must return account ID + IAM principal. If missing: `aws configure`.

- [ ] **1.2 Install Serverless Framework v4**
  ```bash
  npm install --save-dev serverless@4
  ```
  `package.json` and `serverless-lift` already exist. Commit updated `package.json` and `package-lock.json`. Verify `node_modules/` is in `.gitignore`. Use `npx serverless` everywhere (no global install needed).

- [x] **1.3 Fix serverless.yml — four corrections** ✅ already applied

  | Field | Value |
  |---|---|
  | `service` | `mirror-match` |
  | `provider.region` | `eu-central-1` |
  | `functions.web.runtime` | `php-82-fpm` |
  | `functions.artisan.runtime` | `php-82-console` |

- [ ] **1.4 Create scoped IAM deploy role** (or user `mirror-match-deploy`)

  Minimum permissions:
  ```json
  {
    "Version": "2012-10-17",
    "Statement": [
      {"Effect": "Allow", "Action": ["lambda:*", "cloudformation:*"], "Resource": "*"},
      {"Effect": "Allow", "Action": ["s3:*"], "Resource": ["arn:aws:s3:::mirror-match-*"]},
      {"Effect": "Allow", "Action": [
        "iam:CreateRole","iam:DeleteRole","iam:AttachRolePolicy","iam:DetachRolePolicy",
        "iam:GetRole","iam:PassRole","iam:PutRolePolicy","iam:DeleteRolePolicy","iam:GetRolePolicy"
      ], "Resource": "arn:aws:iam::*:role/mirror-match-*"},
      {"Effect": "Allow", "Action": ["ssm:GetParameter","ssm:GetParameters"],
       "Resource": "arn:aws:ssm:eu-central-1:*:parameter/mirror-match/*"},
      {"Effect": "Allow", "Action": ["logs:CreateLogGroup","logs:DescribeLogGroups"], "Resource": "*"},
      {"Effect": "Allow", "Action": ["apigateway:*"], "Resource": "*"}
    ]
  }`
  ```

  **Phase 1 done when:** `npx serverless --version` shows 4.x; `serverless.yml` has `mirror-match` / `eu-central-1` / `php-82-fpm`.

---

## Phase 2 — Secrets & Environment

- [ ] **2.1 Set up Neon PostgreSQL database**

  1. Create account at neon.tech — Starter tier is free, no credit card required
  2. Create project `mirror-match`, region `aws-eu-central-1` (Frankfurt)
  3. Create two branches: `dev` and `main` (maps to prod)
  4. In Neon dashboard, enable the built-in **connection pooler** (PgBouncer, transaction mode) for each branch — required to avoid `max_connections` exhaustion under concurrent Lambda invocations
  5. For each branch, copy the pooled connection string (format: `postgresql://user:pass@ep-xxx.eu-central-1.aws.neon.tech/mirror-match?sslmode=require`)

  > Note: Neon uses standard PostgreSQL TLS — no custom CA cert env var needed. If the branch is inactive for >5 days, Neon auto-pauses; the first Lambda cold start will add 3–5s wake-up latency. The pooler mitigates connection exhaustion but not wake-up latency.

- [ ] **2.2 Store secrets in SSM Parameter Store** (eu-central-1)

  ```bash
  # APP_KEY — generate fresh (don't use the key hardcoded in serverless.yml)
  aws ssm put-parameter \
    --name "/mirror-match/dev/APP_KEY" \
    --value "$(php artisan key:generate --show)" \
    --type SecureString --region eu-central-1

  aws ssm put-parameter \
    --name "/mirror-match/prod/APP_KEY" \
    --value "$(php artisan key:generate --show)" \
    --type SecureString --region eu-central-1

  # DB_URL — full PostgreSQL connection string (from Neon dashboard, use pooled URL)
  aws ssm put-parameter \
    --name "/mirror-match/dev/DB_URL" \
    --value "postgresql://USER:PASS@ep-xxx.eu-central-1.aws.neon.tech/mirror-match?sslmode=require" \
    --type SecureString --region eu-central-1

  aws ssm put-parameter \
    --name "/mirror-match/prod/DB_URL" \
    --value "postgresql://USER:PASS@ep-xxx.eu-central-1.aws.neon.tech/mirror-match?sslmode=require" \
    --type SecureString --region eu-central-1
  ```

- [ ] **2.3 Rewrite `provider.environment` and `params` blocks in serverless.yml**

  The environment and params blocks are already applied in `serverless.yml`. Reference state:
  ```yaml
  provider:
      name: aws
      region: eu-central-1
      environment:
          APP_ENV: ${param:appEnv}
          APP_KEY: ${ssm:/mirror-match/${sls:stage}/APP_KEY}
          APP_DEBUG: ${param:debug}
          SESSION_DRIVER: cookie
          MAINTENANCE_MODE: ${param:maintenance, null}
          LOG_CHANNEL: stderr
          LOG_STDERR_FORMATTER: Bref\Monolog\CloudWatchFormatter
          DB_CONNECTION: pgsql
          DB_URL: ${ssm:/mirror-match/${sls:stage}/DB_URL}
  ```

  ```yaml
  params:
      default:
          appEnv: ${sls:stage}
          debug: 0
          maintenance: null
      prod:
          appEnv: production
      dev:
          debug: 1
  ```

  DB credentials are fully in SSM — no plaintext in params.

  **Phase 2 done when:** `aws ssm get-parameter --name "/mirror-match/dev/APP_KEY" --with-decryption --region eu-central-1` returns a value; `aws ssm get-parameter --name "/mirror-match/dev/DB_URL" --with-decryption --region eu-central-1` returns a value; no plaintext secrets in serverless.yml.

---

## Phase 3 — Staging Deploy

- [ ] **3.1 Create `routes/api.php`** with health-check route

  ```php
  <?php

  use Illuminate\Support\Facades\Route;

  Route::get('/up', fn () => response()->json(['status' => 'ok']));
  ```

- [ ] **3.2 Verify routes locally**
  ```bash
  php artisan route:list --path=api
  ```
  Expect one row: `GET api/up`.

- [ ] **3.3 Deploy to dev stage**
  ```bash
  serverless deploy --stage dev
  ```
  Note the endpoint URL from output.

- [ ] **3.4 Smoke test the health-check route**
  ```bash
  curl -s https://YOUR_DEV_ENDPOINT/up | jq .
  # Expected: {"status":"ok"}
  ```

- [ ] **3.5 Run dev migrations via artisan Lambda function**
  ```bash
  serverless bref:cli --stage dev --args="migrate --force"
  ```
  Check output for successful migration list or "Nothing to migrate" — not an error.

- [ ] **3.6 Verify CloudWatch logs**
  ```bash
  serverless logs -f web --stage dev --tail
  ```
  Trigger one more `curl /up`; confirm JSON log line appears — not a file-write error.

  **Phase 3 done when:** `/up` returns 200; CloudWatch shows structured JSON logs; no `/tmp` filesystem errors.

---

## Phase 4 — Production Deploy

> **HUMAN GATE — explicit confirmation required before proceeding.**
> This phase mutates the production Lambda and runs migrations against the production database.

- [ ] **4.1 Confirm prod SSM params exist**
  ```bash
  aws ssm get-parameter --name "/mirror-match/prod/APP_KEY" --with-decryption --region eu-central-1
  aws ssm get-parameter --name "/mirror-match/prod/DB_URL" --with-decryption --region eu-central-1
  ```

- [ ] **4.2 Deploy to prod**
  ```bash
  serverless deploy --stage prod
  ```

- [ ] **4.3 Run prod migrations**
  ```bash
  serverless bref:cli --stage prod --args="migrate --force"
  ```

- [ ] **4.4 Smoke test prod endpoint**
  ```bash
  curl -s https://YOUR_PROD_ENDPOINT/up | jq .
  # Expected: {"status":"ok"}
  ```

- [ ] **4.5 Verify prod CloudWatch logs**
  ```bash
  serverless logs -f web --stage prod
  ```
  Confirm JSON log lines; no errors.

  **Phase 4 done when:** prod `/up` returns 200; migrations ran cleanly.

---

## Phase 5 — GitHub Actions CI/CD

- [ ] **5.1 Create CI IAM user** `mirror-match-ci` with same permissions as Phase 1.4. Generate access key pair.

- [ ] **5.2 Add GitHub Secrets** (repo → Settings → Secrets → Actions)
  - `AWS_ACCESS_KEY_ID`
  - `AWS_SECRET_ACCESS_KEY`

- [ ] **5.3 Create `.github/workflows/deploy.yml`**

  ```yaml
  name: Deploy

  on:
    push:
      branches: [main]
    pull_request:
      branches: [main]

  jobs:
    test:
      runs-on: ubuntu-latest
      steps:
        - uses: actions/checkout@v4
        - uses: shivammathur/setup-php@v2
          with:
            php-version: '8.2'
        - run: composer install --no-interaction --prefer-dist
        - run: cp .env.example .env && php artisan key:generate
        - run: composer test

    deploy-dev:
      needs: test
      if: github.event_name == 'pull_request'
      runs-on: ubuntu-latest
      steps:
        - uses: actions/checkout@v4
        - uses: shivammathur/setup-php@v2
          with:
            php-version: '8.2'
        - run: composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
        - run: npm ci
        - uses: aws-actions/configure-aws-credentials@v4
          with:
            aws-access-key-id: ${{ secrets.AWS_ACCESS_KEY_ID }}
            aws-secret-access-key: ${{ secrets.AWS_SECRET_ACCESS_KEY }}
            aws-region: eu-central-1
        - run: npx serverless deploy --stage dev

    deploy-prod:
      needs: test
      if: github.ref == 'refs/heads/main' && github.event_name == 'push'
      runs-on: ubuntu-latest
      steps:
        - uses: actions/checkout@v4
        - uses: shivammathur/setup-php@v2
          with:
            php-version: '8.2'
        - run: composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
        - run: npm ci
        - uses: aws-actions/configure-aws-credentials@v4
          with:
            aws-access-key-id: ${{ secrets.AWS_ACCESS_KEY_ID }}
            aws-secret-access-key: ${{ secrets.AWS_SECRET_ACCESS_KEY }}
            aws-region: eu-central-1
        - run: npx serverless deploy --stage prod
  ```

  **Phase 5 done when:** PR triggers test + deploy-dev and both pass; merge to main triggers deploy-prod and passes.

---

## Lambda Edge-Case Mitigations

| Risk | Status | Action |
|---|---|---|
| Cold starts 500–1200ms | Accept at MVP | Monitor CloudWatch p95 latency; enable Provisioned Concurrency only if > 1s SLA |
| `/tmp` ephemeral filesystem | Bref auto-handles | Bref routes storage to `/tmp/storage`; never assume file persistence across invocations |
| S3 permission gap on file uploads | Deferred | Wire `FILESYSTEM_DISK=s3` + uncomment `constructs.storage` before FR-002 (photo uploads) ships |
| API Gateway 29s timeout for AI inference | Deferred | Move AI classification to async SQS flow before FR-002; `constructs.jobs` already scaffolded in serverless.yml |
| SQLite on Lambda | Natural gate | SQLite → migrations fail on Lambda; treated as a deploy-time block, not a CI gate |
| Log loss | Fixed ✅ | `LOG_CHANNEL=stderr` + `LOG_STDERR_FORMATTER` already in serverless.yml |
| Bref handler mismatch (SQS) | Deferred | When enabling queue workers: use `Bref\LaravelBridge\Queue\QueueHandler`, not the FPM handler |
| `.env` vs SSM confusion | Fixed by plan | All production secrets in SSM; `.env` excluded from deploy package |
