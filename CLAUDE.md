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

<!-- BEGIN @przeprogramowani/10x-cli -->

## 10xDevs AI Toolkit - Module 2, Lesson 4

Prepare for a harder implementation stream with the **research-backed planning chain**:

```
internal research (/10x-research) + external research (exa.ai, Context7) -> /10x-plan -> /10x-implement -> success
```

The lesson focus is distinguishing internal from external research and using evidence to back planning decisions.

### Task Router - Where to start

| Skill | Use it when |
| --- | --- |
| **Internal research (lesson focus)** | |
| `/10x-research <change-id>` | You need evidence from the existing codebase — patterns, conventions, integration points, or existing implementations. Runs parallel sub-agents over the repo and writes structured findings to `research.md`. |
| **External research (lesson focus)** | |
| exa.ai | You need AI-native web search for library comparisons, best practices, or ecosystem context that the codebase cannot answer. |
| Context7 (`resolve-library-id` → `get-library-docs`) | You need live, current documentation for a specific library or framework. Resolves a library ID first, then fetches relevant doc pages. |
| **Framing spare wheel** | |
| `/10x-frame <change-id>` | The plan won't converge, the plan doesn't deliver expected results, or persistent drift keeps breaking the implementation. Use as an escape hatch on a separate problem (demonstrated on Space Explorers example), not as pre-research ritual. |
| **Planning and execution** | |
| `/10x-plan <change-id>` / `/10x-implement <change-id> phase <n>` | Use the same planning and execution chain from Lesson 2, now with upstream research evidence feeding the plan. |

### Research discipline

- Internal research (`/10x-research`) answers "what does our codebase already do?" — patterns, schemas, conventions, integration points.
- External research (exa.ai, Context7) answers "what should we do?" — library capabilities, API docs, ecosystem best practices.
- Combine both as evidence-backed input to `/10x-plan`. A plan without research evidence on a non-trivial stream is a guess.
- Agent-friendly docs (`llms.txt`, markdown-for-agents, `/md` endpoints) are a quality signal for library selection — libraries that publish agent-readable docs integrate faster.

### `/10x-frame` as spare wheel

Three triggers for reaching for `/10x-frame`:
1. The plan won't converge — research keeps opening more questions instead of narrowing to a contract.
2. The plan doesn't deliver — implementation repeatedly fails to meet success criteria.
3. Persistent drift — the implementation keeps diverging from the plan in ways that suggest the problem was mis-framed.

Demonstrated on a Space Explorers example, not the SRS path. It is an escape hatch, not a mandatory step.

### Paths used by this lesson

- `context/changes/<change-id>/research.md` - internal research output
- `context/changes/<change-id>/frame.md` - framing output when needed
- `context/changes/<change-id>/plan.md` - evidence-backed implementation contract
- `context/foundation/lessons.md` - recurring rules and pitfalls

Skills must not write to `context/archive/`. Archived changes are immutable; if a resolved target path starts with `context/archive/`, abort with: "This change is archived. Open a new change with `/10x-new` instead."

<!-- END @przeprogramowani/10x-cli -->
