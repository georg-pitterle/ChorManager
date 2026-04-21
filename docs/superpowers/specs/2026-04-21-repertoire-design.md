# Repertoire Redesign

Date: 2026-04-21
Status: Approved for planning
Scope: Replace direct song-to-project assignment with a global repertoire and project song usage model

## 1. Goal

Redesign the song library so songs are managed centrally in a reusable repertoire instead of being owned by a single project.

Required outcomes:
- Introduce a global repertoire for all songs.
- Allow a song to belong to multiple categories at the same time.
- Allow the same song to be assigned to multiple projects.
- Store a project-specific note on each song-to-project assignment.
- Keep attachments global on the song itself, not duplicated per project.
- Keep the downloads area project-oriented for members.
- Replace the current accordion-based library management with a clearly upgraded repertoire UI.

## 2. Chosen Approach

Selected approach: Repertoire central with project usage assignments.

Reason:
- Matches the real domain better than treating the same piece as separate project-owned records.
- Keeps song metadata and attachments canonical and avoids duplication.
- Preserves the current user-facing downloads mental model while fixing the underlying structure.
- Gives room for a stronger management UI centered on finding and curating repertoire, not expanding project accordions.

## 3. Decisions Captured

- Repertoire model: one global repertoire for the whole organization.
- Categories: many-to-many; a song can belong to multiple categories.
- Project usage: many-to-many via an explicit assignment entity.
- Assignment metadata in first phase: project note.
- Attachments: global on the song only.
- Downloads view: still grouped by project for end users.
- UI direction: clearly redesigned management view, not a light touch-up.

## 4. Architecture

### 4.1 Core entities

- Song remains the canonical repertoire record.
- Category is a reusable label-like domain entity.
- SongCategory links songs and categories.
- ProjectSongAssignment links songs and projects and stores assignment-specific note text.
- Attachment remains bound to the song entity_type song.

### 4.2 Responsibility split

- Repertoire management owns song master data, category assignment, and global attachments.
- Project usage management owns whether a project uses a song and what note belongs to that usage.
- Downloads uses project membership plus project-song assignments to determine visibility.

### 4.3 User-facing flow

1. Manager creates or edits a song in the global repertoire.
2. Manager assigns one or more categories to the song.
3. Manager uploads global attachments to the song.
4. Manager assigns the song to one or more projects.
5. Manager adds or edits the note for each project assignment.
6. Members see assigned songs under their projects in downloads.

## 5. Data Model

### 5.1 Songs

Keep songs as the canonical master record, but remove direct project ownership from the functional model.

Target fields retained on song:
- id
- title
- composer
- arranger
- publisher
- created_by_user_id
- created_at

Change:
- project_id is no longer the source of truth for project visibility and should be removed after migration.

### 5.2 Categories

Add a dedicated categories table.

Columns:
- id
- name
- slug or normalized key
- sort_order or equivalent lightweight ordering field

Behavior:
- Category names must be unique.
- Categories are shared across the full repertoire.

### 5.3 Song categories

Add a many-to-many join table for songs and categories.

Columns:
- song_id
- category_id

Behavior:
- Unique constraint on song_id plus category_id.
- A song may have zero to many categories.

### 5.4 Project song assignments

Add a dedicated assignment table between projects and songs.

Columns:
- id
- project_id
- song_id
- note
- created_at
- updated_at if useful for admin visibility

Behavior:
- Unique constraint on project_id plus song_id.
- Assignment note is optional but persisted on the assignment.
- This table becomes the source of truth for whether a song appears inside a project.

### 5.5 Attachments

No new attachment ownership type is required in the first phase.

Behavior:
- Existing song attachments remain global.
- The same attachment can appear in every project where the song is assigned.
- There are no project-specific song attachments in this design.

## 6. UX Design

### 6.1 Primary layout

Replace the current project accordion management page with a repertoire central.

Desktop layout:
- Left pane or top section for repertoire discovery.
- Right pane or main detail region for the selected song.

Mobile layout:
- Stacked flow.
- List first, detail second.
- Clear back or close pattern from detail to list.

### 6.2 Repertoire list

The list view should support fast scanning and quick narrowing.

Contents per row or card:
- song title
- category chips
- attachment count
- project assignment count

Controls:
- free-text search
- category filter
- optional project filter
- create song action

### 6.3 Song detail workspace

The detail area is the main editing surface for one selected song.

Sections:
- song master data
- category assignment
- global attachments
- project assignments

Project assignments section should make these actions obvious:
- assign to project
- remove from project
- edit project note
- see which projects currently use the song

### 6.4 Visual direction

The redesign should feel noticeably more intentional than the current repeated form cards.

Design goals:
- clearer hierarchy
- less vertical sprawl
- stronger grouping of related actions
- visible distinction between global song data and per-project usage
- responsive behavior without hidden critical actions

## 7. Download Behavior

### 7.1 End-user model

Downloads remains grouped by project so members keep the existing navigation pattern.

### 7.2 Data loading rule

Project downloads must load songs through project-song assignments instead of songs.project_id.

Visibility rule:
- user must be a member of the project
- song must be assigned to that project
- song attachments are visible through that assignment path

### 7.3 Presentation impact

- The project accordion in downloads can stay conceptually the same.
- Song cards remain per project.
- Global song metadata is shown as today.
- Project note can be surfaced if useful, but it is not required to block the first delivery.

## 8. Permissions and Security

- Existing song library management permission continues to gate repertoire management.
- Authenticated project members only access downloads for their own projects.
- Download authorization must validate membership through project_users and song visibility through project_song_assignments.
- Duplicate or forged access through direct attachment URLs must fail when there is no valid project assignment path.
- Validation must prevent duplicate project-song assignments.

## 9. Migration Strategy

### 9.1 Data migration

Migrate every existing song into the new model without losing visibility.

Migration rule:
- each existing song record remains a song record
- each existing project_id becomes one project-song assignment
- assignment note starts empty unless a default value is intentionally introduced
- existing attachments remain attached to the same song

### 9.2 Rollout expectation

- No existing member should lose song downloads because of the migration.
- Existing manager workflows should remain functionally possible after the transition, even if the UI is reorganized.

## 10. Error Handling

- Creating a duplicate category name must produce a clear validation error.
- Assigning the same song to the same project twice must be blocked cleanly.
- Removing a category that is still referenced must either be blocked or safely detach references according to final implementation policy.
- Deleting a song with project assignments must remove dependent assignments and song attachments predictably.
- Download requests for song attachments without a valid membership-plus-assignment path must return access denied or not found behavior consistent with current security posture.

## 11. Testing Strategy

Feature tests should cover at least:
- creating and editing global repertoire songs
- assigning multiple categories to a song
- assigning one song to multiple projects
- persisting and updating the assignment note
- showing assigned songs in project downloads
- hiding songs from projects where they are not assigned
- preserving attachment download authorization through the new assignment model
- migrating existing songs into assignments without losing visibility

Additional coverage:
- update dev seed generation so seeded data includes repertoire songs, multiple categories, and project assignments
- update route and controller structure tests that currently assume direct song-to-project ownership

## 12. Non-Goals

- No project-specific song attachments in phase one.
- No advanced repertoire versioning or song history.
- No separate public repertoire browsing for regular members outside the existing downloads area.
- No category-specific permission model.

## 13. Open Implementation Notes For Planning

- Introduce new query boundaries so management and downloads do not rely on legacy eager loading from Project::songs.
- Plan the schema migration so code can move safely from songs.project_id to project_song_assignments.
- Decide whether project note is shown inside downloads in phase one or deferred after the model transition.
- Update dev seed data and feature tests together so the new domain model is exercised by default.
