# Test Plan

> Phased test rollout for this project. Strategy is frozen at the top
> (§1–§5); cookbook patterns at the bottom (§6) fill in as phases ship.
> Read before writing any new test.
>
> Refresh: re-run `/10x-test-plan --refresh` when stale (see §8).
>
> Last updated: 2026-07-16 (Phase 2 change opened)

## 1. Strategy

Tests follow three non-negotiable principles for this project:

1. **Cost × signal.** The cheapest test that gives a real signal for the
   risk wins. Do not promote to e2e because e2e "feels safer." Do not put a
   vision model on top of a deterministic check that already catches the
   regression.
2. **User concerns are first-class evidence.** Risks anchored in "the team
   is worried about X, and the failure would surface somewhere in <area>"
   carry the same weight as PRD lines or hot-spot data.
3. **Risks are scenarios, not code locations.** This plan documents *what
   could fail* and *why we believe it's likely* — drawn from documents,
   interview, and codebase *signal* (churn, structure, test base). It does
   NOT claim to know which line owns the failure. That knowledge is
   produced by `/10x-research` during each rollout phase. If the plan and
   research disagree about where the failure lives, research is the ground
   truth.

Hot-spot scope used for likelihood weighting: `app/` (Controllers, Services, Models).

## 2. Risk Map

The top failure scenarios this project must protect against, ordered by
risk = impact × likelihood. Risks are failure scenarios in user / business
terms, not test names. The Source column cites the *evidence that surfaced
this risk* — never a file as "where the failure lives" (§1 principle #3).

| # | Risk (failure scenario) | Impact | Likelihood | Source (evidence — not anchor) |
|---|--------------------------|--------|------------|---------------------------------|
| 1 | AI classification returns a plausible-but-wrong value instead of `null`; the user trusts it and lists garbage. Confirmed asymmetry (research): `condition` is enum-guarded, but `category` accepts any string verbatim — an out-of-set but plausible category leaks | High | High | PRD guardrail #1 + NFR; lessons.md (never plausible-but-wrong); interview Q1; hot-spot `app/Services` (8 commits/30d); research.md |
| 2 | IDOR — a user reads, edits, or deletes another user's garment (authenticated ≠ owner) | High | Medium | interview Q1; PRD Access Control; hot-spot `app/Http/Controllers` (23 commits/30d); abuse/authz lens |
| 3 | Cost abuse on the unauthenticated `/classify` endpoint exhausts AI tokens (brute-force / key reuse) | High | Medium | interview Q1 (brute-force); abuse/resource lens; throttle + app-key just shipped |
| 4 | Classify failure becomes a dead-end — the user cannot save manually or re-run when the AI returns nothing | High | Medium | interview Q1; PRD guardrail #3 (work never silently discarded) + FR-003; US-01 |
| 5 | A raw client request bypasses the 422 contract (302 redirect) or the server trusts client-supplied values | Medium | Medium | lessons.md (422 only when expectsJson; ForceJsonResponse); abuse/untrusted-input lens |
| 6 | PATCH partial-update nulls out an unspecified field, or a persist path clobbers an existing wardrobe row | High | Low | interview Q1 (bad sync); roadmap S-03 risk (PATCH must not overwrite null fields with empty strings) |

**Impact × Likelihood rubric.** High = user loses access/data/money or the
failure is publicly visible / area changes weekly or already burned. Medium
= feature degrades with a workaround / touched occasionally. Low = cosmetic
or rarely touched.

Abuse lens applied: R2 (IDOR/authorization), R3 (resource abuse on a paid
endpoint), R5 (untrusted-input / server-side validation parity). Secret/PII
leakage is covered indirectly — `/classify` returns only generic error
messages and never echoes the OpenRouter key or app key; revisit if error
bodies grow. JWT verification (`SupabaseJwtVerifier`) is already covered by
existing tests (expired token, wrong signing key) and is maintained, not a
new rollout phase.

### Risk Response Guidance

| Risk | What would prove protection | Must challenge | Context `/10x-research` must ground | Likely cheapest layer | Anti-pattern to avoid |
|------|-----------------------------|----------------|--------------------------------------|-----------------------|-----------------------|
| #1 | Malformed / partial / out-of-enum / wrong-typed AI JSON yields `null` for the affected field, never a guessed value; an out-of-set `category` (and other restricted fields) is rejected to `null`, not just `condition` | "valid-looking JSON means the fields are trustworthy"; "condition being enum-guarded means every restricted field is" | the service boundary that parses the AI response; which fields have an allow-list vs pass strings verbatim; where the faked HTTP edge sits | unit (real service + `Http::fake()`) | oracle copied from the parser's own output — assert against the PRD null-not-wrong rule; testing only via `FakeGarmentClassifier` (bypasses the real parse path) |
| #2 | Every garment route returns 404 for a non-owner; the list endpoint returns only the caller's rows | "logged-in implies allowed to touch this resource" | how ownership is enforced per route; whether the index query is scoped by user; 404-vs-403 choice | integration (feature) | happy-path-only; returning 403 (leaks existence) where 404 is the contract |
| #3 | Missing/wrong `X-App-Key` → 403; requests past the per-IP and global caps → 429 | "throttle config being present means the endpoint is protected" | the app-key gate; the named rate limiter's per-IP + global buckets; how the client IP is derived behind the proxy | integration | asserting config exists without exercising the limit to a 429 |
| #4 | POST /garments with all classification fields null + a photo persists a row; `/classify` is re-callable with no server-side lock/state | "classification must succeed before a garment can be saved" | whether persist and classify are decoupled; what fields are required vs nullable on store; statelessness of `/classify` | integration | testing only the AI-success path |
| #5 | A raw multipart request without `Accept: application/json` on every mutating garment route returns 422 (not 302); enum and size limits reject server-side | "postJson tests already prove the 422 contract" | which middleware forces JSON on the api group; which routes are covered vs uncovered by a no-Accept test | integration | only Accept-header tests, which hide the real-client redirect gap |
| #6 | A PATCH that omits a field leaves that field unchanged; no persist path implicitly overwrites an existing row's data | "PATCH replaces the whole resource" | PATCH validation/`sometimes` semantics; whether store creates vs updates; what the audit/delete path guarantees | integration | asserting full-object replacement semantics |

## 3. Phased Rollout

Each row is a discrete rollout phase that will open its own change folder
via `/10x-new`. Status moves left-to-right through the values below; the
orchestrator updates Status as artifacts appear on disk.

| # | Phase name | Goal (one line) | Risks covered | Test types | Status | Change folder |
|---|------------|-----------------|----------------|------------|--------|----------------|
| 1 | AI classification safety net | Prove malformed/low-confidence AI output becomes `null`, never a guessed value | #1 | unit (service + faked HTTP) | complete | context/changes/testing-classification-safety-net/ |
| 2 | Authorization & abuse lockdown | Lock IDOR on every garment route + app-key/throttle on `/classify` + server-side 422 parity | #2, #3, #5 | integration | change opened | context/changes/testing-authz-abuse-lockdown/ |
| 3 | Core-flow resilience | Guarantee a classify failure is never a dead-end and PATCH never silently overwrites | #4, #6 | integration | not started | — |
| 4 | Quality-gates wiring | Lock the floor: suite + pint in pre-commit/CI, optional per-edit hook on risk files | cross-cutting | gates, post-edit hook | not started | — |

**Status vocabulary** (fixed): `not started` → `change opened` → `researched` → `planned` → `implementing` → `complete`.

## 4. Stack

The classic test base for this project. AI-native tools (if any) carry a
`checked:` date so future readers can see which lines need re-verification.

| Layer | Tool | Version | Notes |
|-------|------|---------|-------|
| unit + integration | PHPUnit (`php artisan test`) | Laravel 12 / PHP 8.2 | `composer test` clears config cache first; 14 Feature/Unit tests exist |
| AI / HTTP edge mocking | `Http::fake()` + `FakeGarmentClassifier` (`app/Testing/`) | n/a | OpenRouter call faked at the HTTP edge; classifier double bound via container |
| DB under test | in-memory SQLite + `RefreshDatabase` | n/a | `DB_DATABASE=:memory:` in `phpunit.xml`; never hits the dev SQLite file |
| style | Laravel Pint (PSR-12) | bundled | `./vendor/bin/pint --test` is a required gate |
| e2e | none yet | — | API-only backend; the mobile client is a separate Expo repo; e2e is out of scope here |
| accessibility | n/a | — | no UI in this repo |
| (optional) AI-native | none | — | classification correctness is checked deterministically against fixtures, not a vision model |

**Stack grounding tools (current session):**
- Docs: Context7 / framework docs MCP — none available; relied on local `phpunit.xml`, `composer.json`, existing tests; checked: 2026-06-19
- Search: Exa.ai — available; not needed (stack is standard Laravel PHPUnit, well-known); checked: 2026-06-19
- Runtime/browser: Playwright MCP — none; not used (no UI in this repo); checked: 2026-06-19
- Provider/platform: none connected; not used; checked: 2026-06-19

## 5. Quality Gates

The gates that must pass before a change reaches production. "Required after
§3 Phase N" means the gate is enforced once that rollout phase lands.

| Gate | Where | Required? | Catches |
|------|-------|-----------|---------|
| Pint style (`pint --test`) | local + CI | required | PSR-12 / style drift |
| PHPUnit suite (`composer test`) | local + CI | required | logic + contract regressions |
| `composer audit` | CI | required | known-vulnerable dependencies |
| unit on classifier normalization | local + CI | required after §3 Phase 1 | plausible-but-wrong instead of null |
| integration on authz + abuse + 422 | local + CI | required after §3 Phase 2 | IDOR, cost abuse, validation bypass |
| per-edit hook on risk files | local (agent loop) | recommended after §3 Phase 4 | regressions at edit time on `GarmentController` / `GarmentClassifierService` |

## 6. Cookbook Patterns

How to add new tests in this project. Each sub-section fills in once the
relevant rollout phase ships; before that it reads "TBD — see §3 Phase <N>."

### 6.1 Adding a unit/service test (AI classification)

- **Test type**: instantiate the **real** `GarmentClassifierService` directly (`new GarmentClassifierService`) — do NOT bind `FakeGarmentClassifier`; the fake short-circuits the parse path and proves nothing.
- **HTTP edge**: `Http::fake([<base_url>/* => Http::response(['choices' => [['message' => ['content' => $content]]]])])`, where `$content` is the model's text — usually a JSON string, deliberately malformed per the shape under test. Set `services.openrouter.*` config in `setUp()` for a deterministic base URL.
- **Oracle**: assert each returned field against the PRD null-not-wrong rule (out-of-enum / garbage → `null`; valid → the value), never against what the parser happens to emit. `category` and `condition` are allow-list-guarded (`Garment::CATEGORIES` / `CONDITIONS`); `brand`/`color`/`description` are free-text.
- **Reference test**: `tests/Feature/GarmentClassifierSafetyNetTest.php` (research shapes 1–11 + happy path).
- **Run locally**: `php artisan test --filter=GarmentClassifierSafetyNet`.
- **Bite check**: reverting the service guards must fail the out-of-enum-category and whitespace-condition tests — if it doesn't, the oracle is copied from parser output.

### 6.2 Adding an integration test for a garment route

- **Test type**: Feature test (`tests/Feature/`), `extends Tests\TestCase`, `use RefreshDatabase`.
- **Auth**: mint a Sanctum token with the `access` ability (see the `token()` helper in `tests/Feature/GarmentStoreTest.php`).
- **Reference test**: `tests/Feature/GarmentListingCardTest.php` (ownership 404s), `tests/Feature/GarmentStoreTest.php` (validation + null fields).
- **Run locally**: `php artisan test --filter=<ClassName>`.

### 6.3 Adding an authorization (IDOR) test

- TBD — see §3 Phase 2 (per-route non-owner → 404; index scoped to caller).

### 6.4 Adding an abuse / rate-limit test for `/classify`

- TBD — see §3 Phase 2 (app-key 403; per-IP + global → 429).

### 6.5 Adding a validation-parity (no-Accept 422) test

- **Pattern**: use the raw `$this->post(...)` (not `postJson`) with no `Accept` header and assert `422`; see `test_store_validation_returns_422_without_accept_header` in `tests/Feature/GarmentStoreTest.php`. Fully filled once §3 Phase 2 lands.

### 6.6 Per-rollout-phase notes

(Filled in as phases ship — anything surprising a phase taught.)

## 7. What We Deliberately Don't Test

Exclusions agreed during the rollout. Respect these unless the underlying
assumption changes.

- **Shop-platform integration / cross-posting** — V2; PRD non-goal (MVP is copy-to-clipboard, no platform API posting). Re-evaluate when platform posting enters scope. (Source: Phase 2 interview Q4.)
- **Load / scale capacity + production-deploy mechanics** — observability/infra concerns, not unit tests; MVP target scale is small. Re-evaluate if target scale rises. (Source: Phase 2 interview Q2.)
- **V2 features (outfit suggestions, barcode scan, avatar)** — unbuilt; zero test value today. (Source: PRD non-goals / roadmap Parked.)
- **Mobile-client guest-mode local-data boundary** — enforced on-device in the separate Expo repo; the API cannot assert it. Covered there, not here.

## 8. Freshness Ledger

- Strategy (§1–§5) last reviewed: 2026-06-19
- Stack versions last verified: 2026-06-19
- AI-native tool references last verified: 2026-06-19

Refresh (`/10x-test-plan --refresh`) when:

- a new top-3 risk surfaces from the roadmap or archive,
- a recommended tool's `checked:` date is older than three months,
- the project's tech stack changes (new framework, new test runner),
- §7 negative-space no longer matches what the team believes,
- a sync/upsert endpoint lands (deferred R6 "sync-merge overwrite" risk becomes testable).
