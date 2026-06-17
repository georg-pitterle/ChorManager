# Seed Data

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
