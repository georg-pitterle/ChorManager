# Table Modernization Design

## Goal

Modernize all table-heavy areas with a mobile-first experience and stronger interaction capabilities. The target includes hybrid mobile behavior, consistent controls, and selected domain logic improvements where they measurably increase productivity.

Primary user goals:
- Better mobile usability.
- Full rollout across all table areas.
- Stronger interaction features (column control, persistent preferences, bulk workflows).

## Scope

In scope:
- All table-heavy templates across members, evaluations, finance, sponsoring, songs, downloads, and related management views.
- Unified table interaction model for desktop and mobile.
- Domain logic changes when they improve workflow speed, reliability, or reproducibility.
- URL and state conventions for shareable/reload-safe table views.

Out of scope:
- Replacing Slim or Twig rendering architecture.
- Broad unrelated refactors outside table workflows.

## Architecture

### 1) Grid-based Table Engine

Adopt a third-party grid foundation behind an internal adapter layer so templates do not depend directly on vendor APIs.

Components:
- Table Adapter Layer (JavaScript):
  - Reads configuration from data attributes in Twig templates.
  - Instantiates and wires grid features consistently.
  - Exposes normalized hooks for filters, sorting, paging, and row actions.
- Shared Table Toolbar (Twig partial + JavaScript):
  - Search, column visibility, density, view switch, and bulk action entry points.
- Mobile Hybrid View Controller (JavaScript + CSS):
  - Mobile defaults to card view.
  - User can switch to scrollable table view.
  - Choice is persisted per table.
- State Persistence Layer (JavaScript):
  - Stores per-table preferences (view mode, visible columns, density, sorting, filters).
  - Uses localStorage with namespaced keys.

### 2) Progressive Enhancement Contract

- Baseline without JavaScript must remain usable with existing rendered table markup.
- If grid initialization fails, runtime fallback keeps the plain table path active.
- Critical actions remain server-authoritative and do not depend solely on client logic.

### 3) Data and Query Contract

Introduce a unified query parameter scheme for all table pages:
- page, per_page
- sort, dir
- q (text query)
- filters[...] (domain-specific filter fields)
- cols (optional visible column set)
- view (cards or table)

Benefits:
- Predictable links and browser reload behavior.
- Easier support and debugging.
- Reusable controller/query handling patterns.

## Interaction Design

### Desktop

- Dense but readable tabular layout.
- Sticky header for long lists.
- Column selector with defaults and reset.
- Bulk selection with scoped action bar.

### Mobile

- Default card view with compact row summaries.
- Toggle to table mode for users preferring strict columns.
- Per-table persisted view preference.
- Action menu remains reachable with touch-first targets.

### Bulk and Quick Actions

- Add domain-specific bulk actions only where useful.
- Show actions only for allowed roles and valid selection states.
- Return detailed result summaries for partial success scenarios.

## Domain Logic Changes Allowed

Domain changes are allowed if they directly improve table workflows.

Examples:
- Unified server-side filtering and sorting for large datasets.
- Bulk endpoints for repetitive operations.
- Consistent response format for multi-item operations.
- Linkable table state via query parameters.

Guardrails:
- Keep business rules centralized and validated server-side.
- No speculative model changes without workflow impact.

## Error Handling

- Init failure: fallback to plain table behavior with non-blocking warning logging.
- Network or server failure for table operations:
  - Keep current view state where safe.
  - Show actionable error notices.
- Bulk actions:
  - Return per-item outcome list.
  - Surface partial success and failed records explicitly.
- Persistence unavailable:
  - Continue without local preference storage.

## Security and Permissions

- Enforce authorization on every affected endpoint, especially bulk actions.
- Validate all filter/sort inputs against allowlists.
- Reject invalid column references and unsupported sort fields.
- Prevent unsafe mass actions through explicit operation scoping and CSRF protection.

## Rollout Plan (Design Level)

### Wave A: Foundation
- Add adapter, toolbar, mobile hybrid controller, and persistence layer.
- Extend shared CSS for table states and toolbar patterns.

### Wave B: Core Pilot
- Apply to members management and evaluations first.
- Validate UX, performance, and fallback behavior.

### Wave C: Full Expansion
- Roll out to finance, sponsoring, songs, downloads, and remaining table pages.
- Keep behavior contract consistent across domains.

### Wave D: Logic Enhancements
- Add selected bulk workflows and query contract unification.
- Harden server-side validation and response consistency.

### Wave E: Stabilization
- Full regression pass, responsive QA, and performance checks.
- Final consistency sweep of table controls and labels.

## Testing Strategy

Automated:
- Extend and add feature tests for each table domain.
- Add endpoint tests for bulk operations and query contract validation.
- Run full feature suite, full test suite, and coding standards checks.

Manual:
- Responsive checks for mobile cards/table toggle.
- Accessibility checks for keyboard and focus flow on controls.
- High-volume dataset checks for usability and perceived performance.

Required validation gates:
- ddev exec php vendor/bin/phpunit tests/Feature
- ddev exec php vendor/bin/phpunit
- ddev composer phpcs

## Risks and Mitigations

- Vendor lock-in risk:
  - Mitigate through adapter abstraction and minimal template coupling.
- Migration inconsistency risk:
  - Mitigate with shared toolbar conventions and rollout checklist.
- Performance regression risk:
  - Mitigate with server-first filtering/sorting and scoped rendering.
- Permission leakage risk in bulk operations:
  - Mitigate with strict server checks per record.

## Acceptance Criteria

- All targeted table areas support the shared interaction model.
- Mobile defaults to card mode with reliable table-mode toggle.
- Column visibility and view preferences persist per table.
- New bulk workflows (where introduced) are authorized, validated, and test-covered.
- Progressive enhancement and fallback behavior remain functional.
- Validation gates pass with no blocking issues.
