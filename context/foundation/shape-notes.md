---
project: MirrorMatch
context_type: greenfield
created: 2026-05-20
updated: 2026-05-20
checkpoint:
  current_phase: 8
  phases_completed: [1, 2, 3, 4, 5, 6, 7]
  gray_areas_resolved:
    - topic: "primary pain"
      decision: "resale friction — listing clothes on Vinted/OLX is too tedious"
    - topic: "primary persona"
      decision: "fashion-interested women 20-35, own many clothes, occasionally resell"
    - topic: "insight"
      decision: "AI vision can now extract structured data from a photo cheaply; no tool currently cross-posts to Vinted+OLX+FB at once"
  frs_drafted: 13
  quality_check_status: accepted
---

<!-- shape-notes.md — working notes for /10x-prd. Not a PRD. -->

## Vision & Problem Statement

Listing a garment for resale on Vinted, OLX, or Facebook Marketplace takes 5–15 minutes of manual work per item: photographing it, writing a description from scratch, picking a category, choosing a price, and repeating the entire process per platform. Fashion-interested women (20–35) who own large wardrobes and occasionally resell give up mid-process and keep clothes they'd rather sell — not because they don't want to, but because the work cost per item is too high.

The insight that makes this worth building now: AI vision can extract brand, color, category, and preliminary size from a photo in seconds — data extraction that was too expensive or inaccurate three years ago is now commodity. And no tool currently cross-posts a single listing to Vinted + OLX + FB Marketplace at once; that integration gap is unoccupied.

## User & Persona

**Primary persona: The Occasional Reseller**

A woman, 20–35, fashion-interested, who has accumulated a large wardrobe over time. She shops regularly (online and in-store) and periodically wants to clear out clothes she no longer wears. She knows Vinted and OLX exist and has sold a few things before, but finds the per-item listing effort too high to do it systematically. She has a smartphone, takes plenty of photos already, and is comfortable with apps.

She reaches for this product when she's sorted a pile of clothes she wants to sell and dreads the hour of manual work ahead of her.

Secondary persona (noted, not MVP priority): active high-volume resellers who list 20+ items per week and need bulk automation — out of MVP scope.

## Access Control

Two-state model: **guest → account**.

- **Guest (unauthenticated)**: user can open the app and use core wardrobe features (add/browse garments) without creating an account. Data stored locally on-device only.
- **Account (email + password or OAuth)**: user creates or signs into an account. Wardrobe data syncs to cloud, enabling multi-device access. Account required for resale automation (app holds Vinted/OLX OAuth tokens on the user's behalf).

Flat single-user model — no roles, no sharing, no admin panel in MVP. One account = one wardrobe.

## Success Criteria

### Primary
A user can photograph a garment, review an auto-filled listing card (category, brand, color, condition, description generated from the photo), and export it ready to paste on Vinted — without typing a single field manually. This flow works for any common clothing item within 30 seconds of tapping "Add".

MVP scope decision: no Vinted API posting in MVP — the app generates the listing card and the user posts manually (copy to clipboard / open Vinted). The AI extraction value is proved independently of platform integration risk.

### Secondary
User can scan a store barcode on a new (tagged) garment and have the app retrieve item name, brand, and category automatically — faster than photographing for new items.

### Guardrails
1. If the AI cannot confidently determine brand, color, or category from a photo, it shows an empty field for the user to fill — never shows a confidently wrong value. Trust over automation.
2. In guest mode, garment photos and data never leave the device without the user explicitly creating an account. Guest mode is truly local.
3. A listing card in review/edit state is preserved across app backgrounding and navigation — the user's work is never silently discarded.

## Functional Requirements

### Garment Management
- FR-001: User can add a garment by taking or uploading a photo. Priority: must-have
  > Socrates: Counter-argument considered: "some items are impossible to photograph well (hanging vs flat-lay matters)." Resolution: kept; photo quality guidance is a UX concern to solve (instructions, tips), not a counter-argument to photo-first. Manual text-only add is V2.

- FR-002: User can receive an auto-filled listing card (category, brand, color, condition, description) generated from the garment photo. Priority: must-have
  > Socrates: Counter-argument considered: "brand recognition from photo is unreliable for non-logo items — brand field may often be blank." Resolution: kept; category + color + description auto-fill provides value even when brand is blank. The guardrail (empty not wrong) covers this. Partial automation > no automation.

- FR-003: User can review and edit any field on the listing card before exporting. Priority: must-have
  > Socrates: Counter-argument considered: none — edit is the safety valve that makes auto-fill trustworthy. Skipping review would destroy trust. FR stands.

- FR-004: User can browse all garments in their wardrobe catalogue. Priority: must-have
  > Socrates: Counter-argument considered: none — browsing own wardrobe is the retention hook. Without catalogue, the app is a one-shot listing tool. FR stands.

- FR-005: User can remove a garment from their wardrobe. Priority: must-have
  > Socrates: Counter-argument considered: "soft-archive (hide but keep) is safer than hard delete — users delete by mistake." Resolution: kept; the product-level capability (remove from visible wardrobe) stands. Whether this is implemented as hard delete or soft-archive is a downstream implementation decision. FR stands; archive vs delete is noted for implementation planning.

### Resale Export
- FR-006: User can copy a completed listing card to clipboard or open a resale platform in the browser with listing data pre-populated. Priority: must-have
  > Socrates: Counter-argument considered: none — clipboard/browser-open is MVP-appropriate given the deliberate decision to avoid Vinted API integration in v1. FR stands.

### Account & Sync
- FR-007: User can create an account or sign in to sync wardrobe to the cloud and connect resale platform accounts. Priority: must-have
  > Socrates: Counter-argument considered: "account adds complexity; could stay guest-only in MVP." Resolution: kept — account is load-bearing for retention. Without it, one app reinstall erases the entire wardrobe. FR stands as must-have.

### Nice-to-have
- FR-008: User can scan a store barcode to auto-populate garment name, brand, and category. Priority: nice-to-have
  > Socrates: Counter-argument considered: "barcodes only help with new/tagged items; most resale candidates are worn items without tags — limited overlap with core pain." Resolution: acknowledged; the feature targets a real but secondary moment (cataloguing new purchases before wearing them). Nice-to-have stands with noted scope limitation.

- FR-009: User can save their clothing sizes and body measurements to a personal profile. Priority: nice-to-have
  > Socrates: Counter-argument considered: none — body measurements are table-stakes for a smart wardrobe app even without outfit suggestions. Nice-to-have stands.

- FR-010: User can receive outfit suggestions from their wardrobe based on current weather. Priority: nice-to-have
  > Socrates: Counter-argument considered: "outfit suggestion requires a well-catalogued wardrobe — new users have zero catalogued garments, so this feature has zero value at install (cold-start problem)." Resolution: acknowledged; cold-start is a real risk. Onboarding must incentivize cataloguing before outfit suggestions surface. Nice-to-have stands; cold-start mitigation is an Open Question for implementation.

- FR-011: User can describe their emotional state or occasion in a sentence and receive an outfit suggestion from their wardrobe. Priority: nice-to-have
  > Socrates: Counter-argument considered: "this is the feature that differentiates from every other wardrobe app — no one does mood → outfit well." Resolution: this is a differentiator framing, not a counter-argument against inclusion. Execution quality (suggestion relevance) is the risk. Nice-to-have stands; suggestion quality is a key design concern.

- FR-012: User can visualize how an outfit looks on a virtual avatar. Priority: nice-to-have
  > Socrates: Counter-argument considered: "avatar body shape won't match the user's — visualization misleads more than it helps." Resolution: kept as nice-to-have but the accuracy risk is real. Poor fit visualization erodes trust. This feature requires high visual accuracy to be worth shipping. Nice-to-have stands; accuracy bar is a hard gate before shipping.

- FR-013: User can receive proactive shopping suggestions based on wardrobe gaps and promotions matching their size. Priority: nice-to-have
  > Socrates: Counter-argument considered: "proactive suggestions that aren't relevant feel like spam — users disable notifications immediately." Resolution: kept; suggestion quality must precede volume. Nice-to-have stands; quality-before-quantity is a hard constraint on this feature's rollout.

## User Stories

### US-01: Resell a garment without manual typing

- **Given** a user has a garment they want to sell
- **When** they tap "Add" and photograph the garment
- **Then** the app generates a filled-out listing card (category, brand, color, condition, description) that the user can review, edit, and copy — ready to paste on Vinted — without typing any field manually

#### Acceptance Criteria
- All five fields (category, brand, color, condition, description) are populated or explicitly empty (never silently wrong) within 30 seconds of photo capture
- User can edit any field before exporting
- "Copy to clipboard" and "Open Vinted" actions are both available on the completed card

## Business Logic

MirrorMatch applies two domain rules to the same wardrobe catalogue: given a garment photo, it classifies the item and generates a ready-to-publish resale listing description; given a user-provided context (weather, mood, or occasion), it selects and suggests an outfit from the catalogued wardrobe.

**Classification rule** (resale flow): The app takes a user-supplied garment photo as its primary input and outputs a structured listing card — category, brand, color, condition, and a prose description. The user encounters this rule immediately after photographing a garment: the card appears pre-filled and ready for review. The rule applies a confidence threshold: any field the app cannot determine with high confidence is left blank rather than populated with a plausible-but-wrong value.

**Recommendation rule** (outfit flow): The app takes user-provided context — current weather (obtained externally), a mood or occasion described in a sentence or selected from a survey — as its input and outputs an outfit selection from the items currently in the user's wardrobe catalogue. The user encounters this rule when they open the "What should I wear?" flow. The rule has no value until the user has catalogued garments; cold-start (empty wardrobe) is a known limitation.

The wardrobe catalogue is the shared asset both rules operate on. Both rules degrade gracefully without it (classification produces a listing card that isn't stored; recommendation refuses to run on an empty catalogue rather than suggesting nothing meaningful).

## Non-Functional Requirements

- The time from photo capture to a fully populated listing card is short enough that a user does not need to navigate away; if the operation takes longer than two seconds, the user sees continuous visible progress — not a frozen screen.
- The AI classification never outputs a value in any field when its confidence is below an internal threshold; empty fields are preferable to plausible-but-wrong values. A user who sees a populated field can trust it.
- In guest mode, no garment photo, garment data, or personal information leaves the device. No server contact occurs without an explicit user action (account creation or explicit sync trigger).

## Product Framing
product_type: mobile
target_scale:
  users: small
timeline_budget:
  mvp_weeks: 3
  hard_deadline: null
  after_hours_only: true

## Non-Goals

- **No direct API posting to Vinted/OLX/FB Marketplace in MVP.** The app generates a ready-to-paste listing card; the user posts manually. Platform API integration is V2 — the AI extraction value is proved independently of integration risk.
- **No social features.** Single-user experience only. No public profiles, outfit sharing, community feed, likes, or social graph in MVP or V2 roadmap unless explicitly added.
- **No bulk import from camera roll.** One garment at a time. Batch AI classification of an entire existing wardrobe from the photo library is V2.
- **No real-time resale price lookup.** The listing card suggests a description and condition; it does not fetch live market prices from Vinted or comparable platforms.

## Quality cross-check (2026-05-20)

All 5 greenfield checks passed. No gaps recorded. `quality_check_status: accepted`

| Check | Result |
|---|---|
| Access Control | present — guest → account, flat single-user |
| Business Logic (one-sentence rule) | present — two-rule model captured |
| Project artifacts | present — valid frontmatter checkpoint |
| Timeline-cost acknowledged | present — mvp_weeks: 3 (≤ 3, no ack block needed) |
| Non-Goals | present — 4 entries |
