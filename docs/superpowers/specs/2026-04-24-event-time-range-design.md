# Event Time Range Redesign

Date: 2026-04-24
Status: Approved for planning
Scope: Replace the single event datetime field with an explicit start and end time range across event management, attendance context, and related event displays

## 1. Goal

Introduce explicit start and end times for events so every event is modeled as a real time range instead of a single ambiguous datetime.

Required outcomes:
- Replace the current single event datetime semantics with a clear start and end model.
- Keep event creation and editing strict: date, start time, and end time are required.
- Migrate existing events automatically to default times 19:00 to 21:00.
- Apply the chosen time range consistently to recurring events.
- Show event times in read/display views where users need to understand an event.
- Keep compact event selection lists time-free unless time is needed to disambiguate otherwise identical options.

## 2. Chosen Approach

Selected approach: full event time-range remodel with starts_at and ends_at.

Reason:
- Fixes the data model at the root instead of stretching the meaning of the existing event_date field.
- Makes event semantics explicit everywhere in code, templates, tests, and future features.
- Preserves a simple mental model: sorting and navigation use the start timestamp, while displays can show the full range.
- Avoids carrying a misleading field name that actually stores a start datetime.

## 3. Decisions Captured

- Data model: replace event_date with starts_at and ends_at.
- Both fields are required.
- Validity rule: ends_at must be later than starts_at.
- Existing events are migrated to 19:00 to 21:00 on the same calendar day.
- New and edited events require date, start time, and end time.
- Recurring event generation keeps the same time range on each generated occurrence.
- Series edit with the existing future-series option also updates start and end times for future events in that series.
- Compact event selection lists do not show time by default.
- Compact event selection lists may show time only when needed to disambiguate otherwise identical options.

## 4. Architecture

### 4.1 Core event model

- Event becomes a true interval-based entity.
- starts_at is the canonical start timestamp.
- ends_at is the canonical end timestamp.
- All event ordering and neighbor resolution are anchored on starts_at.

### 4.2 Responsibility split

- Event persistence owns starts_at and ends_at validation and storage.
- Event management flows build timestamps from form date plus start/end time inputs.
- Attendance navigation chooses previous and next events based on starts_at ordering.
- Display templates decide whether to render date only or full time range depending on context.

### 4.3 User-facing flow

1. Manager enters date, start time, end time, type, and other event metadata.
2. The system builds starts_at and ends_at from those inputs.
3. Validation rejects empty or inverted ranges.
4. If recurrence is enabled, each generated occurrence keeps the same start and end times while the date shifts by recurrence rules.
5. If future-series update is enabled during edit, future events in the series inherit the updated time range.

## 5. Data Model

### 5.1 Events

The events table should move from one datetime column to two explicit datetime columns.

Target fields:
- id
- series_id
- title
- location
- project_id
- event_type_id
- starts_at
- ends_at
- type

Change:
- event_date is removed after migration is complete.

Behavior:
- starts_at and ends_at are stored in the app's existing timezone-consistent datetime handling.
- starts_at is the source of truth for sorting, filtering, and event adjacency.
- ends_at is the source of truth for duration display and interval validation.

### 5.2 Model casting

The Event model should expose:
- starts_at => datetime
- ends_at => datetime

The previous event_date cast is removed.

### 5.3 Compatibility rule

There is no long-term compatibility alias in this design.

Expectation:
- affected controllers, templates, and tests are updated to use starts_at and ends_at directly.
- this is an intentional broad but clean refactor of event time semantics.

## 6. UX Design

### 6.1 Event creation and editing

Event create and edit surfaces require these inputs:
- date
- start time
- end time
- event type

Behavior:
- users must not save an event without a full time range.
- users must not save an event whose end is equal to or earlier than its start.
- title fallback behavior remains unchanged if title is still allowed to default from event type.

### 6.2 Recurring events

Recurring event generation keeps the chosen time range stable.

Behavior:
- the recurrence engine moves the calendar date only.
- start and end clock times stay identical for each generated occurrence.
- the existing series update checkbox also applies the changed time range to future occurrences.

### 6.3 Display rules

Display contexts that explain an event should show the time range.

Examples:
- event index rows
- event edit confirmation context
- attendance event header
- any other event detail or overview block where users read event details

Selection contexts stay compact.

Examples:
- attendance dropdowns
- newsletter event selectors
- other compact select boxes that list many events

Rule:
- compact event selectors show date plus title by default.
- time is only added there if two options would otherwise be ambiguous enough to confuse selection.

### 6.4 Sorting and navigation

- Event tables sort by starts_at.
- Previous/next event logic uses starts_at ordering.
- Old event filters use starts_at as the time boundary field.

## 7. Migration Strategy

### 7.1 Schema migration

Add a dedicated Phinx migration that:
- adds starts_at and ends_at as datetime columns
- backfills both fields for all existing rows
- removes event_date only after backfill is complete and code is switched

### 7.2 Data migration

Backfill rule for existing rows:
- starts_at uses the existing event_date calendar date with default time 19:00
- ends_at uses the same calendar date with default time 21:00

This makes the migration deterministic and avoids leaving legacy events partially configured.

### 7.3 Rollout expectation

- no event remains without a valid time range after migration
- existing attendance and event views continue to function after the refactor
- recurring series behavior remains predictable because starts_at stays the recurrence anchor

## 8. Error Handling

- Missing date, start time, or end time returns a clear validation error.
- end time equal to or earlier than start time returns a clear validation error.
- recurring event creation must reject invalid ranges before generating any series rows.
- future-series update must not partially apply invalid time changes.
- if ambiguity handling is needed in compact selectors, the UI should prefer adding time to labels rather than changing the stored model.

## 9. Permissions and Security

- Existing event access rules remain unchanged.
- Time-range introduction must not widen access to project-scoped events.
- Series update and delete behavior continue to respect the current per-event permission checks.
- Input validation must treat date and time form values as untrusted input and reject malformed values.

## 10. Testing Strategy

Feature tests should cover at least:
- creating an event with date, start time, and end time
- rejecting events whose end is not later than start
- editing a single event time range
- applying updated start and end times to future events when series update is selected
- keeping recurring generated occurrences on the same clock-time range
- sorting and filtering events by starts_at
- previous and next attendance navigation based on starts_at
- rendering time ranges in display contexts
- keeping compact event selection lists time-free by default
- showing time in compact selectors only when disambiguation is required
- model cast expectations for starts_at and ends_at
- migration behavior for existing event_date rows to 19:00 to 21:00

## 11. Out of Scope

- Optional all-day events
- midnight-crossing events that end on the next day
- timezone-per-event configuration
- calendar-style duration visualizations
- partial legacy compatibility layer for event_date

## 12. Summary

This design intentionally chooses the cleaner refactor over a smaller patch.

The result is:
- explicit event intervals with starts_at and ends_at
- required start and end times for all events
- deterministic migration of existing events to 19:00 to 21:00
- stable recurring event behavior
- time shown where users need event context, but omitted from compact event pickers unless necessary for disambiguation