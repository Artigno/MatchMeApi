---
project: MirrorMatch
researched_at: 2026-05-23
recommended_platform: AWS Lambda + Bref.sh (Serverless Framework v4)
runner_up: Railway
context_type: mvp
tech_stack:
  language: PHP 8.2
  framework: Laravel 12
  runtime: AWS Lambda (Bref.sh 3.x)
  database: PostgreSQL (Neon free tier)
---

## Recommendation

**Deploy on AWS Lambda + Bref.sh via Serverless Framework v4.**

The tech stack already names `aws-lambda` as the deployment target, `bref/bref` and `bref/laravel-bridge` are already installed, and a `serverless.yml` is scaffolded. Lambda's permanent free tier (1M requests/month + 400k GB-seconds compute) means compute cost is effectively $0 at MVP scale — up to ~800k monthly requests before any charge applies. The developer has AWS familiarity, eliminating platform learning curve. Serverless Framework v4 + Bref 3.0 (GA, PHP 8.2 on Amazon Linux 2023) is the community-standard path for Laravel on Lambda. Queue workers ("maybe queue" from interview) are handled natively via SQS-triggered Lambda with `Bref\LaravelBridge\Queue\QueueHandler` — no long-running process needed. AWS MCP Server reached GA in May 2026, covering 15,000+ AWS operations from Claude Code with scoped IAM credentials.

## Platform Comparison

| Platform | CLI-first | Managed/Serverless | Agent docs | Stable deploy API | MCP / Integration | Score |
|---|---|---|---|---|---|---|
| **AWS Lambda + Bref.sh** | Pass | Pass | Partial | Pass | Pass | **4.5/5** |
| Railway | Pass | Pass | Partial | Pass | Partial | 3.5/5 |
| Fly.io | Pass | Partial | Pass | Pass | Partial | 3.5/5 |
| Render | Partial | Partial | Pass | Partial | Pass | 3/5 |
| Cloudflare Workers | — | — | — | — | — | **Dropped** (no PHP runtime) |
| Netlify | — | — | — | — | — | **Dropped** (no PHP runtime) |
| Vercel | — | — | — | — | — | **Dropped** (no Laravel runtime) |

**Soft-weight adjustments applied:**
- Q2 minimize cost → Lambda wins ($0 compute at MVP scale vs Fly.io ~$5-10/mo, Railway ~$5/mo, Render ~$21-25/mo)
- Q3 AWS familiarity → Lambda tie-breaker bonus
- Q4 single region → neutral
- Q5 external services OK, free dev tiers required → Neon PostgreSQL (free, GA) covers the DB requirement

### Shortlisted Platforms

#### 1. AWS Lambda + Bref.sh (Recommended)

All operations via Serverless Framework CLI (`serverless deploy`, `serverless rollback`, `serverless logs --tail`, `serverless bref:cli --args="migrate --force"`). True serverless — OS, networking, scaling, TLS all managed by AWS. Bref docs are MDX on GitHub (`github.com/brefphp/bref/tree/master/docs`), fetchable by agents; no explicit `llms.txt` but raw MDX is sufficient. Deployment is deterministic (`serverless deploy --stage=prod`) backed by CloudFormation with versioned rollback. AWS MCP Server (GA May 2026) exposes Lambda, CloudWatch Logs, S3, SQS via structured tool calls from Claude Code. **Cost: ~$0/month compute** at MVP scale; only real costs are Neon PostgreSQL (free tier) and the external AI inference API.

#### 2. Railway

Railpack (Nixpacks successor, now active) auto-detects PHP 8.2 + Laravel from `composer.json`/`artisan`, sets document root, caches config/routes/views, runs migrations by default. Full CLI: `railway up`, `railway logs`, `railway redeploy` (rollback), `railway variable set`. Queue workers run as separate persistent services in the same Railway project. Hobby plan: $5/month flat with $5 of included compute credits — App + Worker + Postgres at low traffic fits within credits. Official MCP server is beta/work-in-progress as of May 2026. Loses to Lambda on cost at MVP volumes and on the developer's existing AWS familiarity.

#### 3. Fly.io

`flyctl` is the strongest CLI of the three: `fly deploy`, `fly logs`, `fly secrets set`, `fly scale count`. Laravel via `fideloper/fly-laravel` Dockerfile. Tigris object storage has a genuine free tier (5 GB + 10k PUTs/month, zero egress fees) — directly compatible with `FILESYSTEM_DISK=s3`. `llms.txt` published. Queue workers as separate process groups in `fly.toml`. Loses to Lambda on: no free compute tier (discontinued October 2024; ~$5-10/mo minimum), Fly Postgres has no free tier, Docker ownership required, `fly-mcp` is open-source single-machine limited [preview].

## Anti-Bias Cross-Check: AWS Lambda + Bref.sh

### Devil's Advocate — Weaknesses

1. **Cold start + AI inference timeout risk**: PHP bootstrap (~250ms) + Laravel boot (~300-500ms) + Guzzle call to external AI endpoint (2–10s+) all happen within the API Gateway hard 29-second timeout. A slow AI provider under load causes intermittent mobile 504s with no retry mechanism unless the AI call moves to an async SQS job.
2. **Package size trap**: `aws/aws-sdk-php` alone is ~120 MB uncompressed. Adding S3 + SQS support can push `vendor/` past the 250 MB Lambda limit, requiring ECR container image deployment — a non-trivial infrastructure addition not in the original plan.
3. **Local dev mismatch**: `php artisan serve` does not simulate Lambda's read-only filesystem, `/tmp`-only writes, or the 29s API Gateway timeout. Lambda-specific bugs (writing to `storage/` assuming persistence) appear only in production.
4. **Serverless Framework v4 licensing change**: v4 requires accounts for CI usage and gates some features on commercial plans — a vendor-lock risk that didn't exist with v3.
5. **Two-language CI chain**: Serverless Framework requires Node.js + npm alongside PHP/Composer in every CI pipeline.

### Pre-Mortem — How This Could Fail

Six months in, the solo developer has deployed MirrorMatch to Lambda but spends 40% of limited after-hours time on infrastructure firefighting instead of features. The external AI inference endpoint (FR-002, garment classification) responds in 6–8 seconds under normal load. On cold starts, PHP bootstrap + Laravel boot + AI call hits 12–15 seconds. Mobile users report the "Add garment" flow hanging intermittently. The fix — moving AI calls to an async SQS queue and having the mobile app poll for results — requires a significant change to the API contract that wasn't designed into the PRD. While debugging, the developer adds `aws/aws-sdk-php` for SQS and S3; the compressed vendor ZIP crosses 52 MB (over the 50 MB direct upload limit) and then the uncompressed size reaches 260 MB (over the 250 MB Lambda limit). A mid-project ECR migration takes a full evening. Meanwhile, the Neon free tier database pauses when the developer takes a week off. On return, the first Lambda invocation hits a paused Neon branch (3–5s wake-up) on top of a Lambda cold start, producing an 8-second first-request delay. CloudWatch Logs are verbose and unstructured, so debugging each timeout requires manual log archaeology.

### Unknown Unknowns

- **Neon free tier auto-pause + Lambda container recycling**: Neon pauses branches inactive for >5 days. Lambda containers recycle regularly. A recycled container hitting a paused Neon branch adds 3–5s latency regardless of whether Lambda itself was warm.
- **API Gateway 29s timeout is non-configurable**: If the AI inference provider ever takes >25 seconds, API Gateway returns 504 and discards the Lambda result. No retry happens. Recovery requires async architecture.
- **`BREF_LOOP_MAX` singleton state**: Keeping the PHP process alive across invocations prevents full cold restarts but can retain stale Laravel service-provider state between requests — subtle bugs that never surface in `php artisan serve` but manifest under concurrent Lambda invocations.
- **IAM role permissions drift**: Serverless Framework creates the Lambda execution role. As the project adds S3, SQS, SSM, and Secrets Manager over time, the role must be extended. A missing permission surfaces as a silent 403 from AWS, caught by Laravel as a generic exception.
- **Neon `max_connections` at free tier**: Lambda scales horizontally — 10 concurrent invocations = 10 simultaneous Neon connections. Neon's free tier limits simultaneous connections, causing intermittent connection-pool exhaustion under moderate load.

## Operational Story

- **Preview deploys**: `serverless deploy --stage=staging` deploys a named CloudFormation stack alongside `prod`. API Gateway endpoints are stage-specific (`/staging/...` vs `/prod/...`). No automatic per-PR preview URLs — staging is a long-lived environment. Access controlled via AWS IAM; staging endpoint is not publicly advertised.
- **Secrets**: Non-sensitive env vars in `serverless.yml > provider > environment:`. Sensitive values (DB URL, AI API key, APP_KEY) in AWS SSM Parameter Store (`aws ssm put-parameter --type SecureString`) and referenced in `serverless.yml` via `${ssm:/mirror-match/prod/KEY~true}`. The Lambda execution role needs `ssm:GetParameter` on the `/mirror-match/*` prefix. Rotation: update SSM value, redeploy.
- **Rollback**: `serverless rollback --stage=prod` reverts to the previous successful CloudFormation deployment. `serverless deploy list --stage=prod` shows available timestamps. Database migrations do not roll back automatically — run `serverless bref:cli --stage=prod --args="migrate:rollback"` manually before rolling back code if a migration was included in the deploy.
- **Approval**: Agent may perform unattended: `serverless deploy --stage=staging`, `serverless logs`, `serverless bref:cli --args="migrate:status"`, SSM parameter reads. Human-only: `serverless deploy --stage=prod` (production code changes), `serverless remove` (deletes entire stack), rotating APP_KEY or DB credentials, deleting S3 bucket contents, modifying IAM policies.
- **Logs**: `serverless logs -f web --stage=prod --tail` streams CloudWatch Logs. AWS MCP Server (GA): `call_aws` tool with `cloudwatch-logs:FilterLogEvents` gives structured JSON log queries from Claude Code. Prerequisite: set `LOG_CHANNEL=stderr` in `serverless.yml` environment so Laravel logs route to CloudWatch instead of the ephemeral `/tmp` filesystem.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| AI inference timeout under cold start causes mobile 504 | Devil's advocate | Medium | High | Move FR-002 AI call to async SQS job (Bref queue handler already available); return job ID immediately; mobile app polls or receives push notification |
| Package size exceeds 250 MB Lambda limit after adding AWS SDK | Devil's advocate | Medium | Medium | Run `composer install --no-dev`; add exclude patterns for test dirs in `serverless.yml`; use ECR container image deployment as escape hatch |
| Local dev behavior differs from Lambda (filesystem, timeouts) | Devil's advocate | High | Low | Document in CLAUDE.md: storage writes → S3 only on Lambda; test against staging Lambda before prod deploy; never assume `storage/` persists |
| Serverless Framework v4 commercial plan gate | Devil's advocate | Low | Low | Pin to v4 CLI version; monitor license terms; AWS SAM is a viable migration fallback |
| Two-language CI (Node.js + PHP) | Devil's advocate | Low | Low | Use `node:lts` base image in CI with PHP extension; document in GitHub Actions workflow |
| Neon free branch auto-pause adds wake-up latency | Unknown unknowns | Medium | Medium | Enable Neon connection pooler (PgBouncer mode); add `DB_CONNECT_TIMEOUT=10` in Laravel config; consider a scheduled Lambda ping to keep the branch active |
| Neon `max_connections` exhaustion under concurrent Lambda invocations | Unknown unknowns | Medium | Medium | Enable Neon PgBouncer pooling (transaction mode); set `DB_POOL_MAX=5` in `config/database.php` |
| API Gateway 29s hard timeout unrecoverable without async | Unknown unknowns | Medium | High | Budget max 20s for AI inference; if provider regularly exceeds this, async SQS flow is mandatory |
| IAM role permissions drift as project adds AWS services | Unknown unknowns | High | Low | Review Lambda execution role in `serverless.yml` on every feature that touches a new AWS service; follow least-privilege prefix `mirror-match-prod-*` |
| `BREF_LOOP_MAX` singleton state bugs under concurrent load | Unknown unknowns | Low | Medium | Audit Laravel service providers for request-scoped state; prefer `scoped()` over `singleton()` where per-request reset is expected |
| CloudFormation deploy fails mid-migration (code + DB out of sync) | Pre-mortem | Low | High | Run migrations as a separate `serverless bref:cli` step before code deploy; maintain down-migrations for every up-migration |
| Log driver writes to /tmp (lost logs) | Pre-mortem | High | Medium | Set `LOG_CHANNEL=stderr` in `serverless.yml`; verify CloudWatch log group receives output on first staging deploy |
| S3 writes silently fail due to missing IAM permissions | Pre-mortem | Medium | High | Add `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` to Lambda execution role scoped to the project bucket; smoke-test file upload on staging before prod launch |

## Getting Started

1. **Confirm Bref 3.x and Serverless Framework v4 are available:**
   ```bash
   composer show bref/bref | grep -E "^versions"   # expect 3.x
   npm install -g serverless
   serverless --version   # expect 4.x
   ```

2. **Configure a scoped AWS IAM user** (not root) and set up credentials:
   ```bash
   aws configure --profile mirror-match
   # Enter: Access Key ID, Secret Access Key, region (e.g. eu-central-1), output: json
   export AWS_PROFILE=mirror-match
   ```

3. **Set up Neon PostgreSQL** (free tier at neon.tech):
   - Create project → copy the connection string
   - Enable the built-in connection pooler in Neon dashboard (PgBouncer transaction mode)
   - Store in SSM: `aws ssm put-parameter --name /mirror-match/prod/DB_URL --value "postgresql://..." --type SecureString`
   - In `serverless.yml`: `DB_URL: ${ssm:/mirror-match/prod/DB_URL~true}`, `DB_CONNECTION: pgsql`

4. **Set required env vars in `serverless.yml`** before first deploy:
   ```yaml
   environment:
     APP_ENV: production
     LOG_CHANNEL: stderr
     SESSION_DRIVER: cookie
     FILESYSTEM_DISK: s3
     QUEUE_CONNECTION: sqs
     DB_CONNECTION: pgsql
     DB_URL: ${ssm:/mirror-match/prod/DB_URL~true}
     APP_KEY: ${ssm:/mirror-match/prod/APP_KEY~true}
   ```

5. **Deploy to staging and run migrations:**
   ```bash
   serverless deploy --stage=staging
   serverless bref:cli --stage=staging --args="migrate --force"
   # Verify endpoint: serverless info --stage=staging
   ```

6. **Promote to production** after staging smoke tests:
   ```bash
   serverless deploy --stage=prod
   serverless bref:cli --stage=prod --args="migrate --force"
   ```

## Out of Scope

The following were not evaluated in this research:
- Docker image / ECR configuration (ECR is the escape hatch if package size limits are hit)
- CI/CD pipeline setup (GitHub Actions workflow files)
- Production-scale architecture (multi-region, HA, DR)
- RDS / Aurora Serverless as paid alternatives to Neon
