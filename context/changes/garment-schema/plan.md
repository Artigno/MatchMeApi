# Garment Schema (F-02) Implementation Plan

## Overview

Stworzenie migracji tabeli `garments` z polami karty ogłoszenia i soft-delete, modelu Eloquent `Garment` z traiitem `SoftDeletes`, fabryki oraz testu smoke. Fundament pod S-02 (klasyfikacja AI), S-04 (katalog szafy) i S-05 (usuwanie).

## Current State Analysis

- Brak tabeli `garments` i modelu `Garment`
- Migracje użytkownika wylądowane (`users`, `personal_access_tokens`) — `user_id` FK ma do czego wskazywać
- Wzorzec migracji z `create_users_table.php` i `create_personal_access_tokens_table.php` — do naśladowania
- `app/Models/User.php` jako wzorzec modelu (traits, fillable, casts)
- Laravel 12 + SQLite lokalnie

## Desired End State

Po ukończeniu F-02:
- `php artisan migrate` tworzy tabelę `garments` z kolumnami: `id`, `user_id`, `photo_path`, `category`, `brand`, `color`, `condition`, `description`, `deleted_at`, `created_at`, `updated_at`
- `Garment::factory()->create(['category' => null])` działa bez błędów DB
- `$garment->delete()` ustawia `deleted_at` (soft-delete)
- `php artisan test --filter=GarmentSchemaTest` → PASS
- Model `Garment` gotowy do użycia w S-02/S-04/S-05

## What We're NOT Doing

- Brak endpointów API (to S-02/S-04/S-05)
- Brak logiki klasyfikacji (to S-02)
- Brak relacji `hasMany` na modelu `User` (to S-02/S-04, nie F-02)
- Brak enum dla `condition` — string (walidacja w service layer, nie w schemacie)
- Brak JSON blob-ów dla pól klasyfikacji — każde pole osobna kolumna
- Brak uploadu zdjęć (to S-02 — `photo_path` przechowuje ścieżkę po uploadzłe)

## Implementation Approach

Standardowa Laravel migracja + model + factory. Wszystkie pola klasyfikacji nullable (AI zwraca null zamiast plausible-but-wrong — reguła z lessons.md). `photo_path` nullable — rekord może istnieć przed wgraniem zdjęcia. `description` jako `text` (dłuższy tekst), pozostałe jako `string` (varchar).

## Critical Implementation Details

**Nullability wszystkich pól klasyfikacji** — AI classification (S-02) nie może zawsze wyznaczyć wszystkich pól. Schemat musi akceptować null. Walidacja kompletności karty ogłoszenia należy do service layer, nie do schematu DB.

**`photo_path` nullable** — w flow S-02 zdjęcie jest przesyłane i klasyfikowane atomowo, ale schemat nie powinien wymuszać path na poziomie DB; elastyczność potrzebna do testów i edge cases.

---

## Phase 1: Migration + Garment model + factory

### Overview

Stworzenie migracji `garments`, modelu Eloquent z SoftDeletes i factory. Uruchomienie migracji.

### Changes Required:

#### 1. Utwórz migrację `garments`

**File**: `database/migrations/XXXX_create_garments_table.php` (przez `php artisan make:migration`)

**Intent**: Zdefiniuj schemat tabeli `garments` z wszystkimi polami karty ogłoszenia, FK do users i soft-delete.

**Contract**:
```php
Schema::create('garments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('photo_path')->nullable();
    $table->string('category')->nullable();
    $table->string('brand')->nullable();
    $table->string('color')->nullable();
    $table->string('condition')->nullable();
    $table->text('description')->nullable();
    $table->softDeletes();
    $table->timestamps();
});
```

`cascadeOnDelete()` — usunięcie użytkownika usuwa jego garmentu. `softDeletes()` dodaje kolumnę `deleted_at`.

#### 2. Utwórz model `Garment`

**File**: `app/Models/Garment.php` (nowy plik)

**Intent**: Model Eloquent z `SoftDeletes`, `fillable` na wszystkich edytowalnych polach i relacją `belongsTo(User::class)`.

**Contract**: Traits: `SoftDeletes`. `$fillable`: `['user_id', 'photo_path', 'category', 'brand', 'color', 'condition', 'description']`. Metoda `user()` → `belongsTo(User::class)`.

#### 3. Utwórz factory `GarmentFactory`

**File**: `database/factories/GarmentFactory.php` (nowy plik)

**Intent**: Fabryka generująca realistyczne dane testowe dla garmentu. Pola klasyfikacji wypełnione fake danymi (na potrzeby testów jednostkowych S-02+), ale wszystkie nullable w sygnaturze.

**Contract**: `definition()` zwraca: `user_id` → `User::factory()`, `photo_path` → `'photos/test.jpg'`, `category` → losowy z kilku (np. `fake()->randomElement(['top', 'bottom', 'shoes', 'accessory'])`) , `brand` → `fake()->company()`, `color` → `fake()->colorName()`, `condition` → `fake()->randomElement(['new', 'like_new', 'good', 'fair'])`, `description` → `fake()->sentence()`.

### Success Criteria:

#### Automated Verification:

- `php artisan migrate --pretend` bez błędu — tabela `garments` widoczna
- `php artisan migrate` bez błędu
- `php artisan migrate:status` — migracja `garments` jako `Ran`

#### Manual Verification:

- Tabela `garments` istnieje z kolumną `deleted_at`
- `Garment::factory()->create(['category' => null])` w tinker — brak błędu DB

**Implementation Note**: Po automated verification — pauza na manualną weryfikację przed przejściem do Phase 2.

---

## Phase 2: Smoke test + style

### Overview

Napisanie `GarmentSchemaTest` weryfikującego factory create z null polami i soft-delete. Pint check.

### Changes Required:

#### 1. Napisz `GarmentSchemaTest`

**File**: `tests/Feature/GarmentSchemaTest.php` (nowy plik)

**Intent**: Dwa testy: (1) factory create z null polami klasyfikacji — brak błędu DB; (2) soft-delete ustawia `deleted_at` i ukrywa rekord z domyślnych zapytań.

**Contract**:
```php
class GarmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_garment_factory_creates_with_null_classification_fields(): void
    {
        $garment = Garment::factory()->create([
            'category' => null,
            'brand' => null,
            'color' => null,
            'condition' => null,
            'description' => null,
        ]);

        $this->assertNotNull($garment->id);
        $this->assertNull($garment->category);
    }

    public function test_soft_delete_sets_deleted_at(): void
    {
        $garment = Garment::factory()->create();
        $garment->delete();

        $this->assertNotNull($garment->deleted_at);
        $this->assertNull(Garment::find($garment->id)); // hidden from default scope
        $this->assertNotNull(Garment::withTrashed()->find($garment->id));
    }
}
```

### Success Criteria:

#### Automated Verification:

- `php artisan test --filter=GarmentSchemaTest` → 2 testy PASS
- `php artisan test` — cały suite PASS (bez regresji)
- `./vendor/bin/pint --test` — PASS

#### Manual Verification:

- Brak regresji w `SanctumSmokeTest`

**Implementation Note**: Po automated verification — pauza na manualną weryfikację. F-02 kompletne po tej fazie.

---

## Testing Strategy

### Unit Tests:

Brak — F-02 to schema + model, nie logika domenowa.

### Integration Tests (Feature):

- `GarmentSchemaTest` — 2 testy: null factory create, soft-delete

### Manual Testing Steps:

1. `php artisan tinker` → `Garment::factory()->create(['category' => null])` → brak błędu
2. `php artisan tinker` → `$g = Garment::factory()->create(); $g->delete(); Garment::find($g->id)` → null (soft-deleted)
3. `Garment::withTrashed()->find($g->id)` → rekord z `deleted_at` ustawionym

## References

- Roadmap F-02: `context/foundation/roadmap.md#f-02-garment-domain-schema`
- Wzorzec migracji: `database/migrations/0001_01_01_000000_create_users_table.php`
- Lessons: `context/foundation/lessons.md` — null zamiast plausible-but-wrong

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Migration + Garment model + factory

#### Automated

- [x] 1.1 `php artisan migrate --pretend` bez błędu
- [x] 1.2 `php artisan migrate` bez błędu
- [x] 1.3 `php artisan migrate:status` — migracja `garments` jako `Ran`

#### Manual

- [x] 1.4 Tabela `garments` istnieje z kolumną `deleted_at`
- [x] 1.5 `Garment::factory()->create(['category' => null])` — brak błędu DB

### Phase 2: Smoke test + style

#### Automated

- [ ] 2.1 `php artisan test --filter=GarmentSchemaTest` — 2 testy PASS
- [ ] 2.2 `php artisan test` — cały suite PASS
- [ ] 2.3 `./vendor/bin/pint --test` — PASS

#### Manual

- [ ] 2.4 Brak regresji w `SanctumSmokeTest`
