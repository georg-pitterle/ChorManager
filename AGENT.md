# ChorManager Agent Rules (Minimal)

## Scope
These rules are for AI coding agents working in this repository.
You are a professional software engeneer.

## Environment
- Use DDEV for project commands.
- On Windows host, prefer PowerShell commands.
- Inside DDEV/container, use project-appropriate shell commands.

## Database
- All schema changes must be done via Phinx migrations.
- Default migration command: `ddev exec ./vendor/bin/phinx migrate`.
- Agents should run migrations automatically for schema changes.
- Agents must report migration outcome (success or error with cause).
- Ask before running migrations only if:
  - environment is production or unclear,
  - migration is destructive/potentially destructive,
  - access/connectivity is missing.

## Seed Data Requirement
- For every newly implemented feature, agents must also add or update seed data so the feature can be tested immediately in Dev.
- Seed data must be realistic and cover relevant user flows of the new feature.
- If a feature introduces new tables or relations, seed generation must include those relations.
- Seed updates must be implemented in the Dev seed workflow (`src/Services/DevSeedService.php` and related entry points).
- Feature work is not complete until seed data coverage is included and reported.
- Mandatory completion checklist for every new persisted feature:
  - add new tables to `resetSeedData()`
  - add new counters to the seed report in `run()`
  - add required model imports in `src/Services/DevSeedService.php`
  - add or update dedicated seed methods for the new entities and relations
  - wire those seed methods into the `run()` flow in dependency-safe order
  - seed relevant role or permission data when the feature introduces authorization changes
  - execute a real dev seed run before finishing
  - inspect the resulting report and confirm the new counts are populated

## Code and Style
- Follow PSR-12.
- Use 4 spaces (no tabs).
- Line length: soft limit 120, hard limit 130.
- Run style checks for substantial changes:
  - `ddev composer phpcs`
  - `ddev composer phpcbf` (if needed)

## Project Constraints
- Do not modify files in `vendor/`.
- Do not use inline JavaScript in templates; use dedicated JS files.
- Do not use inline CSS in templates; use CSS files.
- Keep UI responsive on mobile and desktop.

## Security Baseline
- Follow OWASP principles.
- Rotate session ID after successful login.
- Use secure cookie settings (HttpOnly, Secure where applicable, SameSite).
- Validate all user input.
- Apply least privilege.
- Never store secrets or credentials in the repository.

## Reporting
- Never perform important actions silently.
- Always summarize what was changed, what was executed, and the result.