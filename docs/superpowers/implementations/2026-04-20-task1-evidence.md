# Task 1 Evidence: Fail-First and Pass Verification

Date: 2026-04-20
Scope: Task 1 spec-compliance evidence for `MailDeliveryLifecycleFeatureTest`

## Objective
Provide auditable proof that:
1. In the current worktree, replaying Task 1 with implementation temporarily absent yields an assertion-based failure.
2. Restoring files exactly to HEAD state yields a passing run with the same command.

## Environment and Setup
- Host OS: Windows (PowerShell)
- Repo: `d:/Proggen/ChorManager`
- Branch during replay: `bounce`
- Required test command form: `ddev exec php vendor/bin/phpunit tests/Feature/MailDeliveryLifecycleFeatureTest.php`

## Commands Executed

### 1) Verify DDEV is running in current repo
```powershell
git status --short
git rev-parse --abbrev-ref HEAD
ddev describe
```
Result:
- DDEV services `web` and `db` reported `OK` for project `ChorManager`.
- Replay proceeded in current repo worktree.

### 2) Controlled fail-first replay setup in current worktree
```powershell
$ErrorActionPreference = 'Stop'
$temp = '.tmp/task1-strict-replay'
New-Item -ItemType Directory -Force -Path $temp | Out-Null
Move-Item 'src/Models/MailDeliveryEvent.php' "$temp/MailDeliveryEvent.php" -Force
Move-Item 'db/migrations/20260420111000_create_mail_delivery_events_table.php' "$temp/20260420111000_create_mail_delivery_events_table.php" -Force
cmd /c "git show f7c3a85^:src/Models/MailQueue.php > src\Models\MailQueue.php"
git status --short
```
Result:
- `src/Models/MailDeliveryEvent.php` and `db/migrations/20260420111000_create_mail_delivery_events_table.php`
	were temporarily removed from the working tree via move.
- `src/Models/MailQueue.php` was temporarily replaced with parent content from `f7c3a85^`.
- Status showed expected replay deltas (`D` for removed files, `M` for `MailQueue.php`).

### 3) Run required feature test in replay state (expected fail)
```powershell
ddev exec php vendor/bin/phpunit tests/Feature/MailDeliveryLifecycleFeatureTest.php
```
Result:
- Assertion-based failure observed:
	- `1) Tests\Feature\MailDeliveryLifecycleFeatureTest::testDeliveryEventsModelAndTableExist`
	- `Failed asserting that false is true.`
	- Location: `/var/www/html/tests/Feature/MailDeliveryLifecycleFeatureTest.php:23`
- PHPUnit summary: `Tests: 2, Assertions: 6, Failures: 1.`
- Exit status: `1`

### 4) Restore all replayed files to exact HEAD state
```powershell
$ErrorActionPreference = 'Stop'
$temp = '.tmp/task1-strict-replay'
Move-Item "$temp/MailDeliveryEvent.php" 'src/Models/MailDeliveryEvent.php' -Force
Move-Item "$temp/20260420111000_create_mail_delivery_events_table.php" 'db/migrations/20260420111000_create_mail_delivery_events_table.php' -Force
git restore --source=HEAD -- 'src/Models/MailQueue.php'
Remove-Item $temp -Force
if ((Get-ChildItem '.tmp' -Force | Measure-Object).Count -eq 0) { Remove-Item '.tmp' -Force }
git status --short
```
Result:
- Replay artifacts removed.
- Files restored to HEAD content in current worktree.
- Only unrelated pre-existing modification remained in status output.

### 5) Re-run required feature test after restore (expected pass)
```powershell
ddev exec php vendor/bin/phpunit tests/Feature/MailDeliveryLifecycleFeatureTest.php
```
Result:
- `PHPUnit 10.5.63`
- `OK (2 tests, 10 assertions)`

Interpretation:
- Same command fails under controlled absent-implementation replay and passes after exact restore.

## Conclusion
- Strict fail-first replay was executed in the current worktree as requested.
- Assertion failure was captured with implementation temporarily absent.
- Full restore to HEAD was performed, and pass was verified with the same PHPUnit command.
