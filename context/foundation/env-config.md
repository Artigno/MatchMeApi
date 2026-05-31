# Environment Configuration Reference

All secrets, credentials, and env vars required to build and deploy MirrorMatch API.

---

## Accounts

| Service | Email | Type | Notes |
|---|---|---|---|
| AWS | hubertkrzyszt@gmail.com | Private | Root account; use IAM users for day-to-day work — never root keys in CI |
| Serverless Framework | hubertkrzyszt@gmail.com | Private | app.serverless.com; needed for SFv4 CI auth |
| Neon PostgreSQL | hubertkrzyszt@gmail.com | Private | neon.tech; project `mirror-match`, region `aws-eu-central-1` |
| OpenRouter | hubertkrzyszt@gmail.com | Private | openrouter.ai; Gemini 2.0 Flash (`google/gemini-2.0-flash`) |
| Supabase | hubertkrzyszt@gmail.com | Private | supabase.com; provides JWT secret for mobile auth |

---

## GitHub Actions Secrets

Set at: **GitHub repo → Settings → Secrets and variables → Actions → New repository secret**

| Secret name | Value source | How to get |
|---|---|---|
| `AWS_ACCESS_KEY_ID` | IAM user `mirror-match-ci` | AWS Console → IAM → Users → mirror-match-ci → Security credentials → Create access key |
| `AWS_SECRET_ACCESS_KEY` | IAM user `mirror-match-ci` | Same as above (shown once on creation) |
| `SERVERLESS_ACCESS_KEY` | app.serverless.com | app.serverless.com → org → Access Keys → Create |

### Create IAM user `mirror-match-ci`

```bash
# Create user
aws iam create-user --user-name mirror-match-ci

# Attach inline policy (minimum permissions for serverless deploy)
aws iam put-user-policy \
  --user-name mirror-match-ci \
  --policy-name mirror-match-deploy \
  --policy-document '{
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
  }'

# Generate access key (copy both values immediately — secret shown once)
aws iam create-access-key --user-name mirror-match-ci
```

---

## AWS SSM Parameter Store

Region: `eu-central-1`. Set via AWS CLI. Referenced in `serverless.yml` as `${ssm:/mirror-match/${sls:stage}/KEY}`.

| Parameter | Stage | How to generate / where to get |
|---|---|---|
| `/mirror-match/dev/APP_KEY` | dev | `php artisan key:generate --show` |
| `/mirror-match/prod/APP_KEY` | prod | `php artisan key:generate --show` (separate key) |
| `/mirror-match/dev/DB_URL` | dev | Neon dashboard → dev branch → pooled connection string |
| `/mirror-match/prod/DB_URL` | prod | Neon dashboard → main branch → pooled connection string |
| `/mirror-match/dev/OPENROUTER_API_KEY` | dev | openrouter.ai → Keys |
| `/mirror-match/prod/OPENROUTER_API_KEY` | prod | openrouter.ai → Keys |

### Store secrets in SSM

```bash
# APP_KEY (generate fresh per stage — never reuse)
aws ssm put-parameter \
  --name "/mirror-match/dev/APP_KEY" \
  --value "$(php artisan key:generate --show)" \
  --type SecureString --region eu-central-1

aws ssm put-parameter \
  --name "/mirror-match/prod/APP_KEY" \
  --value "$(php artisan key:generate --show)" \
  --type SecureString --region eu-central-1

# DB_URL (from Neon dashboard — use pooled URL with sslmode=require)
aws ssm put-parameter \
  --name "/mirror-match/dev/DB_URL" \
  --value "postgresql://USER:PASS@ep-xxx.eu-central-1.aws.neon.tech/mirror-match?sslmode=require" \
  --type SecureString --region eu-central-1

aws ssm put-parameter \
  --name "/mirror-match/prod/DB_URL" \
  --value "postgresql://USER:PASS@ep-xxx.eu-central-1.aws.neon.tech/mirror-match?sslmode=require" \
  --type SecureString --region eu-central-1

# OpenRouter API key
aws ssm put-parameter \
  --name "/mirror-match/dev/OPENROUTER_API_KEY" \
  --value "sk-or-..." \
  --type SecureString --region eu-central-1

aws ssm put-parameter \
  --name "/mirror-match/prod/OPENROUTER_API_KEY" \
  --value "sk-or-..." \
  --type SecureString --region eu-central-1
```

### Verify SSM params

```bash
aws ssm get-parameter --name "/mirror-match/dev/APP_KEY" --with-decryption --region eu-central-1
aws ssm get-parameter --name "/mirror-match/dev/DB_URL" --with-decryption --region eu-central-1
aws ssm get-parameter --name "/mirror-match/dev/OPENROUTER_API_KEY" --with-decryption --region eu-central-1
```

---

## Local `.env`

Copy from `.env.example`, then fill:

```bash
cp .env.example .env
php artisan key:generate   # writes APP_KEY automatically
```

| Variable | Value | Notes |
|---|---|---|
| `APP_KEY` | auto-generated | `php artisan key:generate` fills this |
| `DB_CONNECTION` | `sqlite` | Local only; tests use in-memory SQLite |
| `SUPABASE_JWT_SECRET` | From Supabase project settings | Project → Settings → API → JWT Secret |
| `OPENROUTER_API_KEY` | From openrouter.ai → Keys | Only needed for S-02 ai-classification work |

`SUPABASE_JWT_SECRET` is not required in CI — tests use `FakeSupabaseJwtVerifier` and do not call the real verifier.

---

## Neon PostgreSQL Setup

1. Sign in at neon.tech (hubertkrzyszt@gmail.com)
2. Project: `mirror-match` → region `aws-eu-central-1` (Frankfurt)
3. Two branches: `dev` (maps to dev stage) and `main` (maps to prod stage)
4. For each branch: enable **connection pooler** (PgBouncer, transaction mode) in Neon dashboard
5. Copy the **pooled** connection string (not the direct one) — format: `postgresql://user:pass@ep-xxx-pooler.eu-central-1.aws.neon.tech/mirror-match?sslmode=require`

> Neon free tier auto-pauses branches inactive for >5 days. First Lambda cold start after pause adds 3–5s latency — expected behaviour at MVP scale.

---

## Supabase Setup

1. Sign in at supabase.com (hubertkrzyszt@gmail.com)
2. Project settings → API → **JWT Secret** → copy value
3. Set locally: `SUPABASE_JWT_SECRET=<value>` in `.env`
4. Not needed in AWS SSM yet (Supabase JWT verification is local; the secret is only needed for the `SupabaseJwtVerifier` service in production Lambda)

When wiring for Lambda: add to SSM as `/mirror-match/{stage}/SUPABASE_JWT_SECRET` and reference in `serverless.yml`.
