# Auth Scaffold (F-01) Implementation Plan

## Overview

Instalacja i konfiguracja Laravel Sanctum jako token-only API auth foundation. Po ukończeniu: `personal_access_tokens` tabela istnieje, User emituje tokeny z abilities i TTL, `auth:sanctum` middleware działa, route group jest gotowa dla S-01.

Token model: **access token** (5 min, ability `access`) + **refresh token** (30 dni, ability `refresh`). Aplikacja mobilna używa refresh tokena do cichej wymiany wygasłego access tokena — efekt: brak widocznego re-logowania.

## Current State Analysis

- `laravel/sanctum` nie w `composer.json`
- `User` ma tylko `HasFactory, Notifiable`
- `config/auth.php` — tylko guard `web` (session)
- `config/sanctum.php` — nie istnieje
- `app/Http/Middleware/` — katalog nie istnieje
- `routes/api.php` — tylko `GET /up`
- Migracje — brak `personal_access_tokens`
- `users.name` — non-nullable (domyślna migracja Laravela); mobile rejestracja powinna działać bez `name`

## Desired End State

Po ukończeniu F-01:
- `php artisan migrate` tworzy `personal_access_tokens` (z `expires_at`) i zmienia `users.name` na nullable
- `User::factory()->create(['name' => null])->createToken('access', ['access'], now()->addMinutes(5))` działa bez błędów
- `GET /api/ping` z Bearer tokenem → 200; bez tokena lub z wygasłym → 401
- `php artisan test --filter=SanctumSmokeTest` → PASS (3 testy)
- `routes/api.php` ma pusty blok `auth:sanctum` gotowy na trasy S-01

## What We're NOT Doing

- Brak endpointów rejestracji/logowania/wylogowania (to S-01: `account-endpoints`)
- Brak endpointu wymiany refresh tokena (to S-01)
- Brak email verification (MustVerifyEmail nie jest włączony)
- Brak OAuth / social login
- `EnsureFrontendRequestsAreStateful` **NIE** jest dodawany — to SPA-only middleware, tutaj nie potrzebne
- Brak rate limiting na token endpoints (to S-01 lub osobna zmiana)
- Brak token pruning schedule (nie ma queue/scheduler w MVP)

## Implementation Approach

Standardowy install Sanctum wg dokumentacji, zmodyfikowany dla token-only API (bez cookie/session SPA flow). Kluczowe odejście od domyślnego: `expiration: null` w `sanctum.php` — zamiast globalnego TTL, każdy token dostaje indywidualny `expires_at` przy tworzeniu (`createToken(..., now()->addMinutes(5))`). To daje pełną kontrolę nad typami tokenów (access vs refresh) bez dodatkowej tabeli.

## Critical Implementation Details

**Token creation contract** — S-01 będzie używać tego wzorca:
```php
// Access token — krótkotrwały
$user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;

// Refresh token — długotrwały
$user->createToken('refresh', ['refresh'], now()->addDays(30))->plainTextToken;
```
`createToken(string $name, array $abilities, DateTimeInterface $expiresAt)` — trzeci parametr dodany w Sanctum 3.x i dostępny w Laravel 12.

**Nie dodawać `EnsureFrontendRequestsAreStateful` do middleware** — to middleware jest dla SPA cookie auth. Token API nie używa sesji. Dodanie go mogłoby powodować nieoczekiwane 419 CSRF errors.

**Sprawdzenie wygaśnięcia tokena przez Sanctum** — Sanctum 3.x automatycznie sprawdza `expires_at` podczas uwierzytelniania. Nie trzeba pisać własnej logiki.

---

## Phase 1: Sanctum install & configure

### Overview

Instalacja pakietu, publikacja konfiguracji i migracji, dodanie trait na User model, konfiguracja sanctum.php.

### Changes Required:

#### 1. Zainstaluj pakiet

**File**: `composer.json` (przez CLI)

**Intent**: Dodaj `laravel/sanctum` do zależności projektu.

**Contract**: `composer require laravel/sanctum` — dodaje pakiet do `require` w composer.json i instaluje go w vendor/.

#### 2. Opublikuj konfigurację i migrację

**File**: `config/sanctum.php` + `database/migrations/XXXX_create_personal_access_tokens_table.php` (tworzone przez artisan)

**Intent**: Wygeneruj plik konfiguracji Sanctum i migrację tabeli tokenów.

**Contract**: `php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"` — tworzy `config/sanctum.php` i migrację z kolumnami: `id`, `tokenable_id`, `tokenable_type`, `name`, `token` (hashed), `abilities` (JSON), `last_used_at`, `expires_at`, `timestamps`.

#### 3. Skonfiguruj sanctum.php

**File**: `config/sanctum.php`

**Intent**: Wyłącz globalne wygasanie tokenów (kontrola per-token) i usuń ustawienie `stateful` — to token-only API, nie SPA.

**Contract**: Ustaw `'expiration' => null`. Sekcja `'stateful'` pozostaje z domyślnymi wartościami (są ignorowane gdy `EnsureFrontendRequestsAreStateful` nie jest w middleware stack).

#### 4. Dodaj HasApiTokens do User

**File**: `app/Models/User.php`

**Intent**: Wyposażenie modelu User w możliwość emitowania i weryfikowania API tokenów.

**Contract**: Import `use Laravel\Sanctum\HasApiTokens;` + dodanie `HasApiTokens` do `use` statement. Kolejność traitów: `HasApiTokens, HasFactory, Notifiable`.

### Success Criteria:

#### Automated Verification:

- `composer show laravel/sanctum | grep versions` zwraca wersję 3.x
- `php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --force` kończy się bez błędu
- `php artisan test --filter=SanctumSmokeTest` (Phase 3) — PASS

#### Manual Verification:

- `config/sanctum.php` istnieje, `expiration` = `null`
- `app/Models/User.php` importuje `HasApiTokens` i używa go w `use`

**Implementation Note**: Po tej fazie — verify ręcznie, zanim przejdziesz do Phase 2.

---

## Phase 2: Schema

### Overview

Uruchomienie migracji Sanctum (creates `personal_access_tokens`) i dodanie nowej migracji zmieniającej `users.name` na nullable.

### Changes Required:

#### 1. Utwórz migrację name nullable

**File**: `database/migrations/XXXX_alter_users_make_name_nullable.php` (nowy plik)

**Intent**: Zmień kolumnę `name` w tabeli `users` na nullable, żeby rejestracja mobilna działała bez podawania display name.

**Contract**: 
```php
// up()
Schema::table('users', function (Blueprint $table) {
    $table->string('name')->nullable()->change();
});

// down()
Schema::table('users', function (Blueprint $table) {
    $table->string('name')->nullable(false)->change();
});
```
Laravel 12 wspiera natywną modyfikację kolumn (nie wymaga `doctrine/dbal`).

#### 2. Uruchom migracje

**File**: baza danych (przez CLI)

**Intent**: Zastosuj obie migracje (Sanctum + name nullable).

**Contract**: `php artisan migrate` — tworzy `personal_access_tokens` i zmienia `users.name`.

### Success Criteria:

#### Automated Verification:

- `php artisan migrate --pretend` nie wyrzuca błędu
- `php artisan migrate` kończy bez błędu
- `php artisan migrate:status` pokazuje obie migracje jako `Ran`

#### Manual Verification:

- Tabela `personal_access_tokens` istnieje z kolumną `expires_at`
- `users.name` jest nullable (widoczne przez `php artisan tinker`: `User::factory()->create(['name' => null])` — brak błędu DB)

**Implementation Note**: SQLite (lokalna dev DB) wspiera `->change()` przez Laravel 12. Jeśli pojawi się błąd `SQLSTATE[HY000]: General error: 1 Cannot add a NOT NULL column with default value NULL` — sprawdź kolejność migracji.

---

## Phase 3: Route scaffold + smoke test

### Overview

Dodanie chronionego `GET /api/ping` endpointu i pustej `auth:sanctum` grupy w `routes/api.php`. Napisanie Feature testu weryfikującego token auth.

### Changes Required:

#### 1. Zaktualizuj routes/api.php

**File**: `routes/api.php`

**Intent**: Dodaj endpoint `/api/ping` chroniony przez `auth:sanctum` jako weryfikator działania Sanctum i miejsce-placeholder dla S-01.

**Contract**:
```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/up', fn () => response()->json(['status' => 'ok']));

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/ping', fn () => response()->json(['status' => 'ok', 'user_id' => auth()->id()]));

    // S-01: account endpoints here
    // S-02: ai-classification endpoints here
    // S-03: listing-card-edit endpoints here
    // S-04: wardrobe-catalogue endpoints here
    // S-05: garment-removal endpoints here
});
```

#### 2. Napisz Feature test SanctumSmokeTest

**File**: `tests/Feature/SanctumSmokeTest.php` (nowy plik)

**Intent**: Trzy asercje weryfikujące: (1) access token autoryzuje, (2) wygasły token → 401, (3) refresh token autoryzuje.

**Contract**:
```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanctumSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_access_token_returns_200(): void
    {
        $user = User::factory()->create(['name' => null]);
        $token = $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;

        $this->getJson('/api/ping', ['Authorization' => 'Bearer ' . $token])
             ->assertOk();
    }

    public function test_expired_token_returns_401(): void
    {
        $user = User::factory()->create(['name' => null]);
        $token = $user->createToken('access', ['access'], now()->subMinute())->plainTextToken;

        $this->getJson('/api/ping', ['Authorization' => 'Bearer ' . $token])
             ->assertUnauthorized();
    }

    public function test_valid_refresh_token_returns_200(): void
    {
        $user = User::factory()->create(['name' => null]);
        $token = $user->createToken('refresh', ['refresh'], now()->addDays(30))->plainTextToken;

        $this->getJson('/api/ping', ['Authorization' => 'Bearer ' . $token])
             ->assertOk();
    }
}
```

### Success Criteria:

#### Automated Verification:

- `php artisan test --filter=SanctumSmokeTest` — 3 testy PASS
- `php artisan test` (cały suite) — PASS (bez regresji)
- `./vendor/bin/pint --test` — brak błędów stylu

#### Manual Verification:

- `curl -s http://localhost:8000/api/ping` → `{"message":"Unauthenticated."}` (401)
- `php artisan tinker` → `User::factory()->create(['name'=>null])->createToken('access',['access'],now()->addMinutes(5))->plainTextToken` → zwraca token string
- Curl z tokenem → `{"status":"ok","user_id":1}` (200)

**Implementation Note**: Po ukończeniu tej fazy — F-01 jest kompletne. Roadmap status F-01 można zmienić z `ready` na `in-progress` → `done`.

---

## Testing Strategy

### Unit Tests:

Brak — F-01 to integracja biblioteki, nie logika domenowa. Smoke test w Feature jest wystarczający.

### Integration Tests (Feature):

- `SanctumSmokeTest` — 3 testy: valid access, expired, valid refresh

### Manual Testing Steps:

1. `php artisan serve` → `curl http://localhost:8000/api/up` → `{"status":"ok"}`
2. Tinker → utwórz token → curl z Authorization header → `{"status":"ok","user_id":1}`
3. Tinker → utwórz token z przeszłym `expiresAt` → curl → `{"message":"Unauthenticated."}`

## References

- Roadmap F-01: `context/foundation/roadmap.md#f-01-auth-scaffold`
- Sanctum docs: https://laravel.com/docs/12.x/sanctum#api-token-authentication
- Lessons: `context/foundation/lessons.md` — reguła null vs plausible-but-wrong dotyczy S-02, nie F-01

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Sanctum install & configure

#### Automated

- [x] 1.1 `composer show laravel/sanctum` zwraca wersję 3.x — 5f2f0d8
- [x] 1.2 `config/sanctum.php` istnieje, `expiration: null` — 5f2f0d8
- [x] 1.3 `app/Models/User.php` importuje i używa `HasApiTokens` — 5f2f0d8

#### Manual

- [ ] 1.4 Ręczna weryfikacja `config/sanctum.php` i `User.php`

### Phase 2: Schema

#### Automated

- [x] 2.1 `php artisan migrate --pretend` bez błędu
- [x] 2.2 `php artisan migrate` bez błędu
- [x] 2.3 `php artisan migrate:status` — obie migracje `Ran`

#### Manual

- [ ] 2.4 Tabela `personal_access_tokens` istnieje z `expires_at`
- [ ] 2.5 `User::factory()->create(['name' => null])` — brak błędu DB

### Phase 3: Route scaffold + smoke test

#### Automated

- [ ] 3.1 `php artisan test --filter=SanctumSmokeTest` — 3 testy PASS
- [ ] 3.2 `php artisan test` — cały suite PASS
- [ ] 3.3 `./vendor/bin/pint --test` — PASS

#### Manual

- [ ] 3.4 `curl /api/ping` bez tokena → 401
- [ ] 3.5 `curl /api/ping` z ważnym tokenem → 200 z `user_id`
- [ ] 3.6 `curl /api/ping` z wygasłym tokenem → 401
