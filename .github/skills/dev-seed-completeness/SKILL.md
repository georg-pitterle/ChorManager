---
name: dev-seed-completeness
description: Ensure new persisted features include realistic dev seed data and complete seed workflow integration.
---

# dev-seed-completeness

Use for every new persisted feature.

Rules:
- Feature work is incomplete without dev seed coverage.
- Seed data must be realistic and cover relevant user flows.
- New tables/relations must be seeded, including relation links.
- Implement seed updates in `src/Services/DevSeedService.php` and related entry points.

Mandatory checklist:
- Add new tables to `resetSeedData()`.
- Add new counters to seed report in `run()`.
- Add required model imports in `src/Services/DevSeedService.php`.
- Add/update dedicated seed methods for new entities/relations.
- Wire seed methods into `run()` in dependency-safe order.
- Seed role/permission data if authorization changes.
- Execute a real dev seed run before finishing.
- Inspect report and confirm new counts are populated.
