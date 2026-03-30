# Design Spec: Viewport Auto Switching for Table/Card Views

Date: 2026-03-30
Status: Approved for planning
Topic: Dynamic viewport-based switching with explicit Auto mode

## 1. Goal

Implement dynamic switching between card and table view based on viewport size, while only persisting a preference when the user explicitly changes the mode.

Required behavior:
- Add a third button "Auto" next to "Karten" and "Tabelle".
- Auto mode means:
  - mobile viewport -> cards
  - desktop viewport -> table
- In Auto mode, view updates live on viewport resize/orientation changes.
- Preferences are saved only on explicit user interactions.

## 2. Scope

In scope:
- Toolbar UI update with Auto button.
- Table engine mode logic (auto/cards/table).
- Local preference model update to override-only behavior.
- Backward-compatible read path for older stored preference values.
- Tests for mode behavior and persistence semantics.

Out of scope:
- Redesign of table card visuals.
- Changes to unrelated data-table templates.
- Server-side persistence.

## 3. Existing Context

Current implementation:
- Toolbar has only "Karten" and "Tabelle".
- table-engine writes preference on initialization and whenever view changes.
- Initial selection mixes stored preference and viewport/default view logic.

Key files:
- templates/partials/table_toolbar.twig
- public/js/table-engine.js
- public/js/table-preferences.js
- public/css/table-engine.css

## 4. Chosen Approach

Chosen option: Override-only persistence.

Principle:
- Default mode is Auto when no user override is set.
- Store preference only when user clicks Karten or Tabelle.
- Clicking Auto removes any override and returns to dynamic behavior.

Why this approach:
- Exactly matches requirement "save only when user actively changes view".
- Minimal state and low complexity.
- Works well with existing localStorage abstraction.

## 5. UX and Interaction Design

Toolbar:
- Three buttons in one group:
  - Auto
  - Karten
  - Tabelle

Mode semantics:
- Auto button active when no override exists.
- Karten active when override is cards.
- Tabelle active when override is table.

Effective rendered view:
- Auto mode:
  - mobile <= 767.98px -> cards
  - desktop > 767.98px -> table
- Override mode:
  - always show selected override view regardless of viewport changes.

Accessibility:
- Buttons should set aria-pressed=true/false according to active mode.
- Existing aria-label for group remains.

## 6. Technical Design

### 6.1 State model

Per table container instance:
- mode: one of auto, cards, table
- effectiveView: one of cards, table

Derivation:
- if mode is auto -> effectiveView = viewport-based
- else effectiveView = mode

### 6.2 Preference model

Storage key remains: chor.table.<tableId>

Persisted shape (new):
- viewOverride: "cards" | "table" (optional)

Meaning:
- viewOverride absent -> Auto mode
- viewOverride present -> explicit user override

Backward compatibility:
- If legacy prefs.view exists and viewOverride is absent:
  - interpret prefs.view as override at read time
  - do not write prefs.view anymore

### 6.3 Table engine behavior

Initialization:
- Read prefs.
- Resolve mode from viewOverride (or legacy view fallback).
- Apply effective view.
- Do not persist anything during init.

User click behavior:
- Karten:
  - mode = cards
  - apply cards
  - persist viewOverride=cards
- Tabelle:
  - mode = table
  - apply table
  - persist viewOverride=table
- Auto:
  - mode = auto
  - compute and apply viewport-based view
  - remove viewOverride from prefs and persist

Resize behavior:
- Register viewport listener.
- If mode is auto, recompute and apply effective view live.
- If mode is override, ignore resize for view switching.

### 6.4 CSS impact

No structural CSS rewrite required.
- Existing rules keyed by [data-active-view="cards"] remain valid.
- Table mode continues as implicit non-cards behavior.

Optional minor styling:
- Ensure active button style is clearly visible for Auto too (same active class behavior as existing mode buttons).

## 7. Error Handling and Edge Cases

- localStorage unavailable or blocked:
  - read returns empty object
  - write silently no-op
  - UI still functional in-memory
- Missing tableId:
  - keep current fallback behavior
- Multiple table instances:
  - each uses independent tableId preference key
- Legacy preference values:
  - mapped safely to new override model without migration step

## 8. Testing Strategy

### 8.1 Frontend behavior tests

Add/update tests to cover:
- Initial auto behavior on mobile -> cards
- Initial auto behavior on desktop -> table
- Click Karten stores override and locks view
- Click Tabelle stores override and locks view
- Click Auto removes override and re-enables dynamic mode
- Resize in Auto mode switches view live
- Resize in override mode does not switch view
- Existing search/filter behavior remains unaffected

### 8.2 Template-level check

Assert toolbar includes Auto button in addition to Karten/Tabelle.

## 9. Risks and Mitigations

Risk: Legacy stored prefs create inconsistent state.
Mitigation: Single read-path compatibility handling with precedence rules.

Risk: Resize event storms causing excessive DOM updates.
Mitigation: Only apply when computed effective view actually changes.

Risk: User confusion between Auto and explicit modes.
Mitigation: Clear active-state highlight and consistent behavior.

## 10. Acceptance Criteria

- Toolbar contains Auto, Karten, Tabelle.
- No preference is written on initial load.
- Preference is written only after user clicks a mode button.
- Auto mode dynamically switches on viewport changes.
- Karten/Tabelle modes stay fixed across viewport changes.
- Clicking Auto clears override and resumes dynamic switching.
- Existing table search behavior remains unchanged.
- Relevant tests pass.
