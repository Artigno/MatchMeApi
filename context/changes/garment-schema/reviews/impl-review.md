<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Add garments migration with listing card fields and soft delete (F-02)

- **Plan**: context/changes/garment-schema/plan.md
- **Scope**: All Phases (2 of 2)
- **Date**: 2026-05-27
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical  2 warnings  2 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — user_id in $fillable enables IDOR when S-02 HTTP endpoints land

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Models/Garment.php:17
- **Detail**: `user_id` is in `$fillable`. Today this is safe (no HTTP endpoints). When S-02 adds `POST /garments`, a controller that does `Garment::create($request->all())` or `$request->validated()` (if `user_id` leaks into validation) lets any authenticated user set `user_id` to any value — classic IDOR. The plan did not address this; it deferred auth concerns to S-02/S-04. The risk is dormant now but baked into the schema layer.
- **Fix A ⭐ Recommended**: Remove `user_id` from `$fillable` now; set it explicitly in S-02.
  - Strength: Closes the IDOR class entirely at the model level — no controller discipline needed later. Consistent with Laravel convention of not putting server-side FKs in $fillable.
  - Tradeoff: Factory must set user_id via direct attribute; but factories bypass $fillable anyway so `User::factory()` subexpression still works.
  - Confidence: HIGH — standard Laravel pattern; lessons.md null-not-wrong rule applies to classification fields, not auth fields.
  - Blind spot: GarmentFactory uses `'user_id' => User::factory()` — this still works even without $fillable because factories bypass $fillable.
- **Fix B**: Leave as-is; add explicit `$request->user()->id` assignment rule to S-02 plan.
  - Strength: No code change now; defers to the place where the risk actually fires.
  - Tradeoff: Relies on S-02 implementer following the rule — model offers no guard.
  - Confidence: MEDIUM — works only if the rule is followed consistently.
  - Blind spot: If S-02 is implemented in a new agent run with no memory of this finding, risk silently re-opens.
- **Decision**: PENDING

### F2 — No test for cascadeOnDelete FK behavior

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Feature/GarmentSchemaTest.php (missing test)
- **Detail**: Migration declares `cascadeOnDelete()` on the `user_id` FK. No test verifies that deleting a User removes their Garments. Future migrations could silently drop the CASCADE constraint and the suite would not catch it.
- **Fix**: Add one test: `$user->delete()` → `Garment::withTrashed()->where('user_id', $user->id)->count()` === 0.
- **Decision**: PENDING

### F3 — outerwear in factory not in plan contract

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: database/factories/GarmentFactory.php:19
- **Detail**: Plan contract: `['top', 'bottom', 'shoes', 'accessory']`. Actual: `['top', 'bottom', 'shoes', 'accessory', 'outerwear']`. Benign — factory data only; no schema constraint, no API surface.
- **Fix**: Remove outerwear from factory to match plan, or add addendum note to the plan.
- **Decision**: PENDING

### F4 — Trait order diverges from User model convention

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Models/Garment.php:14
- **Detail**: `use HasFactory, InteractsWithMedia, SoftDeletes;` — alphabetical within group. User.php uses `HasApiTokens, HasFactory, Notifiable` — also roughly alphabetical. Not a hard convention, minor consistency note.
- **Fix**: No action needed unless a project convention is formally established.
- **Decision**: PENDING
