---
change_id: testing-classification-safety-net
title: AI classification safety net — malformed AI output becomes null, never a guess
status: implementing
created: 2026-06-22
updated: 2026-07-01
archived_at: null
---

## Notes

Rollout Phase 1 of `context/foundation/test-plan.md`: "AI classification safety net".
Risks covered: #1 (AI returns plausible-but-wrong instead of null). Test types planned:
unit (service + faked HTTP). Risk response intent: prove malformed/partial/out-of-enum/
wrong-typed AI JSON yields null for the affected field, never a guessed value; oracle =
PRD null-not-wrong rule, not the parser output.
