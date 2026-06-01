---
change_id: ai-classification
title: POST /api/garments — photo upload → AI classification → save garment (S-02)
status: implemented
created: 2026-06-01
updated: 2026-06-01
archived_at: null
---

## Notes

S-02 z roadmap.md — North Star. POST /api/garments przyjmuje zdjęcie, klasyfikuje przez Gemini 2.0 Flash (via OpenRouter), zapisuje Garment z wynikami, zwraca pełny resource. Null dla pól niepewnych (lessons.md: nigdy plausible-but-wrong).
