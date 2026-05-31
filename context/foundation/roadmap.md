---
project: MirrorMatch
version: 1
status: draft
created: 2026-05-25
updated: 2026-05-25
prd_version: 1
main_goal: speed
top_blocker: time
---

# Roadmap: MirrorMatch

> Derived from `context/foundation/prd.md` (v1) + auto-researched codebase baseline.
> Edit-in-place; archive when superseded.
> Slices below are listed in dependency order. The "At a glance" table is the index.

## Vision recap

MirrorMatch eliminates the 5–15-minute manual effort of listing a garment for resale. A user photographs a garment; an AI classification engine extracts category, brand, color, condition, and description; the user reviews, edits, and copies the ready listing card to Vinted — without typing a single field. The shared wardrobe catalogue also powers future outfit suggestions, but the resale automation is the initial reason to build.

## North star

**S-02: AI classification endpoint** — POST ze zdjęciem garmentu zwraca wypełnioną kartę ogłoszenia; udowadnia, że AI vision wyciąga użyteczne dane dla odzieży (rdzeń produktu). To pierwsza rzecz, którą warto mieć działającą — jeśli AI nie działa wystarczająco dobrze, reszta roadmapy traci sens.

> Gwiazda przewodnia (north star): najmniejszy przebieg end-to-end, którego dostarczenie jako pierwsze udowadnia, że rdzeń produktu działa. Umieszczona tak wcześnie jak pozwalają Prerequisites, bo wszystko inne ma sens tylko wtedy, gdy ona działa.

## At a glance

| ID   | Change ID           | Outcome (user can …)                                                                          | Prerequisites        | PRD refs                          | Status   |
| ---- | ------------------- | --------------------------------------------------------------------------------------------- | -------------------- | --------------------------------- | -------- |
| F-01 | auth-scaffold       | (foundation) Sanctum zainstalowany; API guard + HasApiTokens skonfigurowane                   | —                    | FR-007                            | done     |
| F-02 | garment-schema      | (foundation) migracja `garments` wylądowana; pola karty ogłoszenia + soft-delete              | —                    | FR-001, FR-002, FR-003, FR-004, FR-005 | ready    |
| F-03 | ci-cd-pipeline      | (foundation) GitHub Actions: testy + deploy-dev na PR, deploy-prod na merge do main           | —                    | —                                 | done     |
| S-01 | account-endpoints   | założyć konto, zalogować się, wylogować się                                                   | F-01                 | FR-007, US-01                     | proposed |
| S-02 | ai-classification   | wgrać zdjęcie garmentu i otrzymać wypełnioną kartę ogłoszenia w ciągu 30 sekund              | F-01, F-02, S-01     | FR-001, FR-002, FR-006, US-01     | proposed |
| S-03 | listing-card-edit   | przejrzeć i edytować dowolne pole karty ogłoszenia przed eksportem                            | S-02                 | FR-003, US-01                     | proposed |
| S-04 | wardrobe-catalogue  | przeglądać wszystkie garmencie w swojej szafie                                                | F-01, F-02, S-01     | FR-004                            | proposed |
| S-05 | garment-removal     | usunąć garment ze swojej szafy                                                                | F-01, F-02, S-01     | FR-005                            | proposed |

## Streams

Pomoc nawigacyjna — grupuje elementy według wspólnego łańcucha Prerequisites. Kanoniczne porządkowanie żyje w grafie zależności poniżej; ta tabela to proponowany porządek czytania między równoległymi torami.

| Stream | Temat                          | Łańcuch                        | Nota                                                                          |
| ------ | ------------------------------ | ------------------------------ | ----------------------------------------------------------------------------- |
| A      | Auth backbone                  | `F-01` → `S-01`                | Prerequisite dla wszystkich slices; uruchomić równolegle z B i D              |
| B      | Klasyfikacja (gwiazda)         | `F-02` → `S-02` → `S-03`      | F-02 równolegle z F-01; S-02 zablokowane do czasu wyboru dostawcy AI          |
| C      | Zarządzanie szafą              | `S-04` / `S-05`                | Równolegle z S-02; dołączają do Streamów A+B po ukończeniu S-01 + F-02        |
| D      | Infra                          | `F-03`                         | Standalone; przyspiesza iterację; uruchomić równolegle z A i B                |

## Baseline

Stan codebase na 2026-05-25 (auto-researched + user-confirmed). Foundations poniżej zakładają, że te elementy są obecne i NIE re-scaffoldują ich.

- **Frontend:** absent — API-only backend; mobilny klient to osobne repo Expo
- **Backend/API:** partial — `routes/api.php` z health-checkiem (`GET /up`) tylko; brak kontrolerów domenowych
- **Data:** partial — SQLite skonfigurowany lokalnie; tylko domyślne migracje Laravel (users, cache, jobs); brak schematu `garments`
- **Auth:** absent — `laravel/sanctum` nie zainstalowany; model `User` bez `HasApiTokens`; brak API guard
- **Deploy/infra:** present — `serverless.yml` skonfigurowany dla AWS Lambda + Bref.sh (eu-central-1, php-82-fpm); brak `.github/workflows/`
- **Observability:** partial — `LOG_CHANNEL=stderr` + `CloudWatchFormatter` w `serverless.yml`; brak biblioteki error-trackingu (Sentry itp.)

## Foundations

### F-01: Auth scaffold

- **Outcome:** (foundation) `laravel/sanctum` zainstalowany; API guard skonfigurowany w `config/auth.php`; model `User` ma trait `HasApiTokens`; middleware `auth:sanctum` dostępne do ochrony tras.
- **Change ID:** auth-scaffold
- **PRD refs:** FR-007
- **Unlocks:** S-01 (trasy auth), a przez S-01 → S-02, S-04, S-05
- **Prerequisites:** —
- **Parallel with:** F-02, F-03
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Niskie ryzyko; Sanctum token-auth to dobrze udokumentowany flow. Pułapka: użycie domyślnego session guard zamiast `auth:sanctum` w trasach API — API jest bezstanowe, zawsze `auth:sanctum`.
- **Status:** done

### F-02: Garment domain schema

- **Outcome:** (foundation) migracja `garments` wylądowana; tabela zawiera: `id`, `user_id` (FK → users), `photo_path`, `category`, `brand`, `color`, `condition`, `description`, `deleted_at` (soft delete), `timestamps`.
- **Change ID:** garment-schema
- **PRD refs:** FR-001, FR-002, FR-003, FR-004, FR-005
- **Unlocks:** S-02 (zapis garmentu po klasyfikacji), S-04 (zapytania do katalogu), S-05 (usuwanie)
- **Prerequisites:** —
- **Parallel with:** F-01, F-03
- **Blockers:** —
- **Unknowns:** Delete vs soft-archive (PRD Q2) — kolumna `deleted_at` w migracji obsługuje obie opcje bez zmiany schematu. Owner: user. Block: no.
- **Risk:** Minimalne. Ryzyko: over-engineering pól w migracji — trzymać się 7 pól karty ogłoszenia + relacji; żadnych JSON blob-ów dla pól klasyfikacji.
- **Status:** ready

### F-03: CI/CD pipeline

- **Outcome:** (foundation) `.github/workflows/deploy.yml` obecny; push na PR uruchamia testy + `npx serverless deploy --stage dev`; merge do `main` uruchamia `npx serverless deploy --stage prod`; oba wymagają zdanych testów.
- **Change ID:** ci-cd-pipeline
- **PRD refs:** —
- **Unlocks:** Szybsza iteracja na każdym slice — brak ręcznego `serverless deploy` między PR merge'ami
- **Prerequisites:** —
- **Parallel with:** F-01, F-02
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Dwujęzyczne CI (PHP + Node.js dla Serverless Framework). SFv4 wymaga `SERVERLESS_ACCESS_KEY` w CI (dodany jako GitHub secret).
- **Status:** done

## Slices

### S-01: Account endpoints

- **Outcome:** użytkownik może założyć konto, zalogować się, wylogować się (`POST /api/register`, `POST /api/login`, `POST /api/logout`, `GET /api/user`)
- **Change ID:** account-endpoints
- **PRD refs:** FR-007, US-01
- **Prerequisites:** F-01
- **Parallel with:** F-02, F-03
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Standardowy flow Sanctum token-auth. Ryzyko: zbyt szczegółowe błędy ujawniające istnienie konta ("brak konta o tym mailu" vs "nieprawidłowe dane") — zwracać ogólny komunikat `401 Unauthorized`.
- **Status:** proposed

### S-02: AI classification endpoint ★ North star

- **Outcome:** użytkownik może wgrać zdjęcie garmentu i otrzymać wypełnioną kartę ogłoszenia (category, brand, color, condition, description) — pola, których AI nie może określić z wysoką pewnością, są zwracane jako `null`, nigdy jako plausible-but-wrong; pełna karta zwrócona w ciągu 30 sekund
- **Change ID:** ai-classification
- **PRD refs:** FR-001, FR-002, FR-006, US-01
- **Prerequisites:** F-01, F-02, S-01
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:** —
  > ✅ **Q1 resolved (2026-05-26):** Gemini 2.0 Flash via OpenRouter (`google/gemini-2.0-flash`). Base URL: `https://openrouter.ai/api/v1` (OpenAI-compatible). Klucz w SSM: `/mirror-match/{stage}/OPENROUTER_API_KEY`.
- **Risk:** Opóźnienie zewnętrznego AI API w połączeniu z Lambda cold-start może zbliżyć się do 30-sekundowego SLA (PHP bootstrap ~250ms + Laravel boot ~300–500ms + wywołanie AI 2–10s). Mitigacja: jeśli klasyfikacja trwa >2s — zwróć widoczny postęp (NFR). Drugie ryzyko: dostawca AI zwraca nieustrukturyzowany JSON — progi ufności muszą być egzekwowane w warstwie serwisu, nie kontrolera (patrz `lessons.md`: nigdy nie zwracaj plausible-but-wrong zamiast null).
- **Status:** proposed

### S-03: Listing card review/edit

- **Outcome:** użytkownik może przejrzeć i edytować dowolne pole karty ogłoszenia przed eksportem (`GET /api/garments/{id}`, `PATCH /api/garments/{id}` z częściowymi aktualizacjami pól)
- **Change ID:** listing-card-edit
- **PRD refs:** FR-003, US-01
- **Prerequisites:** S-02
- **Parallel with:** S-04, S-05
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Semantyka częściowego update (PATCH) nie może nadpisywać pól `null` pustymi stringami — klient mobilny musi wysyłać tylko pola, które użytkownik zmienił. Kontrakt PATCH zdefiniować wprost w `/10x-plan listing-card-edit`.
- **Status:** proposed

### S-04: Wardrobe catalogue

- **Outcome:** użytkownik może przeglądać wszystkie garmencie w swojej szafie (`GET /api/garments` — paginacja, posortowane po `created_at desc`)
- **Change ID:** wardrobe-catalogue
- **PRD refs:** FR-004
- **Prerequisites:** F-01, F-02, S-01
- **Parallel with:** S-02, S-05
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Minimalne. Ryzyko: N+1 query jeśli pola karty są ładowane lazy — trzymać wszystkie pola na tabeli `garments` (bez osobnej tabeli `listing_cards`).
- **Status:** proposed

### S-05: Garment removal

- **Outcome:** użytkownik może usunąć garment ze swojej szafy (`DELETE /api/garments/{id}` — soft-delete przez `deleted_at` lub hard-delete per F-02 decision)
- **Change ID:** garment-removal
- **PRD refs:** FR-005
- **Prerequisites:** F-01, F-02, S-01
- **Parallel with:** S-02, S-04
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Minimalne. Jeśli wybrano hard-delete: zdjęcie na S3 musi być usunięte w tej samej transakcji (lub job kolejki) — nie zostawiać osieroconych plików.
- **Status:** proposed

## Backlog Handoff

| Roadmap ID | Change ID          | Suggested issue title                                        | Ready for `/10x-plan` | Notes                                        |
| ---------- | ------------------ | ------------------------------------------------------------ | --------------------- | -------------------------------------------- |
| F-01       | auth-scaffold      | Install Sanctum + configure API token auth                   | yes                   | Run `/10x-plan auth-scaffold`                |
| F-02       | garment-schema     | Add garments migration (listing card fields + soft delete)   | yes                   | Run `/10x-plan garment-schema` (parallel with F-01) |
| F-03       | ci-cd-pipeline     | Add GitHub Actions deploy workflow (test + dev + prod)       | yes                   | Run `/10x-plan ci-cd-pipeline` (parallel with F-01, F-02) |
| S-01       | account-endpoints  | Add register / login / logout / GET user endpoints           | no                    | Needs F-01 first                             |
| S-02       | ai-classification  | POST /api/garments: photo → AI → listing card                | no                    | Needs F-01, F-02, S-01; provider: Gemini 2.0 Flash via OpenRouter |
| S-03       | listing-card-edit  | GET + PATCH /api/garments/{id}: review and edit listing card | no                    | Needs S-02 first                             |
| S-04       | wardrobe-catalogue | GET /api/garments: paginated wardrobe catalogue              | no                    | Needs F-01, F-02, S-01                       |
| S-05       | garment-removal    | DELETE /api/garments/{id}: remove garment                    | no                    | Needs F-01, F-02, S-01                       |

## Open Roadmap Questions

1. ~~**Który dostawca API vision?**~~ ✅ **Resolved 2026-05-26** — Gemini 2.0 Flash via OpenRouter (`google/gemini-2.0-flash`). Klucz w SSM jako `OPENROUTER_API_KEY`. S-02 odblokowane.

2. **Delete vs. soft-archive dla usuwania garmentu (FR-005)** — schemat F-02 zawiera `deleted_at`, więc obie opcje działają bez zmiany migracji. Wybór wpływa na implementację `DELETE` w S-05 i na widoczność w GET /api/garments. Owner: user. Block: no.

3. **Cold-start onboarding dla sugestii stylizacji (FR-010)** — jak zachęcić nowych użytkowników do skatalogowania wystarczającej liczby garmentów zanim rekomendacje staną się wartościowe? Owner: design/user. Block: no (FR-010 is nice-to-have; nie blokuje MVP).

## Parked

- **FR-008: Skanowanie kodu kreskowego** — Why parked: nice-to-have; dotyczy głównie nowych/otagowanych ubrań, a większość kandydatów do odsprzedaży to noszone ubrania bez etykiet. Ograniczony overlap z głównym bólem (PRD §Non-Goals spirit).
- **FR-009: Rozmiary i wymiary ciała** — Why parked: nice-to-have; wartość zablokowana przez brak outfit suggestions w MVP.
- **FR-010: Sugestie stylizacji na podstawie pogody** — Why parked: nice-to-have; cold-start problem (pusta szafa = zero wartości). Open Q3 nierozwiązane.
- **FR-011: Sugestie stylizacji na podstawie nastroju/okazji** — Why parked: nice-to-have; differentiator produktu, ale wymaga wypełnionej szafy i wysokiej jakości rekomendacji.
- **FR-012: Wizualizacja na awatarze** — Why parked: nice-to-have; wymaga bardzo wysokiej dokładności wizualizacji przed shipmentem — niska dokładność niszczy zaufanie szybciej niż brak funkcji (PRD §Nice-to-have guardrail).
- **FR-013: Proaktywne sugestie zakupowe** — Why parked: nice-to-have; jakość rekomendacji musi poprzedzać wolumen (PRD §Nice-to-have guardrail).

## Done

<!-- /10x-archive appends entries here when a change matching a Change ID above is archived. Do NOT pre-populate. -->

- **F-01 auth-scaffold** — done 2026-05-27. Sanctum installed, `HasApiTokens` on User, `personal_access_tokens` migration, `auth:sanctum` guard, SanctumSmokeTest (3 tests). Commits: `5f2f0d8`, `7296025`, `fd7a94e`.
- **F-03 ci-cd-pipeline** — done 2026-05-31. `.github/workflows/deploy.yml` with test + deploy-dev (PR) + deploy-prod (merge to main); Composer + npm caching; Node 22; migrations after each deploy; `SERVERLESS_ACCESS_KEY` for SFv4 CI auth. Commits: `accd8b7`, `9a99e89`, `bfbf6b8`, `ac40c88`.
