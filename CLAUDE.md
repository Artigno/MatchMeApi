## Project

MirrorMatch API — Laravel 12 (PHP 8.2) backend serving the MirrorMatch Expo mobile client over HTTPS. Two domain flows: AI-based garment classification (photo → listing card) and outfit recommendation (wardrobe + context → suggestion). See `context/foundation/prd.md` for full requirements.

## Commands

```bash
# First-time setup
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

# Development server only
php artisan serve

# Full dev stack (server + queue + logs + Vite)
composer dev

# Tests (clears config cache first)
composer test

# Single test class or method
php artisan test --filter=ExampleTest
php artisan test --filter="ExampleTest::test_basic"

# Code style (Laravel Pint — PSR-12 based)
./vendor/bin/pint
./vendor/bin/pint --test   # check only, no write

# Security audit
composer audit
```

## Architecture

This is an **API-only** Laravel app — no Blade views, no sessions, no cookies. All responses are JSON. The mobile client authenticates with Laravel Sanctum tokens.

**Routes**: `routes/api.php` (does not exist yet in the scaffold — create it; `routes/web.php` carries only the default welcome route and is not used by the mobile client).

**AI classification** (FR-002): HTTP call to an external inference endpoint via Guzzle. No local model. The classification service URL and key go in `.env` / `config/services.php`. Fields the AI cannot determine with high confidence must be returned as `null`, never a plausible-but-wrong value.

**Auth** (FR-007): Laravel Sanctum (`laravel/sanctum`) — not yet installed. Token-based auth for the mobile client. Two-state model: guest (local device only, no API calls) → account (syncs to API).

**Database**: SQLite locally (`.env` default). Tests always use in-memory SQLite (`DB_DATABASE=:memory:` in `phpunit.xml`). Production targets RDS/Aurora on AWS Lambda.

**Deployment**: Bref.sh (`bref/bref` + `bref/laravel-bridge`) already installed. AWS Lambda requires stateless sessions (no `SESSION_DRIVER=database` on Lambda), S3 for file storage (`FILESYSTEM_DISK=s3`), and SQS for queues (`QUEUE_CONNECTION=sqs`).

## Lessons learned

Zobacz: `context/foundation/lessons.md`
