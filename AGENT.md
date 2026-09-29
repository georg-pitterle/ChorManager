# ChorManager Agent Rules

## Scope
These rules are for AI coding agents working in this repository.
You are a professional software engineer.

## Environment
@instructions/ddev-workflow.md

## Git
@instructions/git-push-guard.md

## Line Endings
@instructions/line-endings.md

## Database
- Schemaänderungen laufen ausschließlich über Phinx-Migrationen, nie direkt an der
  Datenbank. Ablauf, Absicherung destruktiver Schritte und die Regel, dass jede
  Phinx-Kette mit `create()`/`save()`/`update()` endet: `/phinx-migration`.

## Seed Data Requirement
- Zu jedem neu persistierten Feature gehören Seed-Daten; ohne sie ist die Arbeit nicht
  fertig. Vollständige Checkliste: `/dev-seed-completeness`.

## Feature Tests
- Jedes neue Feature braucht automatisierte Tests, geschrieben vor der Umsetzung (TDD).
- Abgedeckt werden der Hauptweg und die relevanten Fehler- und Randfälle.
- Vor dem Abschluss laufen die betroffenen Tests, und ihr Ergebnis wird berichtet.
- Welcher Testbefehl wann, gegen welche Datenbank, und was ein paralleler Rotlauf
  bedeutet: `/test-runs`.

## Naming
@instructions/naming.md

## Code and Style
@instructions/php-style.md

@instructions/twig-style.md

@instructions/template-hygiene.md

## Security Baseline
@instructions/security-baseline.md

## Logging Standard
@instructions/logging.md

## Reporting
@instructions/change-reporting.md

## Rotierender Code-Review
- Welcher Abschnitt an der Reihe ist, entscheidet der Zähler in
  `.claude/rotating-review-state.json`, fortgeschrieben ausschließlich über
  `bin/rotating_review_state.php`. Ablauf: `/rotating-review`.

## Hilfetexte
- In `docs/*.md` nie auf konkrete Rollennamen verweisen — Rollen sind pro Installation
  frei konfigurierbar. Stattdessen das tatsächliche Recht mit seinem Label aus
  `templates/roles/index.twig` nennen, und bei fehlenden Rechten generisch auf den
  Administrator verweisen. Vollständiger Ablauf: `/create-help-topic`.

## Skills / Commands
Invoke with `/command-name`:

- `/dev-seed-completeness` — verify seed data coverage for a new persisted feature
- `/phinx-migration` — create and run a Phinx migration for a schema change
- `/test-runs` — which test command to use, test database mechanics, parallel runs
- `/rotating-review` — the scheduled section-by-section review and its counter
- `/create-help-topic` — write a help topic under `/help`, including screenshots
- `/git-commit` — how work lands in git here (checks, message, squash, merge)
- `/e2e-scenario` — add a Playwright end-to-end scenario
- `/squash-branch` — squash all branch commits since `main` into one clean commit
