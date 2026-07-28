---
change_id: seed-test-wardrobe
title: Artisan command seeding a test account with a fully classified wardrobe (photos + data)
status: implementing
created: 2026-07-06
updated: 2026-07-06
archived_at: null
---

## Notes

Dane testowe do weryfikacji działania aplikacji bez przechodzenia przez `/classify`:
stały fake user + ~12 wypełnionych garmentów (polskie wartości kanoniczne, wszystkie
kategorie i stany, przypadki null, `client_ref`) + odrębne zdjęcia fixture + wydrukowany
token Sanctum do weryfikacji przez API. Wipe & reseed przy każdym uruchomieniu.
