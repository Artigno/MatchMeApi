# MirrorMatch API

Backend API dla aplikacji MirrorMatch — mobilnej aplikacji do zarządzania garderobą i automatycznego generowania kart wystawienia ubrań na sprzedaż.

## O projekcie

MirrorMatch API to serwer zbudowany w Laravel 12 (PHP 8.2), obsługujący mobilnego klienta Expo (React Native). Dostarcza endpointy REST dla dwóch głównych przepływów:

- **Przepływ sprzedaży** — klasyfikacja zdjęcia odzieży przez zewnętrzny serwis AI, generowanie karty ogłoszenia (kategoria, marka, kolor, stan, opis) gotowej do wklejenia na Vinted lub OLX.
- **Przepływ stylizacji** — sugestia stroju z katalogu garderoby na podstawie pogody lub nastroju użytkownika.

MVP nie zawiera bezpośredniej integracji z API platform sprzedażowych — aplikacja generuje gotową kartę, użytkownik wkleja ją ręcznie.

## Wymagania

- PHP 8.2+
- Composer 2.4+
- SQLite (domyślnie, lokalnie) lub PostgreSQL (docelowo produkcja)

## Uruchomienie lokalne

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

## Struktura

```
app/
  Http/Controllers/   # kontrolery API
  Models/             # modele Eloquent
config/               # konfiguracja Laravel
database/
  migrations/         # migracje bazy danych
routes/
  api.php             # trasy API
tests/                # testy PHPUnit
```

## Testy

```bash
php artisan test
```

## Bezpieczeństwo

```bash
composer audit
```

## Architektura

- **Auth**: Laravel Sanctum (tokeny API dla klienta mobilnego)
- **AI**: wywołania zewnętrznego serwisu inferencji (HTTP client Guzzle)
- **Przechowywanie plików**: docelowo S3 (produkcja AWS Lambda)
- **Deployment**: AWS Lambda via Bref.sh lub Laravel Vapor

## Dokumentacja produktu

Szczegóły wymagań funkcjonalnych, historii użytkownika i logiki biznesowej: [`context/foundation/prd.md`](context/foundation/prd.md)
