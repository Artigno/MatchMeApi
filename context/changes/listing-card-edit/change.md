---
change_id: listing-card-edit
title: GET + PATCH /api/garments/{id} — review and edit listing card (S-03)
status: impl_reviewed
created: 2026-06-01
updated: 2026-06-01
archived_at: null
---

## Notes

S-03 z roadmap.md. GET /api/garments/{id} zwraca kartę ogłoszenia (9 pól). PATCH /api/garments/{id} częściowo aktualizuje pola klasyfikacji — klucz obecny w body nadpisuje (null czyści), klucz nieobecny nie jest dotykany.
