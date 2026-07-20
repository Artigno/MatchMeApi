---
change_id: testing-authz-abuse-lockdown
title: Authorization & abuse lockdown — test-plan rollout Phase 2
status: implementing
created: 2026-07-16
updated: 2026-07-20
archived_at: null
---

## Notes

Open a change folder for rollout Phase 2 of context/foundation/test-plan.md: "Authorization & abuse lockdown".
Risks covered: #2 IDOR — a user reads/edits/deletes another user's garment (authenticated ≠ owner); #3 cost abuse on the unauthenticated /classify endpoint exhausts AI tokens; #5 a raw client request bypasses the 422 contract (302 redirect) or the server trusts client-supplied values.
Test types planned: integration (Feature tests).
Risk response intent:
- #2: prove every garment route returns 404 for a non-owner and the list endpoint returns only the caller's rows; challenge "logged-in implies allowed"; avoid happy-path-only and 403-where-404-is-contract.
- #3: prove missing/wrong X-App-Key → 403 and requests past per-IP + global caps → 429; challenge "throttle config present means protected"; avoid asserting config exists without exercising a real 429.
- #5: prove a raw request without Accept: application/json on every mutating garment route returns 422 (not 302) and enum/size limits reject server-side; challenge "postJson tests already prove the 422 contract"; avoid Accept-header-only tests.
