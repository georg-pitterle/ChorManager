# Skills Index

Route by intent keywords. Load one or more matching skills.

## Map

- ddev-workflow-guard -> run command, composer, ddev, powershell
  - .github/skills/ddev-workflow-guard/SKILL.md
- phinx-migration-enforcer -> schema, migration, table, column, index
  - .github/skills/phinx-migration-enforcer/SKILL.md
- dev-seed-completeness -> seed, DevSeedService, test data, new table seed
  - .github/skills/dev-seed-completeness/SKILL.md
- php-style-and-quality-gate -> PSR-12, phpcs, phpcbf, format php
  - .github/skills/php-style-and-quality-gate/SKILL.md
- template-hygiene-responsive-ui -> twig, inline js, inline css, responsive
  - .github/skills/template-hygiene-responsive-ui/SKILL.md
- security-baseline-reviewer -> auth, session, cookie, input validation, authorization
  - .github/skills/security-baseline-reviewer/SKILL.md
- change-reporting-standard -> summarize changes, executed commands, results
  - .github/skills/change-reporting-standard/SKILL.md

## Common Combos

- schema feature: phinx-migration-enforcer + dev-seed-completeness + change-reporting-standard
- php refactor: php-style-and-quality-gate + change-reporting-standard
- frontend change: template-hygiene-responsive-ui + change-reporting-standard
- auth/security change: security-baseline-reviewer + php-style-and-quality-gate + change-reporting-standard
