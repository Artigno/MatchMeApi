# MirrorMatch API

Backend API dla aplikacji MirrorMatch — mobilnej aplikacji do zarządzania garderobą i automatycznego generowania kart wystawienia ubrań na sprzedaż.

## O projekcie

MirrorMatch API to serwer API-only zbudowany w Laravel 12 (PHP 8.2), obsługujący mobilnego klienta Expo (React Native) po HTTPS. Bez Blade, bez sesji, bez cookies — same odpowiedzi JSON. Dostarcza endpointy REST dla dwóch głównych przepływów:

- **Przepływ sprzedaży** — klasyfikacja zdjęcia odzieży przez zewnętrzny serwis AI (OpenRouter), generowanie karty ogłoszenia (kategoria, marka, kolor, stan, opis) gotowej do wklejenia na Vinted lub OLX. Pola, których model nie potrafi określić z wysoką pewnością, wracają jako `null` — nigdy zgadywana wartość.
- **Przepływ stylizacji** — sugestia stroju z katalogu garderoby na podstawie pogody lub nastroju użytkownika.

MVP nie zawiera bezpośredniej integracji z API platform sprzedażowych — aplikacja generuje gotową kartę, użytkownik wkleja ją ręcznie.

## Wymagania

- PHP 8.2+
- Composer 2.4+
- Node 22+ (Vite dev asset build)
- SQLite (lokalnie i w testach, in-memory) — produkcja celuje w RDS/Aurora (AWS)

## Uruchomienie lokalne

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Pełny dev stack (server + queue + logi + Vite naraz):

```bash
composer dev
```

## Struktura

```
app/
  Console/Commands/    # artisan komendy (np. seed-wardrobe)
  Contracts/           # interfejsy usług (np. garment classifier)
  Http/Controllers/Api # kontrolery API (Auth, Classify, Garment, Supabase, User)
  Http/Middleware/     # np. app.key gate, ForceJsonResponse
  Models/              # modele Eloquent (Garment, User, ...)
  Services/            # integracje zewnętrzne (GarmentClassifierService — OpenRouter)
  Testing/             # test doubles (FakeGarmentClassifier)
config/                # konfiguracja Laravel (services, scramble, ...)
database/
  migrations/          # migracje bazy danych
  seeders/fixtures/     # obrazki + generator fixture'ów do seed-wardrobe
routes/
  api.php              # trasy API (jedyne trasy używane przez klienta mobilnego)
tests/                # testy PHPUnit (Feature)
```

## Endpointy (skrót)

- `POST /api/classify` — bez auth, gated `app.key` + throttle. Klasyfikacja zdjęcia, nic nie zapisuje.
- `POST /api/auth/supabase/exchange` — wymiana Supabase JWT na token Sanctum.
- `POST /api/auth/refresh`, `POST /api/auth/logout` — auth:sanctum.
- `GET /api/user`, `GET /api/ping` — auth:sanctum + ability `access`.
- `GET|POST /api/garments`, `GET|PATCH /api/garments/{garment}`, `POST /api/garments/{garment}/photo`, `DELETE /api/garments/{garmentId}` — CRUD garderoby, auth:sanctum + ability `access`.

Pełny, zawsze aktualny kontrakt: wygenerowany OpenAPI/Swagger (Scramble) publikowany na GitHub Pages przy każdym deployu `main`/`dev` (patrz `.github/workflows/deploy.yml`).

## Testy

```bash
composer test          # czyści cache configu, potem php artisan test
php artisan test --filter=NazwaTestu
./vendor/bin/pint --test   # code style (PSR-12)
```

Testy zawsze na in-memory SQLite (`phpunit.xml`). Strategia i cookbook: [`context/foundation/test-plan.md`](context/foundation/test-plan.md).

## Bezpieczeństwo

```bash
composer audit
```

## Architektura

- **Auth**: Laravel Sanctum (tokeny API) + wymiana tokenu Supabase JWT → Sanctum na `auth/supabase/exchange`.
- **AI**: `GarmentClassifierService` — wywołania OpenRouter (Guzzle HTTP client); enum `category`/`condition` po angielsku (zgodne z enumem klienta mobilnego), `color`/`description` po polsku.
- **Media**: spatie/laravel-medialibrary (zdjęcia garderoby); produkcja: S3 (`FILESYSTEM_DISK=s3`).
- **Deployment**: AWS Lambda via Bref.sh (`bref/bref` + `bref/laravel-bridge`), serverless.yml, stateless sesje, SQS dla kolejek na Lambdzie.
- **CI/CD**: GitHub Actions (`.github/workflows/deploy.yml`) — testy na PR, deploy `dev`/`main` → odpowiedni Lambda stage, potem publikacja OpenAPI docs (Scramble) na GitHub Pages (prod → root, dev → `/dev`).

## Dokumentacja produktu

Szczegóły wymagań funkcjonalnych, historii użytkownika i logiki biznesowej: [`context/foundation/prd.md`](context/foundation/prd.md)

Rekurentne zasady i pułapki wyłapane w trakcie pracy: [`context/foundation/lessons.md`](context/foundation/lessons.md)
