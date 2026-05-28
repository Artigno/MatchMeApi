---
change_id: auth-refresh
title: Token refresh endpoint — access/refresh token pair rotation
status: impl_reviewed
created: 2026-05-27
updated: 2026-05-28
archived_at: null
---

## Notes

Related to F-01 (auth-scaffold). Adds login (issues access+refresh pair) and POST /api/auth/refresh (rotates refresh token → new pair). Ability-gated: refresh token must carry 'refresh' ability; access token is rejected at the refresh endpoint.
