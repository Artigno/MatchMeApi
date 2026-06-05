# CI/CD Pipeline Implementation Plan

## Overview

Add a GitHub Actions workflow that runs the test suite on every PR push, deploys to `dev` on PR, and deploys to `prod` on merge to `main`. Both deploy jobs require passing tests and automatically run migrations after deployment.

## Current State Analysis

- `serverless.yml` fully configured: SSM secrets, `pgsql`, `eu-central-1`, `php-82-fpm` ✅
- `package.json` has `serverless@^4.36.1` + `serverless-lift` in devDependencies ✅
- `package-lock.json` exists on disk but **untracked** — `npm ci` in CI will fail without it committed
- `.github/workflows/` directory does not exist
- Tests use in-memory SQLite via `phpunit.xml` (`DB_DATABASE=testing`, `DB_URL=""`) — no external DB needed in CI
- `composer test` = `php artisan config:clear && php artisan test`

## Desired End State

After this plan:
- `package-lock.json` tracked in git (enables `npm ci` in CI)
- `.github/workflows/deploy.yml` present with three jobs:
  - `test` — runs on every PR push + every push to `main`; PHP 8.2, Composer deps cached, `composer test`
  - `deploy-dev` — runs after `test` on PR events; deploys to `dev` stage + runs migrations
  - `deploy-prod` — runs after `test` on push to `main`; deploys to `prod` stage + runs migrations
- GitHub repo secrets `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY` wired (manual step)

## What We're NOT Doing

- No Serverless Framework global install — `npx serverless` only
- No preview URLs per-PR (dev is a shared long-lived stage)
- No deployment to a `staging` stage (out of scope for this change)
- No Slack/email notifications on deploy
- No manual approval gate for prod in the workflow (prod deploy is gated by tests passing + merge to main)
- No separate `SUPABASE_JWT_SECRET` secret in CI — tests use `FakeSupabaseJwtVerifier` and don't need a real value

---

## Phase 1: Track package-lock.json

### Overview

`package-lock.json` exists on disk but is untracked. `npm ci` requires a committed lockfile; without this Phase 2's workflow will fail on the `npm ci` step in CI.

### Changes Required:

#### 1. Stage and commit `package-lock.json`

**File**: `package-lock.json` (existing, currently untracked)

**Intent**: Lock npm dependencies so CI installs reproducibly.

**Contract**: File is committed to the repository. `node_modules/` remains in `.gitignore` (already the case — verified).

### Success Criteria:

#### Automated Verification:

- `git ls-files package-lock.json` outputs `package-lock.json`
- `cat .gitignore | grep node_modules` confirms `node_modules` is still excluded

#### Manual Verification:

- `git log --oneline | head -3` shows a commit including `package-lock.json`

---

## Phase 2: Create GitHub Actions workflow

### Overview

Create `.github/workflows/deploy.yml` with the three-job bilingual CI/CD pipeline. PHP + Node.js both present; composer and npm dependencies cached.

### Changes Required:

#### 1. Create `.github/workflows/deploy.yml`

**File**: `.github/workflows/deploy.yml` (new)

**Intent**: Automate test + deploy lifecycle: PR → test + deploy-dev; merge to main → test + deploy-prod.

**Contract**:

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
      - name: Cache Composer
        uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
          restore-keys: ${{ runner.os }}-composer-
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
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
      - name: Cache Composer
        uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
          restore-keys: ${{ runner.os }}-composer-
      - name: Cache npm
        uses: actions/cache@v4
        with:
          path: node_modules
          key: ${{ runner.os }}-npm-${{ hashFiles('package-lock.json') }}
          restore-keys: ${{ runner.os }}-npm-
      - run: composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
      - run: npm ci
      - uses: aws-actions/configure-aws-credentials@v4
        with:
          aws-access-key-id: ${{ secrets.AWS_ACCESS_KEY_ID }}
          aws-secret-access-key: ${{ secrets.AWS_SECRET_ACCESS_KEY }}
          aws-region: eu-central-1
      - run: npx serverless deploy --stage dev
      - run: npx serverless bref:cli --stage dev --args="migrate --force"

  deploy-prod:
    needs: test
    if: github.ref == 'refs/heads/main' && github.event_name == 'push'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
      - name: Cache Composer
        uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
          restore-keys: ${{ runner.os }}-composer-
      - name: Cache npm
        uses: actions/cache@v4
        with:
          path: node_modules
          key: ${{ runner.os }}-npm-${{ hashFiles('package-lock.json') }}
          restore-keys: ${{ runner.os }}-npm-
      - run: composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
      - run: npm ci
      - uses: aws-actions/configure-aws-credentials@v4
        with:
          aws-access-key-id: ${{ secrets.AWS_ACCESS_KEY_ID }}
          aws-secret-access-key: ${{ secrets.AWS_SECRET_ACCESS_KEY }}
          aws-region: eu-central-1
      - run: npx serverless deploy --stage prod
      - run: npx serverless bref:cli --stage prod --args="migrate --force"
```

### Success Criteria:

#### Automated Verification:

- `test -f .github/workflows/deploy.yml` — file exists
- `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"` — valid YAML (or `npx js-yaml .github/workflows/deploy.yml`)
- Workflow defines three jobs: `test`, `deploy-dev`, `deploy-prod`
- `deploy-dev` has `needs: test` and `if: github.event_name == 'pull_request'`
- `deploy-prod` has `needs: test` and `if: github.ref == 'refs/heads/main' && github.event_name == 'push'`
- Both deploy jobs end with a `serverless bref:cli --args="migrate --force"` step

#### Manual Verification:

- Open the workflow file and confirm all three jobs are structurally correct before pushing

---

## Phase 3: Wire GitHub Actions Secrets

### Overview

GitHub Actions needs AWS credentials as repository secrets before the deploy jobs can run. This phase is entirely manual — no code changes. The CI IAM user should already exist from `deployment-plan.md` Phase 1.4 (`mirror-match-ci`).

### Changes Required:

#### 1. Add secrets to GitHub repository

**Location**: GitHub repo → Settings → Secrets and variables → Actions → New repository secret

**Intent**: Provide deploy jobs with scoped AWS credentials without hardcoding them in the workflow.

**Contract**:

| Secret name | Value source |
|---|---|
| `AWS_ACCESS_KEY_ID` | Access key from IAM user `mirror-match-ci` |
| `AWS_SECRET_ACCESS_KEY` | Secret key from IAM user `mirror-match-ci` |

If `mirror-match-ci` does not exist yet, create it first following `deployment-plan.md` Phase 1.4. The IAM user needs the same scoped permissions as the deploy user (CloudFormation, Lambda, S3 `mirror-match-*`, IAM role on `mirror-match-*`, SSM GetParameter on `/mirror-match/*`, APIGateway).

### Success Criteria:

#### Automated Verification:

- (none — secrets are not readable after being set; verification is behavioural)

#### Manual Verification:

- Open a test PR → confirm `test` job and `deploy-dev` job both appear in GitHub Actions tab
- `deploy-dev` job passes (or fails for a known reason unrelated to credentials)
- Merge test PR to `main` → confirm `deploy-prod` job triggers and passes

---

## Testing Strategy

### Unit Tests:

None — no application logic; workflow file is declarative YAML.

### Integration Tests (CI-level):

- The workflow itself is the integration test: push to a PR branch → GitHub Actions runs `test` + `deploy-dev` → green = plan delivered.

### Manual Testing Steps:

1. Create a test branch and open a PR
2. Confirm `test` job runs and passes
3. Confirm `deploy-dev` job runs after `test` and deploys to `dev` stage
4. Confirm `serverless bref:cli --stage dev --args="migrate --force"` step runs without error
5. Merge the PR → confirm `deploy-prod` job triggers on push to `main`
6. Confirm `deploy-prod` passes and migrations ran on prod

## References

- Deployment context: `context/changes/deployment/deployment-plan.md` Phase 5
- Infrastructure: `context/foundation/infrastructure.md`
- Serverless config: `serverless.yml`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Track package-lock.json

#### Automated

- [x] 1.1 `git ls-files package-lock.json` outputs `package-lock.json` — accd8b7
- [x] 1.2 `cat .gitignore | grep node_modules` confirms `node_modules` excluded — accd8b7

#### Manual

- [x] 1.3 `git log --oneline | head -3` shows commit including `package-lock.json` — accd8b7

### Phase 2: Create GitHub Actions workflow

#### Automated

- [x] 2.1 `.github/workflows/deploy.yml` exists — 9a99e89
- [x] 2.2 Valid YAML — `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"` — 9a99e89
- [x] 2.3 Workflow defines `test`, `deploy-dev`, `deploy-prod` jobs — 9a99e89
- [x] 2.4 `deploy-dev` has `needs: test` + `if: github.event_name == 'pull_request'` — 9a99e89
- [x] 2.5 `deploy-prod` has `needs: test` + `if: github.ref == 'refs/heads/main' && github.event_name == 'push'` — 9a99e89
- [x] 2.6 Both deploy jobs end with `serverless bref:cli --args="migrate --force"` step — 9a99e89

#### Manual

- [x] 2.7 Workflow file reviewed — all three jobs structurally correct — 9a99e89

### Phase 3: Wire GitHub Actions Secrets

#### Automated

- (none)

#### Manual

- [x] 3.1 `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` added to GitHub repo secrets
- [x] 3.2 Test PR → `test` + `deploy-dev` jobs both appear and pass
- [x] 3.3 Merge to `main` → `deploy-prod` triggers and passes
