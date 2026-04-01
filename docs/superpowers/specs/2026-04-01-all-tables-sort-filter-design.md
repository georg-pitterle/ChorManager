# Design: All Tables Sortable and Filterable (Client-Side Hybrid)

Date: 2026-04-01  
Status: Approved design draft for implementation planning

## 1. Context

The project already has a shared table foundation:

- Shared toolbar partial with global search and view mode controls.
- Shared table engine for search and responsive table/cards switching.
- Multiple pages using `data-table-engine="true"`.

The new goal is to make all table-engine tables sortable and filterable in a consistent way, while staying fully client-side.

## 2. Goals

1. All `data-table-engine` tables support:
- Global text search (existing behavior retained)
- Per-column sorting
- Additional column-specific filters where useful
 - Client-side pagination
2. State persistence per table (`localStorage`) for:
- Search query
- Sort key and direction
- Plugin filter state
 - Current page and selected page size
3. Add a clear reset action per table to restore defaults and clear persisted state.
4. Keep behavior consistent in both table and card view.
5. Avoid backend or schema changes for this feature.
6. Default page size is 100 rows for all table-engine tables (unless explicitly overridden per table).

## 3. Non-Goals

1. No server-side sorting or filtering.
2. No unrelated UI refactoring outside table interactions.

## 4. Chosen Approach

Chosen option: Hybrid frontend architecture.

1. A common table core provides generic capabilities for all tables.
2. Optional page/domain plugins provide specialized column filters.
3. Shared toolbar gets a reset action and plugin filter slot.

This balances consistency and flexibility without duplicating logic per page.

## 5. Architecture

### 5.1 Table Core (shared)

Primary responsibilities:

1. Manage table state lifecycle (initialize, apply, persist, reset).
2. Apply filtering pipeline:
- Step 1: Global search predicate.
- Step 2: Plugin predicates (AND-combined).
3. Apply sorting on filtered rows:
- Header-driven sort toggle (`asc`/`desc`).
- Typed compare (`text`, `number`, `date`) with deterministic fallback.
4. Apply pagination on the filtered and sorted result set.
5. Re-render row order/visibility in DOM.
6. Broadcast hooks for plugin integration.

### 5.2 Plugins (optional per table)

Primary responsibilities:

1. Mount controls in toolbar plugin slot.
2. Maintain plugin-local state.
3. Provide row predicates to the core.
4. Implement plugin reset behavior.

Plugins must not implement their own persistence engine or row ordering. They use core API only.

### 5.3 Toolbar Layer

The shared toolbar partial is extended with:

1. A dedicated container for plugin controls.
2. A `Zuruecksetzen` button bound to table reset behavior.
3. Pagination controls (page indicator, previous/next, page-size selector).

## 6. Component and API Design

### 6.1 Table State Model

Per `tableId`, persisted object shape:

```json
{
  "searchQuery": "",
  "sortKey": "",
  "sortDir": "asc",
  "pluginFilters": {},
  "page": 1,
  "pageSize": 100
}
```

Defaults are resolved from DOM attributes and existing behavior.

### 6.2 Data Attributes

Container-level attributes:

1. `data-table-engine="true"` (existing)
2. `data-table-id="..."` (existing)
3. `data-default-sort-key="..."` (new, optional)
4. `data-default-sort-dir="asc|desc"` (new, optional)
5. `data-table-plugins="pluginA,pluginB"` (new, optional)
6. `data-default-page-size="100"` (new, optional, default 100)
7. `data-page-size-options="25,50,100,200"` (new, optional)

Header-level attributes:

1. `data-sort-key="..."` marks a sortable column.
2. `data-sort-type="text|number|date"` selects compare strategy.

Cell-level optional attribute:

1. `data-sort-value="..."` overrides displayed text for sorting.

Toolbar attributes:

1. Existing search input remains `data-table-search`.
2. New plugin host: `data-table-plugin-slot`.
3. New reset control: `data-table-reset`.
4. New pagination container: `data-table-pagination`.
5. New page-size selector: `data-table-page-size`.
6. New previous/next controls: `data-table-page-prev` and `data-table-page-next`.
7. New page label output: `data-table-page-label`.

### 6.3 Plugin Contract

A plugin registry/factory contract is introduced:

1. `registerFilterPlugin(name, factory)`
2. Factory returns object with:
- `mount(context)`
- `getPredicate()`
- `getState()`
- `setState(state)`
- `reset()`

`context` provides table references, utility functions, and state update callbacks.

## 7. Data Flow

1. On DOM ready, core discovers all table-engine containers.
2. Core loads persisted state by table ID.
3. Core resolves defaults and sanitizes invalid values.
4. Core mounts configured plugins and restores plugin state.
5. Any input change (search, header click, plugin control):
- Update state
- Apply filter pipeline
- Apply sorting
- Recalculate total pages and clamp current page
- Apply pagination slice
- Update DOM visibility/order
- Persist state
6. Reset action:
- Clear persisted state for this table
- Reset core and plugin controls to defaults
- Reset page to 1 and page size to default (100 unless overridden)
- Re-run rendering pipeline

## 8. Error Handling and Edge Cases

1. Unknown/removed sort keys in persisted state:
- Ignore and fall back to default sort.
2. Invalid `sortDir` values:
- Normalize to `asc`.
3. Missing plugin names:
- Ignore plugin with no runtime failure.
4. Local storage unavailable:
- Continue without persistence.
5. Mixed content cells (icons, badges, line breaks):
- Normalize text content; use `data-sort-value` where precision is required.
6. Action/checkbox columns:
- Not sortable unless explicitly marked sortable.
7. Card/table view switch:
- Sort/filter state remains consistent because the same row dataset is used.
8. Page out of range after filtering (for example due to narrower result set):
- Current page is clamped to the last available page.

## 9. Initial Plugin Scope

The first specialized plugin target is `users.manage`:

1. Role filter (select)
2. Voice filter (select)
3. Project filter (select)

Option lists are derived from visible table data to avoid backend coupling.

Other tables can start with core-only behavior and receive plugins only when clear UX value exists.

## 10. Testing Strategy

### 10.1 Feature/File Presence Tests

Extend table UX tests to assert:

1. Toolbar contains plugin slot and reset control.
2. Required JS/CSS assets are still referenced.

### 10.2 Behavioral JS Tests

Add tests for:

1. Sort toggle behavior per sortable column.
2. Combined global search + plugin filter behavior.
3. Persistence load/store for sort and filters.
4. Pagination behavior including default page size 100.
5. Reset clears state and restores defaults (including page and page size).
6. Invalid persisted state fallback behavior.

### 10.3 Regression Targets

1. Existing auto/cards/table mode switching remains intact.
2. Existing users bulk-selection behavior remains intact.
3. Tables without plugins still work with core search/sort/reset.
4. Tables without plugins still work with core search/sort/pagination/reset.

## 11. Acceptance Criteria

1. Every `data-table-engine` table supports client-side sorting on marked columns.
2. Every `data-table-engine` table supports global search, pagination, and reset.
3. Configured plugin filters apply correctly and persist per table.
4. Refreshing the page restores table state from persistence.
5. Default page size is 100 unless overridden by table configuration.
6. Reset clears persisted state and returns the table to defaults.
7. Existing table/card responsiveness and current interactions are not regressed.

## 12. Implementation Boundaries

1. No database migration required.
2. No backend API endpoint required.
3. Use existing shared assets and conventions.
4. Keep template changes declarative via data attributes and shared partial usage.
