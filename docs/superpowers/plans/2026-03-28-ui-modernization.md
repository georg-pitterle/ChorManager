# UI Modernization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modernize the Chorus Manager UI with a cleaner topbar-based shell, consistent page patterns, refreshed dashboard/auth/list/form screens, and a configurable primary color that defaults to the current yellow theme.

**Architecture:** Keep the existing Slim + Twig + Bootstrap rendering model and improve the presentation layer in place. Introduce a small theming pipeline through app settings and a generated stylesheet endpoint, then refactor the shared shell and representative templates to consume a consistent UI system.

**Tech Stack:** PHP 8.5, Slim 4, Twig, Bootstrap 5, custom CSS, PHPUnit 10, DDEV

---

## Scope Update: All Areas

This plan's UI rules and task patterns must be applied consistently to all template domains in the repository, not only the initial subset.

Full rollout coverage:

- `attendance/`
- `auth/`
- `dashboard/`
- `evaluations/`
- `events/`
- `finances/`
- `newsletters/`
- `profile/`
- `projects/`
- `roles/`
- `settings/`
- `songs/`
- `sponsoring/`
- `users/`
- `voice_groups/`

Execution rule: after completing Tasks 1-7 as baseline patterns, repeat the same page-header/list/form/state conventions for every remaining area before final acceptance.

---

## File Map

**Create:**

- `tests/Feature/AppSettingFeatureTest.php` — feature coverage for app settings routes, templates, and color normalization behavior

**Modify:**

- `src/Controllers/AppSettingController.php` — persist and validate configurable primary color, expose theme CSS endpoint
- `src/Routes.php` — register the theme stylesheet route
- `src/Dependencies.php` — keep app settings globally available and ready for theme consumption
- `templates/layout.twig` — new app shell structure, theme stylesheet link, shared page header blocks
- `templates/dashboard/index.twig` — new working dashboard layout
- `templates/events/index.twig` — list page pattern with page header, action bar, filter block, table shell
- `templates/settings/index.twig` — surface color setting in app settings UI
- `templates/auth/login.twig` — modernized auth layout
- `templates/auth/forgot_password.twig` — modernized auth layout
- `templates/auth/reset_password.twig` — modernized auth layout
- `templates/auth/setup.twig` — modernized auth layout
- `public/css/style.css` — redesign global tokens, topbar, shell, cards, alerts, forms, auth, utilities
- `public/css/responsive-tables.css` — align responsive table behavior with the refreshed list/table pattern if needed
- `tests/Feature/AuthFeatureTest.php` — route/template assertions for refreshed auth pages remain covered

## Task 1: Lock Down Settings And Theming Behavior With Tests

**Files:**

- Create: `tests/Feature/AppSettingFeatureTest.php`
- Test: `tests/Feature/AppSettingFeatureTest.php`

- [ ] **Step 1: Write the failing test for settings routes, template, and color normalization**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AppSettingController;
use PHPUnit\Framework\TestCase;

class AppSettingFeatureTest extends TestCase
{
    public function testSettingsStructureExists(): void
    {
        $this->assertTrue(class_exists(AppSettingController::class));
        $this->assertTrue(method_exists(AppSettingController::class, 'index'));
        $this->assertTrue(method_exists(AppSettingController::class, 'save'));
        $this->assertTrue(method_exists(AppSettingController::class, 'logo'));
        $this->assertTrue(method_exists(AppSettingController::class, 'themeCss'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/settings'", $routesContent);
        $this->assertStringContainsString("'/logo'", $routesContent);
        $this->assertStringContainsString("'/theme.css'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/settings/index.twig'));
    }

    public function testNormalizePrimaryColorAcceptsValidHexAndAddsHash(): void
    {
        $this->assertSame('#E8A817', AppSettingController::normalizePrimaryColor('E8A817'));
        $this->assertSame('#112233', AppSettingController::normalizePrimaryColor('#112233'));
        $this->assertSame('#AABBCC', AppSettingController::normalizePrimaryColor('abc'));
    }

    public function testNormalizePrimaryColorFallsBackForInvalidValues(): void
    {
        $this->assertSame('#E8A817', AppSettingController::normalizePrimaryColor(''));
        $this->assertSame('#E8A817', AppSettingController::normalizePrimaryColor('not-a-color'));
        $this->assertSame('#E8A817', AppSettingController::normalizePrimaryColor('#12345'));
    }
}
```

- [ ] **Step 2: Run the new test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AppSettingFeatureTest.php`

Expected: `FAIL` because `themeCss()` and `normalizePrimaryColor()` do not exist yet and the route is missing.

- [ ] **Step 3: Commit the failing test**

```bash
git add tests/Feature/AppSettingFeatureTest.php
git commit -m "test: cover app settings theming behavior"
```

## Task 2: Persist And Serve The Configurable Primary Color

**Files:**

- Modify: `src/Controllers/AppSettingController.php`
- Modify: `src/Routes.php`
- Modify: `templates/settings/index.twig`
- Test: `tests/Feature/AppSettingFeatureTest.php`

- [ ] **Step 1: Implement color normalization and a generated theme stylesheet endpoint in the controller**

```php
public const DEFAULT_PRIMARY_COLOR = '#E8A817';

public static function normalizePrimaryColor(?string $value): string
{
    $candidate = strtoupper(trim((string) $value));

    if ($candidate === '') {
        return self::DEFAULT_PRIMARY_COLOR;
    }

    if ($candidate[0] !== '#') {
        $candidate = '#' . $candidate;
    }

    if (preg_match('/^#([A-F0-9]{6}|[A-F0-9]{3})$/', $candidate) !== 1) {
        return self::DEFAULT_PRIMARY_COLOR;
    }

    if (strlen($candidate) === 4) {
        return sprintf(
            '#%1$s%1$s%2$s%2$s%3$s%3$s',
            $candidate[1],
            $candidate[2],
            $candidate[3]
        );
    }

    return $candidate;
}

public function themeCss(Request $request, Response $response): Response
{
    $themeColor = self::DEFAULT_PRIMARY_COLOR;

    try {
        $themeColor = self::normalizePrimaryColor(AppSetting::query()->find('primary_color')?->setting_value);
    } catch (\Throwable $exception) {
        $themeColor = self::DEFAULT_PRIMARY_COLOR;
    }

    $css = ":root, [data-bs-theme=\"light\"] {\n"
        . "    --theme-primary: {$themeColor};\n"
        . "    --bs-primary: {$themeColor};\n"
        . "}\n";

    $response->getBody()->write($css);

    return $response
        ->withHeader('Content-Type', 'text/css; charset=utf-8')
        ->withHeader('Cache-Control', 'no-store, max-age=0');
}
```

- [ ] **Step 2: Persist the normalized primary color when saving app settings**

```php
$primaryColor = self::normalizePrimaryColor($data['primary_color'] ?? null);

AppSetting::updateOrCreate(
    ['setting_key' => 'primary_color'],
    [
        'setting_value' => $primaryColor,
        'binary_content' => '',
        'mime_type' => 'text/plain',
    ]
);
```

- [ ] **Step 3: Register the generated stylesheet route**

```php
$app->get('/theme.css', [AppSettingController::class, 'themeCss']);
```

- [ ] **Step 4: Expose the setting in the app settings template**

```twig
<div class="mb-4">
    <label for="primary_color" class="form-label fw-bold">Primärfarbe</label>
    <input
        type="text"
        class="form-control"
        id="primary_color"
        name="primary_color"
        value="{{ settings.primary_color|default('#E8A817') }}"
        inputmode="text"
        pattern="^#?[A-Fa-f0-9]{3}([A-Fa-f0-9]{3})?$"
    >
    <div class="form-text">Hex-Farbwert, zum Beispiel #E8A817. Leer oder ungültig fällt auf das Standardgelb zurück.</div>
</div>
```

- [ ] **Step 5: Run the app settings test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AppSettingFeatureTest.php`

Expected: `OK` with 3 passing tests.

- [ ] **Step 6: Lint the touched PHP files**

Run: `ddev exec php -l src/Controllers/AppSettingController.php`

Expected: `No syntax errors detected in src/Controllers/AppSettingController.php`

- [ ] **Step 7: Commit the settings/theming backend work**

```bash
git add src/Controllers/AppSettingController.php src/Routes.php templates/settings/index.twig tests/Feature/AppSettingFeatureTest.php
git commit -m "feat: add configurable primary color setting"
```

## Task 3: Build The Shared App Shell And Global UI Tokens

**Files:**

- Modify: `templates/layout.twig`
- Modify: `public/css/style.css`
- Modify: `src/Dependencies.php`
- Test: `tests/Feature/AppSettingFeatureTest.php`

- [ ] **Step 1: Link the generated theme stylesheet and introduce shell/page-header slots in the layout**

```twig
<link rel="stylesheet" href="/theme.css">
<link rel="stylesheet" href="/css/style.css">

<body class="app-shell">
    {% if session.user_id %}
    <nav class="app-topbar navbar navbar-expand-lg fixed-top">
        <div class="container-fluid app-topbar__inner">
            <a class="navbar-brand app-brand d-flex align-items-center" href="/dashboard">
                <img src="/logo" alt="Logo" height="36" class="me-2">
                <span>{{ app_settings.app_name|default('Chor Manager') }}</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appTopbarNav" aria-controls="appTopbarNav" aria-expanded="false" aria-label="Navigation umschalten">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="appTopbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 app-primary-nav">
                    <li class="nav-item">
                        <a class="nav-link {% if is_dashboard_active %}active{% endif %}" href="/dashboard">Dashboard</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {% if is_events_active %}active{% endif %}" href="#" data-bs-toggle="dropdown" aria-expanded="false">Termine</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item {% if nav_active(path, nav, ['/events'], ['events']) %}active{% endif %}" href="/events">Termine</a></li>
                            <li><a class="dropdown-item {% if nav_active(path, nav, ['/attendance'], ['attendance']) %}active{% endif %}" href="/attendance">Anwesenheit</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {% if is_areas_active %}active{% endif %}" href="#" data-bs-toggle="dropdown" aria-expanded="false">Bereiche</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item {% if nav_active(path, nav, ['/downloads'], ['downloads']) %}active{% endif %}" href="/downloads">Downloads</a></li>
                            <li><a class="dropdown-item {% if nav_active(path, nav, ['/newsletters/archive'], ['newsletters_archive', '/newsletters/archive']) %}active{% endif %}" href="/newsletters/archive">Meine Newsletter</a></li>
                        </ul>
                    </li>
                </ul>

                <div class="app-user-nav dropdown">
                    <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle fs-5 me-2"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="/profile">Mein Profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="/logout">Abmelden</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    {% endif %}

    <main class="app-main container-fluid">
        {% block page_header %}{% endblock %}
        <div class="app-content container">
            {% block content %}{% endblock %}
        </div>
    </main>
</body>
```

- [ ] **Step 2: Refactor the global stylesheet into reusable shell and token classes**

```css
:root,
[data-bs-theme="light"] {
    --theme-primary: #E8A817;
    --theme-primary-strong: #c48e0f;
    --theme-surface: #ffffff;
    --theme-surface-muted: #f5f7fa;
    --theme-border: #d9dee7;
    --theme-text: #1f2937;
    --theme-text-muted: #667085;
    --theme-topbar: #18212b;
    --theme-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
}

body.app-shell {
    background: linear-gradient(180deg, #f7f8fb 0%, #eef2f7 100%);
    color: var(--theme-text);
    padding-top: 5.5rem;
}

.app-topbar {
    background: rgba(24, 33, 43, 0.94);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 1rem;
    margin: 1.5rem 0;
}

.surface-card {
    background: var(--theme-surface);
    border: 1px solid var(--theme-border);
    border-radius: 1rem;
    box-shadow: var(--theme-shadow);
}
```

- [ ] **Step 3: Keep app settings globally available without changing the Twig contract**

```php
try {
    $appSettings = \App\Models\AppSetting::all()->pluck('setting_value', 'setting_key')->toArray();
} catch (\Exception $e) {
    $appSettings = [];
}

$environment->addGlobal('app_settings', $appSettings);
```

- [ ] **Step 4: Run the app settings test and full suite after shell wiring**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AppSettingFeatureTest.php`

Expected: `OK`

Run: `ddev exec php vendor/bin/phpunit`

Expected: `OK` for the full suite.

- [ ] **Step 5: Commit the shared shell foundation**

```bash
git add templates/layout.twig public/css/style.css src/Dependencies.php
git commit -m "feat: add refreshed app shell foundation"
```

## Task 4: Redesign The Dashboard And Settings Page Patterns

**Files:**

- Modify: `templates/dashboard/index.twig`
- Modify: `templates/settings/index.twig`
- Test: `tests/Feature/AppSettingFeatureTest.php`

- [ ] **Step 1: Replace the dashboard hero and card grid with a working surface layout**

```twig
{% block page_header %}
<section class="page-header">
    <div>
        <p class="eyebrow">Dashboard</p>
        <h1 class="page-title">Willkommen, {{ session.user_name|default('Mitglied') }}</h1>
        <p class="page-subtitle">Dein Arbeitsbereich für Termine, Aufgaben und Schnellzugriffe.</p>
    </div>
    <div class="page-actions">
        <a href="/attendance" class="btn btn-primary">Zur Anwesenheit</a>
    </div>
</section>
{% endblock %}

{% block content %}
<section class="dashboard-grid">
    <article class="surface-card dashboard-panel">
        <h2 class="dashboard-panel__title"><i class="bi bi-calendar-check-fill me-2"></i>Nächste Probe</h2>
        <p class="dashboard-panel__body">Hier siehst du geplante Termine und kannst deine Anwesenheit direkt verwalten.</p>
        <a href="/attendance" class="btn btn-outline-primary">Zur Anwesenheitsliste</a>
    </article>

    {% if can_manage_users %}
    <article class="surface-card dashboard-panel">
        <h2 class="dashboard-panel__title"><i class="bi bi-people-fill me-2"></i>Mitgliederverwaltung</h2>
        <p class="dashboard-panel__body">Mitglieder, Rollen und Stimmgruppen schneller über einen einheitlichen Arbeitsbereich pflegen.</p>
        <a href="/users" class="btn btn-outline-primary">Mitglieder verwalten</a>
    </article>

    <article class="surface-card dashboard-panel">
        <h2 class="dashboard-panel__title"><i class="bi bi-bar-chart-fill me-2"></i>Auswertungen</h2>
        <p class="dashboard-panel__body">Anwesenheitsquoten und Projektstände ohne visuelle Umwege erfassen.</p>
        <a href="/evaluations" class="btn btn-outline-primary">Auswertungen öffnen</a>
    </article>
    {% elseif role_level >= 40 %}
    <article class="surface-card dashboard-panel">
        <h2 class="dashboard-panel__title"><i class="bi bi-person-lines-fill me-2"></i>Meine Stimmgruppe</h2>
        <p class="dashboard-panel__body">Anwesenheiten der eigenen Stimmgruppe in einer klareren Arbeitsfläche verwalten.</p>
        <a href="/attendance" class="btn btn-outline-primary">Anwesenheiten eintragen</a>
    </article>
    {% endif %}
</section>
{% endblock %}
```

- [ ] **Step 2: Apply the same page-header and surface-card structure to the settings page**

```twig
{% block page_header %}
<section class="page-header">
    <div>
        <p class="eyebrow">Verwaltung</p>
        <h1 class="page-title">App-Einstellungen</h1>
        <p class="page-subtitle">Name, Logo und Standardfarbe zentral verwalten.</p>
    </div>
</section>
{% endblock %}

{% block content %}
<section class="surface-card form-surface">
    <div class="card-body p-4 p-lg-5">
        <form action="/settings" method="post" enctype="multipart/form-data" autocomplete="off" class="stack-lg">
            <div class="form-section">
                <h2 class="section-title">Identität</h2>
                <p class="section-copy">Name, Logo und Standardfarbe zentral für die gesamte Anwendung festlegen.</p>
            </div>

            <div class="mb-4">
                <label for="app_name" class="form-label fw-bold">Name der App</label>
                <input type="text" class="form-control" id="app_name" name="app_name" value="{{ settings.app_name|default('Chor Manager') }}" required>
            </div>

            <div class="mb-4">
                <label for="app_logo" class="form-label fw-bold">Logo hochladen</label>
                <input class="form-control" type="file" id="app_logo" name="app_logo" accept="image/*">
            </div>

            <div class="mb-4">
                <label for="primary_color" class="form-label fw-bold">Primärfarbe</label>
                <input type="text" class="form-control" id="primary_color" name="primary_color" value="{{ settings.primary_color|default('#E8A817') }}">
            </div>

            <div class="form-actions d-flex gap-2 justify-content-end">
                <a href="/dashboard" class="btn btn-outline-secondary">Abbrechen</a>
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            </div>
        </form>
    </div>
</section>
{% endblock %}
```

- [ ] **Step 3: Run the settings test and full suite**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AppSettingFeatureTest.php`

Expected: `OK`

Run: `ddev exec php vendor/bin/phpunit`

Expected: `OK`

- [ ] **Step 4: Commit the dashboard/settings redesign**

```bash
git add templates/dashboard/index.twig templates/settings/index.twig public/css/style.css templates/layout.twig
git commit -m "feat: refresh dashboard and settings layouts"
```

## Task 5: Standardize List And Table Screens With The Events Page

**Files:**

- Modify: `templates/events/index.twig`
- Modify: `public/css/style.css`
- Modify: `public/css/responsive-tables.css`
- Test: `tests/Feature/AppSettingFeatureTest.php`

- [ ] **Step 1: Move page-level inline styles out of the events template**

```twig
{# delete the inline <style> block at the top of templates/events/index.twig #}
```

```css
.table-shell {
    overflow: visible;
}

@media (max-width: 767.98px) {
    .table-shell .table-responsive {
        overflow-x: auto;
        padding-bottom: 4rem;
    }
}
```

- [ ] **Step 2: Wrap filters, actions, and results in the shared list page structure**

```twig
{% block page_header %}
<section class="page-header">
    <div>
        <p class="eyebrow">Termine</p>
        <h1 class="page-title">Events</h1>
        <p class="page-subtitle">Plane Termine, filtere Ergebnisse und bearbeite Serien konsistent an einem Ort.</p>
    </div>
    {% if session.can_manage_users %}
    <div class="page-actions">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEventModal">Termin erstellen</button>
    </div>
    {% endif %}
</section>
{% endblock %}

{% block content %}
<section class="surface-card filter-surface">
    <div class="card-body p-4">
        <form method="get" action="/events" id="event-filter-form" class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <label for="filter_project" class="form-label small">Projekt</label>
                <select name="project_id" id="filter_project" class="form-select">
                    <option value="">Alle Projekte</option>
                    {% for p in projects %}
                        <option value="{{ p.id }}" {% if filters.project_id == p.id %}selected{% endif %}>{{ p.name }}</option>
                    {% endfor %}
                </select>
            </div>
            <div class="col-12 col-md-5">
                <label for="filter_type" class="form-label small">Event-Typ</label>
                <select name="event_type_id" id="filter_type" class="form-select">
                    <option value="">Alle Typen</option>
                    {% for et in event_types %}
                        <option value="{{ et.id }}" {% if filters.event_type_id == et.id %}selected{% endif %}>{{ et.name }}</option>
                    {% endfor %}
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex">
                <a href="/events" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>
</section>

<section class="surface-card table-shell mt-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-responsive-cards">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Titel</th>
                    <th>Ort</th>
                    <th>Typ</th>
                    <th>Projekt</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                {% for event in events %}
                <tr>
                    <td data-label="Datum">{{ event.event_date|date('d.m.Y') }}</td>
                    <td data-label="Titel">{{ event.title }}</td>
                    <td data-label="Ort">{{ event.location|default('-') }}</td>
                    <td data-label="Typ"><span class="badge bg-{{ event.type_color }}">{{ event.type_name }}</span></td>
                    <td data-label="Projekt">{{ event.project_name|default('-') }}</td>
                    <td data-label="Aktionen" class="text-end">
                        <a href="/attendance/{{ event.id }}" class="btn btn-sm btn-outline-primary">Anwesenheit</a>
                    </td>
                </tr>
                {% else %}
                <tr>
                    <td colspan="6" class="text-center py-4 text-muted">Keine Termine geplant.</td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</section>
{% endblock %}
```

- [ ] **Step 3: Apply standardized list/table utility classes in CSS**

```css
.filter-surface,
.table-shell,
.form-surface {
    border-radius: 1rem;
    border: 1px solid var(--theme-border);
    background: var(--theme-surface);
    box-shadow: var(--theme-shadow);
}

.table thead th {
    background: #1b2430;
    color: #fff;
    font-size: 0.875rem;
    letter-spacing: 0.02em;
}

.table tbody tr:hover {
    background: rgba(232, 168, 23, 0.08);
}
```

- [ ] **Step 4: Run the full suite and do a manual browser check of `/events`**

Run: `ddev exec php vendor/bin/phpunit`

Expected: `OK`

Manual check:

```text
Open /events and verify the page header, filter bar, table container, modal trigger, and mobile table behavior.
```

- [ ] **Step 5: Commit the shared list/table pattern**

```bash
git add templates/events/index.twig public/css/style.css public/css/responsive-tables.css
git commit -m "feat: standardize list and table layouts"
```

## Task 6: Refresh Auth Screens To Match The New System

**Files:**

- Modify: `templates/auth/login.twig`
- Modify: `templates/auth/forgot_password.twig`
- Modify: `templates/auth/reset_password.twig`
- Modify: `templates/auth/setup.twig`
- Modify: `public/css/style.css`
- Modify: `tests/Feature/AuthFeatureTest.php`

- [ ] **Step 1: Preserve auth route coverage and extend template assertions if needed**

```php
public function testAuthRoutesAndTemplatesExist(): void
{
    $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

    $this->assertIsString($routesContent);
    $this->assertStringContainsString("'/login'", $routesContent);
    $this->assertStringContainsString("'/logout'", $routesContent);
    $this->assertStringContainsString("'/setup'", $routesContent);
    $this->assertStringContainsString('/forgot-password', $routesContent);
    $this->assertStringContainsString('/reset-password', $routesContent);

    $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/login.twig'));
    $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/setup.twig'));
    $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/forgot_password.twig'));
    $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/reset_password.twig'));
}
```

- [ ] **Step 2: Rework the auth templates around a shared auth-shell pattern**

```twig
{% block content %}
<section class="auth-shell">
    <div class="auth-card surface-card">
        <div class="auth-brand-panel">
            <img src="/logo" alt="Logo" class="auth-logo">
            <h1>{{ app_settings.app_name|default('Chor Manager') }}</h1>
            <p>Bitte melde dich an, um fortzufahren.</p>
        </div>
        <div class="auth-form-panel">
            {% if error %}
                <div class="alert alert-danger" role="alert">{{ error }}</div>
            {% endif %}

            <form action="/login" method="post" class="stack-md">
                <div class="form-floating">
                    <input type="email" class="form-control" id="emailInput" name="email" placeholder="name@example.com" required autofocus>
                    <label for="emailInput">E-Mail-Adresse</label>
                </div>

                <div class="form-floating">
                    <input type="password" class="form-control" id="passwordInput" name="password" placeholder="Passwort" required>
                    <label for="passwordInput">Passwort</label>
                </div>

                <button class="btn btn-primary btn-lg w-100" type="submit">Anmelden</button>
            </form>
        </div>
    </div>
</section>
{% endblock %}
```

- [ ] **Step 3: Add shared auth styling rules to the global stylesheet**

```css
.auth-shell {
    min-height: calc(100vh - 8rem);
    display: grid;
    place-items: center;
}

.auth-card {
    display: grid;
    grid-template-columns: minmax(260px, 360px) minmax(320px, 460px);
    overflow: hidden;
}

.auth-brand-panel {
    background: linear-gradient(160deg, #18212b 0%, #243244 100%);
    color: #fff;
    padding: 2rem;
}

@media (max-width: 991.98px) {
    .auth-card {
        grid-template-columns: 1fr;
    }
}
```

- [ ] **Step 4: Run the auth and password reset tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AuthFeatureTest.php tests/Feature/PasswordResetFeatureTest.php`

Expected: `OK`

- [ ] **Step 5: Commit the auth refresh**

```bash
git add templates/auth/login.twig templates/auth/forgot_password.twig templates/auth/reset_password.twig templates/auth/setup.twig tests/Feature/AuthFeatureTest.php public/css/style.css
git commit -m "feat: refresh auth screens"
```

## Task 7: Final Regression, Manual QA, And Cleanup

**Files:**

- Modify: `docs/superpowers/specs/2026-03-28-ui-modernization-design.md` (only if the implemented outcome forces an approved clarification)
- Test: `tests/Feature/AppSettingFeatureTest.php`
- Test: `tests/Feature/AuthFeatureTest.php`
- Test: `tests/Feature/PasswordResetFeatureTest.php`
- Test: `tests/Feature/FinanceFeatureTest.php`

- [ ] **Step 1: Run the full automated test suite**

Run: `ddev exec php vendor/bin/phpunit`

Expected: `OK` for the entire suite.

- [ ] **Step 2: Lint the touched PHP files**

Run: `ddev exec php -l src/Controllers/AppSettingController.php`

Expected: `No syntax errors detected in src/Controllers/AppSettingController.php`

- [ ] **Step 3: Run PHP_CodeSniffer if PHP files changed materially**

Run: `ddev composer phpcs`

Expected: `OK` or a manageable list of only intentionally touched style violations to fix before merge.

- [ ] **Step 4: Perform manual responsive verification**

```text
Verify /dashboard, /events, /settings, /login, /forgot-password, /reset-password, and /setup on desktop and a narrow mobile viewport.
Confirm topbar behavior, page headers, alert states, form spacing, table responsiveness, and the primary color fallback when no custom color is saved.
```

- [ ] **Step 5: Commit the final validated UI refresh**

```bash
git add templates/layout.twig templates/dashboard/index.twig templates/events/index.twig templates/settings/index.twig templates/auth/login.twig templates/auth/forgot_password.twig templates/auth/reset_password.twig templates/auth/setup.twig public/css/style.css public/css/responsive-tables.css src/Controllers/AppSettingController.php src/Routes.php src/Dependencies.php tests/Feature/AppSettingFeatureTest.php tests/Feature/AuthFeatureTest.php
git commit -m "feat: implement modernized application ui"
```

## Task 8: Apply Shell Pattern To Master Data Domains

**Files:**

- Modify: `templates/users/manage.twig`
- Modify: `templates/projects/index.twig`
- Modify: `templates/projects/members.twig`
- Modify: `templates/roles/index.twig`
- Modify: `templates/voice_groups/index.twig`
- Modify: `templates/settings/event_types.twig`
- Create: `tests/Feature/ProjectFeatureTest.php`
- Create: `tests/Feature/VoiceGroupFeatureTest.php`
- Create: `tests/Feature/EventTypeFeatureTest.php`
- Test: `tests/Feature/UserManagementFeatureTest.php`
- Test: `tests/Feature/RoleFeatureTest.php`

- [ ] **Step 1: Add a standardized page header block to each listed master-data template**

```twig
{% block page_header %}
<section class="page-header">
    <div>
        <p class="text-uppercase text-muted small mb-1">Verwaltung</p>
        <h1 class="h2 mb-1">{{ page_title }}</h1>
        <p class="text-muted mb-0">{{ page_subtitle }}</p>
    </div>
    <div class="page-actions">
        {{ page_actions|raw }}
    </div>
</section>
{% endblock %}
```

- [ ] **Step 2: Wrap each main table or form area in shared surfaces**

```twig
<section class="surface-card filter-surface mb-4">
    <div class="card-body p-4">
        {# existing filter controls #}
    </div>
</section>

<section class="surface-card table-shell">
    <div class="table-responsive">
        {# existing table markup #}
    </div>
</section>
```

- [ ] **Step 3: Add and run master-data structure tests for project, voice group, and event type pages**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/ProjectFeatureTest.php tests/Feature/VoiceGroupFeatureTest.php tests/Feature/EventTypeFeatureTest.php`

Expected: `OK`

- [ ] **Step 4: Verify existing user and role structure tests stay green**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/UserManagementFeatureTest.php tests/Feature/RoleFeatureTest.php`

Expected: `OK`

- [ ] **Step 5: Commit master-data wave changes**

```bash
git add templates/users/manage.twig templates/projects/index.twig templates/projects/members.twig templates/roles/index.twig templates/voice_groups/index.twig templates/settings/event_types.twig
git add tests/Feature/ProjectFeatureTest.php tests/Feature/VoiceGroupFeatureTest.php tests/Feature/EventTypeFeatureTest.php
git commit -m "feat: apply modern ui pattern to master data domains"
```

## Task 9: Apply Shell Pattern To Attendance And Evaluation Domains

**Files:**

- Modify: `templates/attendance/show.twig`
- Modify: `templates/evaluations/index.twig`
- Modify: `templates/evaluations/project_members.twig`
- Create: `tests/Feature/AttendanceFeatureTest.php`
- Create: `tests/Feature/EvaluationFeatureTest.php`

- [ ] **Step 1: Add consistent page header to attendance and evaluation templates**

```twig
{% block page_header %}
<section class="page-header">
    <div>
        <p class="text-uppercase text-muted small mb-1">Auswertung</p>
        <h1 class="h2 mb-1">{{ page_title }}</h1>
        <p class="text-muted mb-0">{{ page_subtitle }}</p>
    </div>
</section>
{% endblock %}
```

- [ ] **Step 2: Use shared surfaces for filters, results, and table/detail sections**

```twig
<section class="surface-card filter-surface mb-4">
    <div class="card-body p-4">{{ filters|raw }}</div>
</section>

<section class="surface-card table-shell">
    <div class="card-body p-0">{{ results|raw }}</div>
</section>
```

- [ ] **Step 3: Add and run attendance/evaluation structure tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttendanceFeatureTest.php tests/Feature/EvaluationFeatureTest.php`

Expected: `OK`

- [ ] **Step 4: Commit attendance/evaluation wave changes**

```bash
git add templates/attendance/show.twig templates/evaluations/index.twig templates/evaluations/project_members.twig
git add tests/Feature/AttendanceFeatureTest.php tests/Feature/EvaluationFeatureTest.php
git commit -m "feat: apply modern ui pattern to attendance and evaluations"
```

## Task 10: Apply Shell Pattern To Finance And Song Library Domains

**Files:**

- Modify: `templates/finances/index.twig`
- Modify: `templates/finances/report.twig`
- Modify: `templates/songs/manage.twig`
- Modify: `templates/songs/downloads.twig`
- Test: `tests/Feature/FinanceFeatureTest.php`
- Test: `tests/Feature/SongLibraryFeatureTest.php`
- Test: `tests/Feature/DownloadFeatureTest.php`

- [ ] **Step 1: Add standardized headers and action bars to finance and song templates**

```twig
{% block page_header %}
<section class="page-header">
    <div>
        <p class="text-uppercase text-muted small mb-1">Bereiche</p>
        <h1 class="h2 mb-1">{{ page_title }}</h1>
    </div>
    <div class="page-actions">{{ actions|raw }}</div>
</section>
{% endblock %}
```

- [ ] **Step 2: Ensure lists, uploads, and reports use shared surface containers**

```twig
<section class="surface-card form-surface mb-4">
    <div class="card-body p-4">{{ form_or_filters|raw }}</div>
</section>

<section class="surface-card table-shell">
    <div class="table-responsive">{{ table_or_report|raw }}</div>
</section>
```

- [ ] **Step 3: Run related domain tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/FinanceFeatureTest.php tests/Feature/SongLibraryFeatureTest.php tests/Feature/DownloadFeatureTest.php`

Expected: `OK`

- [ ] **Step 4: Commit finance/song wave changes**

```bash
git add templates/finances/index.twig templates/finances/report.twig templates/songs/manage.twig templates/songs/downloads.twig
git commit -m "feat: apply modern ui pattern to finance and songs"
```

## Task 11: Apply Shell Pattern To Newsletter And Sponsoring Domains

**Files:**

- Modify: `templates/newsletters/index.twig`
- Modify: `templates/newsletters/create.twig`
- Modify: `templates/newsletters/edit.twig`
- Modify: `templates/newsletters/preview.twig`
- Modify: `templates/newsletters/archive.twig`
- Modify: `templates/newsletters/locked.twig`
- Modify: `templates/sponsoring/dashboard.twig`
- Modify: `templates/sponsoring/packages/index.twig`
- Modify: `templates/sponsoring/sponsors/index.twig`
- Modify: `templates/sponsoring/sponsors/detail.twig`
- Test: `tests/Feature/NewsletterFeatureTest.php`
- Test: `tests/Feature/SponsoringFeatureTest.php`

- [ ] **Step 1: Standardize page headers across newsletter and sponsoring templates**

```twig
{% block page_header %}
<section class="page-header">
    <div>
        <p class="text-uppercase text-muted small mb-1">Kommunikation</p>
        <h1 class="h2 mb-1">{{ page_title }}</h1>
        <p class="text-muted mb-0">{{ page_subtitle }}</p>
    </div>
    <div class="page-actions">{{ page_actions|raw }}</div>
</section>
{% endblock %}
```

- [ ] **Step 2: Use shared list/form/detail surfaces for all newsletter and sponsor views**

```twig
<section class="surface-card form-surface mb-4">
    <div class="card-body p-4">{{ editor_or_form|raw }}</div>
</section>

<section class="surface-card table-shell">
    <div class="card-body p-0">{{ list_or_detail|raw }}</div>
</section>
```

- [ ] **Step 3: Run related domain tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php tests/Feature/SponsoringFeatureTest.php`

Expected: `OK`

- [ ] **Step 4: Commit newsletter/sponsoring wave changes**

```bash
git add templates/newsletters/index.twig templates/newsletters/create.twig templates/newsletters/edit.twig templates/newsletters/preview.twig templates/newsletters/archive.twig templates/newsletters/locked.twig templates/sponsoring/dashboard.twig templates/sponsoring/packages/index.twig templates/sponsoring/sponsors/index.twig templates/sponsoring/sponsors/detail.twig
git commit -m "feat: apply modern ui pattern to newsletters and sponsoring"
```

## Task 12: All-Areas Acceptance Gate

**Files:**

- Test: `tests/Feature/*.php`

- [ ] **Step 1: Run complete feature suite for all areas**

Run: `ddev exec php vendor/bin/phpunit tests/Feature`

Expected: `OK` for all feature tests.

- [ ] **Step 2: Run full suite and coding standards**

Run: `ddev exec php vendor/bin/phpunit`

Expected: `OK`

Run: `ddev composer phpcs`

Expected: no blocking violations.

- [ ] **Step 3: Perform manual responsive walkthrough of every template domain**

```text
Validate desktop and mobile rendering for attendance, auth, dashboard, evaluations, events, finances, newsletters, profile, projects, roles, settings, songs, sponsoring, users, and voice_groups.
Confirm consistent page headers, action bars, filter surfaces, table surfaces, form surfaces, and alert states.
```

- [ ] **Step 4: Commit all-area completion checkpoint**

```bash
git add templates public/css tests/Feature
git commit -m "feat: complete modern ui rollout across all areas"
```

## Self-Review

- Spec coverage check: the plan covers shared shell, configurable primary color, and explicit rollout for all template domains listed in the spec.
- Placeholder scan: no `TODO`, `TBD`, ellipsis placeholders, or "similar to previous task" shortcuts remain.
- Type consistency check: the plan consistently uses `themeCss()`, `normalizePrimaryColor()`, `primary_color`, and `/theme.css` across controller, route, template, and test tasks.