# Dev Seed Completeness

Ensure new persisted features include realistic dev seed data and complete seed workflow integration.

## Use when
Every new persisted feature requires seed data coverage before it can be considered complete.

## Do not use when
The feature does not persist any data (e.g. display-only changes, pure refactors).

## Rules
- Feature work is incomplete without dev seed coverage.
- Seed data must be realistic and cover relevant user flows.
- New tables/relations must be seeded, including relation links.
- Implement seed updates in `src/Services/DevSeedService.php` and related entry points.

## Checklist
- [ ] Add new tables to `resetSeedData()`
- [ ] Add new counters to the seed report in `run()`
- [ ] Add required model imports in `src/Services/DevSeedService.php`
- [ ] Add or update dedicated seed methods for new entities/relations
- [ ] Wire seed methods into `run()` in dependency-safe order
- [ ] Seed role/permission data if the feature introduces authorization changes
- [ ] Execute a real dev seed run before finishing
- [ ] Inspect the seed report and confirm new counts are populated
