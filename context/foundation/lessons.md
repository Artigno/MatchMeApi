# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Nie generuj wartości plausible-but-wrong zamiast null

- **Context**: Cały projekt (wszystkie fazy)
- **Problem**: Agent generuje błędną wartość zamiast null
- **Rule**: Nigdy nie generuj wartości plausible-but-wrong. Gdy brak pewności co do pola — zwróć null, nie zgaduj.
- **Applies to**: all
