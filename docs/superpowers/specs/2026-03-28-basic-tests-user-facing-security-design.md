# Basic Tests For User-Facing And Security-Sensitive Features

## Goal

Add basic automated tests for the application's user-facing and security-sensitive areas without introducing integration tests.

The suite should improve regression detection for validation rules, permission derivation, route coverage, template presence, and small isolated logic paths. It should not depend on a real database, real mail delivery, or full HTTP application bootstrapping.

## Scope

### Included Areas

1. Authentication and initial setup
2. Password reset
3. Profile management
4. User management
5. Roles
6. Session authentication and permission derivation
7. Remember-login behavior
8. Downloads
9. Finance
10. Sponsoring
11. Song library
12. Newsletters

### Excluded Areas

1. Dashboard-only behavior
2. Attendance
3. Evaluations
4. Events
5. Projects
6. Voice groups
7. Event types
8. App settings
9. Dev seed internals
10. Migrations
11. Full-stack HTTP flows
12. Real database behavior
13. Real mail delivery

## Test Philosophy

The test suite will use two levels of coverage only.

### 1. Structural Tests

These confirm that a feature's basic wiring exists and has not been removed accidentally.

Typical assertions:

1. Controller classes exist
2. Expected controller methods exist
3. Routes are registered in the route file
4. Required templates exist
5. Key service or model classes exist where they are part of the feature boundary

### 2. Pure-Logic Tests

These cover isolated logic that can run without persistence or application boot.

Typical assertions:

1. Validation rejects missing or malformed input
2. Session permission flags are derived correctly
3. Safe filename handling strips dangerous characters
4. Range parsing accepts and rejects the correct header formats
5. Fiscal year calculations return the expected windows
6. Small JSON or payload formatting helpers produce stable output

## Allowed Refactoring

Small seam-creating refactors are allowed when necessary to make isolated tests possible.

Allowed examples:

1. Injecting a collaborator instead of instantiating it inline
2. Extracting validation logic into a small helper or dedicated method
3. Extracting range parsing or filename normalization into a helper
4. Extracting date or amount normalization into a testable utility

Not allowed:

1. Broad architecture rewrites
2. Rebuilding controllers around a new framework abstraction only for testing
3. Introducing integration-style test harnesses disguised as unit tests

## Feature Coverage Plan

### Authentication And Setup

Files:

1. src/Controllers/AuthController.php
2. src/Services/SessionAuthService.php
3. src/Services/RememberLoginService.php
4. src/Routes.php

Coverage:

1. Structural checks for login, logout, and setup routes and controller methods
2. Structural checks for auth templates
3. Pure-logic tests for session permission derivation in SessionAuthService
4. Pure-logic tests for selected cookie and policy logic in RememberLoginService, where isolated safely

### Password Reset

Files:

1. src/Controllers/PasswordResetController.php
2. templates/auth/forgot_password.twig
3. templates/auth/reset_password.twig
4. templates/emails/password_reset.twig
5. src/Routes.php

Coverage:

1. Structural checks for routes, controller methods, and templates
2. Pure-logic tests for invalid email rejection
3. Pure-logic tests for missing token or email rejection
4. Pure-logic tests for missing required password fields
5. Pure-logic tests for password mismatch rejection
6. Pure-logic tests for minimum password length

Required seam:

1. Replace inline Mailer construction with an injected collaborator or equivalent seam so mail-sending branches can be isolated if needed

### Profile

Files:

1. src/Controllers/ProfileController.php
2. src/Routes.php
3. templates/profile/

Coverage:

1. Structural checks for profile routes, controller methods, and templates
2. Pure-logic tests for required field validation in profile updates
3. Pure-logic tests for required field validation in password changes
4. Pure-logic tests for new password confirmation mismatch

### User Management

Files:

1. src/Controllers/UserController.php
2. src/Routes.php
3. templates/users/

Coverage:

1. Structural checks for routes, controller methods, and templates
2. Pure-logic tests for required create-field validation
3. Pure-logic tests for voice-group restrictions for limited managers
4. Pure-logic tests for denial when edit permissions are insufficient

Implementation note:

1. If these rules are too entangled with model queries, extract only the permission and validation decision logic into a small helper rather than forcing mocked ORM chains into tests

### Roles

Files:

1. src/Controllers/RoleController.php
2. src/Routes.php
3. templates/roles/

Coverage:

1. Structural checks for role routes, controller methods, and templates
2. Pure-logic tests for required role-name validation
3. Pure-logic tests for permission-flag payload normalization from request data if extracted or otherwise isolated cleanly

Implementation note:

1. Roles are included because permission configuration is security-sensitive and directly affects SessionAuthService-derived authorization behavior

### Downloads

Files:

1. src/Controllers/DownloadController.php
2. src/Routes.php
3. templates/songs/downloads.twig

Coverage:

1. Structural checks for download routes, controller methods, and template presence
2. Pure-logic tests for safe filename normalization
3. Pure-logic tests for range-header validation and partial-content boundaries
4. Pure-logic tests for unsupported streaming MIME-type rejection rules

Implementation note:

1. Extract private logic into a helper if direct controller testing would otherwise require database-backed attachments

### Finance

Files:

1. src/Controllers/FinanceController.php
2. src/Routes.php
3. templates/finances/

Coverage:

1. Structural checks for finance routes, controller methods, and templates
2. Pure-logic tests for fiscal-year start and end date calculations
3. Pure-logic tests for default year selection
4. Pure-logic tests for amount normalization from form input
5. Pure-logic tests for grouped total calculations if extracted cleanly

### Sponsoring

Files:

1. src/Controllers/SponsoringDashboardController.php
2. src/Controllers/SponsorController.php
3. src/Controllers/SponsorshipController.php
4. src/Controllers/SponsoringContactController.php
5. src/Controllers/SponsorPackageController.php
6. src/Routes.php
7. templates/sponsoring/

Coverage:

1. Structural checks for protected routes, controller methods, and templates
2. Structural checks for attachment-related endpoints
3. Pure-logic tests only where validation or normalization can be isolated without persistence

### Song Library

Files:

1. src/Controllers/SongLibraryController.php
2. src/Routes.php
3. templates/songs/

Coverage:

1. Structural checks for routes, controller methods, and templates
2. Pure-logic tests only for isolated validation or attachment-related helper rules if present or extracted

### Newsletters

Files:

1. src/Controllers/NewsletterController.php
2. src/Services/NewsletterService.php
3. src/Services/NewsletterLockingService.php
4. src/Services/NewsletterRecipientService.php
5. src/Routes.php
6. templates/newsletters/
7. tests/Feature/NewsletterFeatureTest.php

Coverage:

1. Preserve and improve the existing structural test coverage
2. Add structural coverage for newer newsletter routes and actions if missing
3. Add pure-logic tests for service-level validation behavior where no database setup is required
4. Add lightweight tests for controller response-formatting helpers where useful

## Test Organization

Tests should remain easy to scan and grouped by feature area.

Proposed layout:

1. tests/Feature/AuthFeatureTest.php
2. tests/Feature/PasswordResetFeatureTest.php
3. tests/Feature/ProfileFeatureTest.php
4. tests/Feature/UserManagementFeatureTest.php
5. tests/Feature/RoleFeatureTest.php
6. tests/Feature/DownloadFeatureTest.php
7. tests/Feature/FinanceFeatureTest.php
8. tests/Feature/SponsoringFeatureTest.php
9. tests/Feature/SongLibraryFeatureTest.php
10. tests/Feature/NewsletterFeatureTest.php
11. tests/Unit/SessionAuthServiceTest.php
12. tests/Unit/RememberLoginServiceTest.php
13. Additional unit-style helper tests only where small extractions are introduced

If the repository prefers a single tests/Feature-only layout, the unit-style tests may remain in tests/Feature as long as their intent is clear and they do not rely on integration behavior.

## Non-Goals

1. Measuring complete business correctness across all modules
2. Adding database fixtures or transactional test infrastructure
3. Simulating full browser flows
4. Replacing manual QA entirely
5. Retrofitting every controller branch into a unit test regardless of coupling cost

## Risks And Mitigations

### Risk: Tight ORM Coupling Prevents Useful Isolated Tests

Mitigation:

1. Prefer tiny seam-creating refactors
2. Fall back to structural coverage where a logic seam is not worth the complexity

### Risk: Test Suite Becomes Large But Low-Value

Mitigation:

1. Keep each feature's structural assertions concise
2. Add branch tests only for security-sensitive or validation-heavy logic

### Risk: Private Controller Logic Is Hard To Reach Cleanly

Mitigation:

1. Extract reusable pure logic into helpers instead of testing private methods indirectly through heavy mocks

## Validation Plan

The implementation should verify at least the following:

1. PHPUnit test discovery still works
2. New tests run successfully in DDEV
3. PHP lint passes for changed PHP files using ddev exec php -l
4. Existing newsletter structural coverage remains green after expansion

## Expected Outcome

After implementation, the repository will have a broader but still maintainable baseline test suite focused on the highest-risk user-facing areas. The suite will catch accidental removal of routes, templates, or actions, and will verify the most important isolated validation and permission rules without introducing database-dependent integration tests.