# Task 2 Evidence: Strict Chronological Reconstruction

Date: 2026-04-20
Requested commit baseline: `6d5ed7280fe2c757296cccf1e064f0e0120f6032^`
Main branch during execution: `bounce`

## 1) Create Temporary Worktree At Pre-Task2 Commit

Command:

```powershell
$ErrorActionPreference='Stop'; Set-Location 'D:\Proggen\ChorManager'; git worktree add '..\ChorManager-task2-pre' '6d5ed7280fe2c757296cccf1e064f0e0120f6032^'
```

Result:

```text
Preparing worktree (detached HEAD a0fdd8d)
HEAD is now at a0fdd8d fix: harden task1 model and migration safety
```

Follow-up (environment constraint):
- Running DDEV commands from that external temp path failed because a running DDEV project named `ChorManager` already existed at `D:\Proggen\ChorManager`.

Constraint output:

```text
Failed to exec command: a project (web container) in running state already exists for ChorManager that was created at D:\Proggen\ChorManager
```

Chronology-preserving adjustment (still same commit baseline, now container-mounted):

```powershell
$ErrorActionPreference='Stop'; Set-Location 'D:\Proggen\ChorManager'; git worktree remove '..\ChorManager-task2-pre' --force
$ErrorActionPreference='Stop'; Set-Location 'D:\Proggen\ChorManager'; New-Item -ItemType Directory -Path '.tmp-worktrees' -Force | Out-Null; git worktree add '.tmp-worktrees\task2-pre' '6d5ed7280fe2c757296cccf1e064f0e0120f6032^'
```

Result:

```text
Preparing worktree (detached HEAD a0fdd8d)
HEAD is now at a0fdd8d fix: harden task1 model and migration safety
```

## 2) In Temp Worktree, Apply Only Task 2 Test Addition And Capture Failure

Applied only method `testDeliveryLifecycleMigrationDefinesExpectedColumns` to:
- `tests/Feature/MailDeliveryLifecycleFeatureTest.php`

Run command:

```powershell
$ErrorActionPreference='Stop'; Set-Location 'D:\Proggen\ChorManager'; ddev exec php vendor/bin/phpunit /var/www/html/.tmp-worktrees/task2-pre/tests/Feature/MailDeliveryLifecycleFeatureTest.php --filter testDeliveryLifecycleMigrationDefinesExpectedColumns
```

Fail result (pre-task2 state, missing Task 2 migration file):

```text
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.3
Configuration: /var/www/html/phpunit.xml

F                                                                   1 / 1 (100%)

Time: 00:00.031, Memory: 4.00 MB

There was 1 failure:

1) Tests\Feature\MailDeliveryLifecycleFeatureTest::testDeliveryLifecycleMigrationDefinesExpectedColumns
Failed asserting that false is of type string.

/var/www/html/.tmp-worktrees/task2-pre/tests/Feature/MailDeliveryLifecycleFeatureTest.php:47

FAILURES!
Tests: 1, Assertions: 1, Failures: 1, Warnings: 1.
```

Interpretation:
- Failure is consistent with requested pre-Task2 condition: expected migration file/columns are absent.

## 3) In Same Temp Worktree, Apply Task 2 Migration Changes And Capture Pass

Applied migration changes:
- Added `db/migrations/20260420110000_add_delivery_lifecycle_to_mail_queue.php`
- Updated `db/migrations/20260420111000_create_mail_delivery_events_table.php`

Rerun same filtered command:

```powershell
$ErrorActionPreference='Stop'; Set-Location 'D:\Proggen\ChorManager'; ddev exec php vendor/bin/phpunit /var/www/html/.tmp-worktrees/task2-pre/tests/Feature/MailDeliveryLifecycleFeatureTest.php --filter testDeliveryLifecycleMigrationDefinesExpectedColumns
```

Pass result:

```text
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.3
Configuration: /var/www/html/phpunit.xml

.                                                                   1 / 1 (100%)

Time: 00:00.009, Memory: 4.00 MB

OK (1 test, 8 assertions)
```

## 4) In Main Worktree, Run Actual Migration Command Equivalent

Command:

```powershell
$ErrorActionPreference='Stop'; Set-Location 'D:\Proggen\ChorManager'; ddev exec php vendor/bin/phinx migrate -e development
```

Result:

```text
Phinx by CakePHP - https://phinx.org. 0.16.11

using config file phinx.php
using config parser php
using migration paths
 - /var/www/html/db/migrations
using seed paths
using environment development
using adapter mysql
using database db
ordering by creation time

All Done. Took 0.0182s
```

## 5) Scope/Integrity Notes

- Permanent source changes in main worktree: evidence file only.
- Unrelated modified spec file remained untouched.# Task 2 Evidence: Fail-First Replay, Restore, Pass Rerun, and Migration Verification

Date: 2026-04-20
Branch during replay: `bounce`
Scope: Strict evidence for Task 2 (`testDeliveryLifecycleMigrationDefinesExpectedColumns`) without permanent source changes.

## 1) Fail-First Replay (intentional temporary incompleteness)

### Pre-check (preserve unrelated changes)
Command:

```powershell
git status --short
```

Result:

```text
 M docs/superpowers/specs/2026-04-20-mail-delivery-status-design.md
```

### Temporary mutation to make migration incomplete
Command:

```powershell
$mig = 'db/migrations/20260420110000_add_delivery_lifecycle_to_mail_queue.php'; \
$bak = 'db/migrations/20260420110000_add_delivery_lifecycle_to_mail_queue.php.task2.bak'; \
Copy-Item $mig $bak -Force; \
(Get-Content $mig -Raw).Replace("'complained_at'", "'complained_removed_at'") | Set-Content $mig -NoNewline
```

Observed effect:
- The migration text no longer contains `complained_at`, which the Task 2 test expects.

### Run Task 2 test method (fail-first)
Command:

```powershell
ddev exec php vendor/bin/phpunit tests/Feature/MailDeliveryLifecycleFeatureTest.php --filter testDeliveryLifecycleMigrationDefinesExpectedColumns
```

Result:

```text
F                                                                   1 / 1 (100%)

There was 1 failure:
1) Tests\Feature\MailDeliveryLifecycleFeatureTest::testDeliveryLifecycleMigrationDefinesExpectedColumns
Failed asserting that ... contains "complained_at".

FAILURES!
Tests: 1, Assertions: 8, Failures: 1.
```

This is the required fail-first evidence where migration content is intentionally incomplete.

### Restore original migration (no permanent source change)
Command:

```powershell
$mig = 'db/migrations/20260420110000_add_delivery_lifecycle_to_mail_queue.php'; \
$bak = 'db/migrations/20260420110000_add_delivery_lifecycle_to_mail_queue.php.task2.bak'; \
Move-Item $bak $mig -Force
```

Verification command:

```powershell
git status --short
```

Verification result:

```text
 M docs/superpowers/specs/2026-04-20-mail-delivery-status-design.md
```

Interpretation:
- Only the pre-existing unrelated modified spec file remains changed.
- No migration/source implementation change persisted from the replay.

## 2) Pass Rerun on Current HEAD

Command:

```powershell
ddev exec php vendor/bin/phpunit tests/Feature/MailDeliveryLifecycleFeatureTest.php --filter testDeliveryLifecycleMigrationDefinesExpectedColumns
```

Result:

```text
.                                                                   1 / 1 (100%)

OK (1 test, 8 assertions)
```

Interpretation:
- The same Task 2 method passes on current HEAD after restore.

## 3) Migration Command Verification (DDEV-compatible)

### Why `ddev phinx` is unavailable
Command:

```powershell
ddev phinx migrate -e development
```

Result:

```text
Error: unknown command "phinx" for "ddev"
```

Explanation:
- `ddev phinx` requires a custom DDEV command wrapper named `phinx`.
- This project does not define that wrapper, so direct `ddev phinx` is unavailable.

### Supported project-compatible command path
Commands:

```powershell
ddev exec php vendor/bin/phinx --version
ddev exec php vendor/bin/phinx status -e development
```

Results:

```text
Phinx by CakePHP - https://phinx.org. 0.16.11
```

```text
Status  [Migration ID] ...
up  20260420110000  ...  AddDeliveryLifecycleToMailQueue
up  20260420111000  ...  CreateMailDeliveryEventsTable
```

Interpretation:
- Phinx works through `ddev exec php vendor/bin/phinx ...`.
- Task 2 migrations are present and applied in `development`.

## 4) Compliance Summary

- Fail-first replay produced for `testDeliveryLifecycleMigrationDefinesExpectedColumns` by intentionally making migration content incomplete.
- Replay state was restored immediately; no permanent source/migration implementation changes were left behind.
- Pass rerun on current HEAD captured.
- Migration verification captured with DDEV-compatible command path and explicit reason `ddev phinx` is unavailable.
