# Header And Listhead Refresh Design

Date: 2026-03-28
Status: Draft for review
Scope: Visual refresh only (no backend/data model changes)

## 1. Goal

The current heavy dark bars in the global header and table/list headers feel visually too dense. We will introduce a global "corporate bold" refresh with a hybrid visual language:

- neutral anthracite base
- targeted primary-color accents
- medium intensity change (clear improvement without changing workflows)

The refresh applies globally across all areas.

## 2. Non-Goals

- No route/controller/model/database changes
- No behavior changes for navigation, filtering, sorting, table/card switching, or permissions
- No redesign of page-specific business workflows

## 3. Design Principles

- Keep functionality unchanged, improve visual hierarchy
- Replace black-heavy blocks with layered neutrals and controlled accent highlights
- Maintain consistent language across topbar, table headers, and card headers
- Preserve accessibility and readability on desktop and mobile

## 4. Architecture

The refresh is implemented primarily in global styling.

Primary touchpoint:

- public/css/style.css

Secondary touchpoints only if needed for class harmonization:

- templates/** where inconsistent header classes prevent global styling from applying cleanly

Existing theme variables remain the source of truth. New header/listhead tokens are additive and consume existing primary color variables.

## 5. Components And Tokens

### 5.1 New/Adjusted Tokens

Add or refine global tokens in style.css:

- --header-bg-start
- --header-bg-end
- --header-border-subtle
- --header-accent-line
- --listhead-bg
- --listhead-text
- --listhead-border

Keep existing tokens active:

- --theme-primary
- --theme-primary-strong
- --theme-primary-rgb

### 5.2 Topbar

- Replace hard black look with a neutral anthracite gradient
- Keep subtle depth via shadow/blur, but reduce visual heaviness
- Active nav state becomes a compact accent chip/pill treatment
- Keep toggler behavior and breakpoints unchanged from current functional state

### 5.3 Table/List Headers

- Harmonize table-dark and table-light visual output under one global corporate style
- Increase typographic clarity (weight/letter spacing) without overpowering content rows
- Add subtle structure lines and restrained accent cues
- Avoid full-height dark bars in list heads

### 5.4 Card Headers

- Unify card headers to match the same language as list heads
- Prefer neutral surfaces with clean separators and optional small accent details

## 6. Responsive Behavior

- Keep global responsive breakpoints unchanged
- Reduce visual density on narrow widths (padding/typographic scale)
- Ensure refreshed header/listhead styles do not create horizontal overflow
- Ensure topbar remains stable when page-header action groups wrap

## 7. Data Flow And Error Handling

No runtime data flow change is required.

Potential UI risks and mitigations:

1. Contrast regressions with custom primary colors
- Mitigation: use neutral base tones and reserve primary color for accents

2. Inconsistent template class usage
- Mitigation: apply robust global selectors, add minimal template harmonization only where required

3. Mobile density regressions
- Mitigation: dedicated breakpoint refinements and manual viewport checks

## 8. Testing Strategy

### 8.1 Automated

- Extend/adjust layout feature tests to assert presence of key structural classes/rules
- Keep existing app-setting tests to ensure theme token compatibility

### 8.2 Manual Visual QA

Validate at minimum on:

- /finances
- /users
- /sponsoring
- /downloads

Viewport checks:

- 586px
- 768px
- 992px
- 1200px

Acceptance checks:

- No heavy black bars in header/listhead areas
- Improved hierarchy and readability
- No behavior regressions in menus, tables, and card/table switchers

## 9. Rollout Plan

Phase 1: Global token and topbar restyling

- Introduce/refine header tokens
- Apply topbar visual refresh with hybrid neutral/accent direction

Phase 2: Global listhead/card-header harmonization

- Align table header classes under a shared visual style
- Align card headers to the same system

Phase 3: Final consistency and QA

- Resolve outlier templates with minimal class harmonization
- Run automated checks and manual viewport QA

## 10. Completion Criteria

The design is complete when:

- Header and listhead visuals are globally consistent and lighter than current black-heavy treatment
- Corporate hybrid style is visible and coherent
- Existing feature behavior is unchanged
- Automated checks and manual QA pass for core pages and target breakpoints
