# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Nie generuj wartości plausible-but-wrong zamiast null

- **Context**: Cały projekt (wszystkie fazy)
- **Problem**: Agent generuje błędną wartość zamiast null
- **Rule**: Nigdy nie generuj wartości plausible-but-wrong. Gdy brak pewności co do pola — zwróć null, nie zgaduj.
- **Applies to**: all

## scramble:export wymaga zmigrowanej bazy w CI

- **Context**: Generowanie dokumentacji OpenAPI (`php artisan scramble:export`) w GitHub Actions / dowolnym CI bez działającej bazy.
- **Problem**: Scramble bootuje aplikację i introspektuje tabele modeli (np. `UserController@show` → `pragma_table_xinfo('users')`), by wywnioskować schematy odpowiedzi. Bez pliku/bazy SQLite eksport pada: "Database file ... does not exist". Lokalnie działa, bo baza już istnieje — błąd ujawnia się dopiero w CI.
- **Rule**: Przed `scramble:export` w CI utwórz i zmigruj bazę (`touch database/database.sqlite && php artisan migrate --force`). Eksport potrzebuje realnych kolumn, mimo że nic nie serwuje.
- **Applies to**: plan, implement, impl-review

## env(key, default) ignoruje pusty string — używaj `?:` dla zmiennych z CI

- **Context**: Configi czytające zmienne wstrzykiwane przez CI (np. `${{ vars.X }}` w GitHub Actions), gdzie zmienna repo bywa nieustawiona.
- **Problem**: `env('KEY', 'fallback')` zwraca fallback tylko gdy klucz jest NIEOBECNY. CI dla nieustawionej zmiennej przekazuje pusty string → fallback się nie aktywuje, a puste wartości wyciekają do configu (np. URL serwera w specyfikacji = `http://localhost`). To plausible-but-wrong zamiast czytelnego sentinela. Patrz [[nie-generuj-plausible-but-wrong]].
- **Rule**: Dla wartości, które CI może przekazać jako pusty string, używaj `env('KEY') ?: 'fallback'` zamiast drugiego argumentu `env()`. `?:` łapie też pusty string.
- **Applies to**: plan, implement, impl-review
