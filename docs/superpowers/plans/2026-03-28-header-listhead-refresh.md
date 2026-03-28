# Header & Listhead Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace heavy black table/card headers and the flat dark topbar with a cohesive corporate-neutral style — anthracite gradient topbar, primary-color accent line, and a light-neutral listhead surface — without touching any backend, route, or data-model code.

**Architecture:** All visual changes live in `public/css/style.css`. New CSS custom properties (`--header-*`, `--listhead-*`) extend the existing token set and inherit the dynamic primary color. The global `.card > .card-header` rule uses `:not()` guards to preserve semantic Bootstrap utility colors (success, danger, warning). Three template files receive minimal class cleanup to remove misleading Bootstrap helpers that the new CSS overrides anyway.

**Tech Stack:** Bootstrap 5 CSS custom properties, PHPUnit (`LayoutFeatureTest`) for automated assertions, TwigCS for template linting, DDEV for all PHP/test execution.

---

## File Structure

| File | Change |
|---|---|
| `public/css/style.css` | Add tokens to `:root`; update topbar rule; update active nav rule; add thead section; add card-header section; add narrow-viewport media query section |
| `tests/Feature/LayoutFeatureTest.php` | Add 6 new test methods (one per substantive CSS task) |
| `templates/attendance/show.twig` | Remove `bg-dark text-white` from card-header (line 71) |
| `templates/evaluations/project_members.twig` | Remove `bg-dark text-white` from card-header (line 49) |
| `templates/profile/index.twig` | Remove `bg-dark text-white` (line 29) and `bg-secondary text-white` (line 90) from card-headers |

**Files NOT modified:** `templates/layout.twig`, `public/css/table-engine.css`, `public/css/responsive-tables.css`, all other templates. Card-headers with semantic Bootstrap colors (`bg-success`, `bg-danger`, `bg-warning`) are preserved by CSS `:not()` guards — no template changes needed for them.

**CSS cascade order within `style.css` (after all tasks):**
1. `:root` tokens (existing + new from Task 1)
2. Body / shell / page-header styles (existing)
3. Navbar rules (existing, updated in Tasks 2–3)
4. Navbar responsive media queries (existing)
5. **[Task 4]** thead harmonization rules
6. **[Task 5]** card-header harmonization rule
7. **[Task 7]** narrow-viewport refinements `@media (max-width: 767.98px)` (second block, wins over base rules above)
8. Button / badge / link / auth styles (existing)

---

## Task 1: Add header and listhead tokens to `:root`

**Files:**
- Modify: `public/css/style.css`
- Modify: `tests/Feature/LayoutFeatureTest.php`

- [ ] **Step 1: Write the failing test**

  Add the following method inside the `LayoutFeatureTest` class in `tests/Feature/LayoutFeatureTest.php`, after the last existing test method (`testPageHeaderCssWrapsActionsToAvoidHorizontalOverflow`):

  ```php
  public function testCssDefinesHeaderAndListheadTokens(): void
  {
      $stylePath = dirname(__DIR__) . '/../public/css/style.css';
      $styleContent = file_get_contents($stylePath);

      $this->assertIsString($styleContent);
      $this->assertStringContainsString('--header-bg-start:', $styleContent);
      $this->assertStringContainsString('--header-bg-end:', $styleContent);
      $this->assertStringContainsString('--header-accent-line:', $styleContent);
      $this->assertStringContainsString('--listhead-bg:', $styleContent);
      $this->assertStringContainsString('--listhead-text:', $styleContent);
      $this->assertStringContainsString('--listhead-border:', $styleContent);
  }
  ```

- [ ] **Step 2: Run the test to verify it fails**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesHeaderAndListheadTokens
  ```

  Expected: `FAILED` — `Failed asserting that '...' contains '--header-bg-start:'`

- [ ] **Step 3: Add the new tokens to `:root` in `style.css`**

  In `public/css/style.css`, the `:root` block currently ends with:

  ```css
      --theme-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);

      --bs-dark: #2b2b2b;
      --bs-dark-rgb: 43, 43, 43;
  }
  ```

  Insert the new token group before the closing `}`:

  ```css
      --theme-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);

      --bs-dark: #2b2b2b;
      --bs-dark-rgb: 43, 43, 43;

      /* Header & listhead tokens */
      --header-bg-start: #243244;
      --header-bg-end: #18212b;
      --header-border-subtle: rgba(255, 255, 255, 0.08);
      --header-accent-line: var(--theme-primary, #E8A817);
      --listhead-bg: #eef1f5;
      --listhead-text: #1f2937;
      --listhead-border: #d9dee7;
  }
  ```

- [ ] **Step 4: Run the test to verify it passes**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesHeaderAndListheadTokens
  ```

  Expected: `OK (1 test, 7 assertions)`

- [ ] **Step 5: Run the full suite to confirm no regressions**

  ```bash
  ddev exec php vendor/bin/phpunit
  ```

  Expected: all existing tests still pass, count unchanged.

- [ ] **Step 6: Commit**

  ```bash
  git add public/css/style.css tests/Feature/LayoutFeatureTest.php
  git commit -m "style: add header and listhead CSS tokens to :root"
  ```

---

## Task 2: Apply topbar gradient and accent bottom border

**Files:**
- Modify: `public/css/style.css`
- Modify: `tests/Feature/LayoutFeatureTest.php`

- [ ] **Step 1: Write the failing test**

  Add to `tests/Feature/LayoutFeatureTest.php`:

  ```php
  public function testTopbarCssUsesGradientWithAccentBorder(): void
  {
      $stylePath = dirname(__DIR__) . '/../public/css/style.css';
      $styleContent = file_get_contents($stylePath);

      $this->assertIsString($styleContent);
      // Must use a gradient instead of a flat rgba() background
      $this->assertStringContainsString('linear-gradient(135deg', $styleContent);
      // Accent border must reference the header token
      $this->assertStringContainsString('3px solid var(--header-accent-line', $styleContent);
      // Must carry explicit box-shadow for depth
      $this->assertStringContainsString('box-shadow: 0 2px 16px rgba(0, 0, 0, 0.4)', $styleContent);
  }
  ```

- [ ] **Step 2: Run the test to verify it fails**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testTopbarCssUsesGradientWithAccentBorder
  ```

  Expected: `FAILED` — `Failed asserting that '...' contains 'linear-gradient(135deg'`

- [ ] **Step 3: Update the `.navbar.bg-dark.app-topbar` rule in `style.css`**

  Find:

  ```css
  /* Navbar Styling */
  .navbar.bg-dark.app-topbar {
      background: rgba(24, 33, 43, 0.94) !important;
      backdrop-filter: blur(12px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  }
  ```

  Replace with:

  ```css
  /* Navbar Styling */
  .navbar.bg-dark.app-topbar {
      background: linear-gradient(135deg, rgba(36, 50, 68, 0.97) 0%, rgba(24, 33, 43, 0.97) 100%) !important;
      backdrop-filter: blur(12px);
      border-bottom: 3px solid var(--header-accent-line, #E8A817);
      box-shadow: 0 2px 16px rgba(0, 0, 0, 0.4);
  }
  ```

  The two rgba() color-stops match the `--header-bg-start` (#243244 → rgb(36,50,68)) and `--header-bg-end` (#18212b → rgb(24,33,43)) token values at 97% opacity, keeping the backdrop blur subtly visible.

- [ ] **Step 4: Run the test to verify it passes**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testTopbarCssUsesGradientWithAccentBorder
  ```

  Expected: `OK (1 test, 4 assertions)`

- [ ] **Step 5: Run the full suite**

  ```bash
  ddev exec php vendor/bin/phpunit
  ```

  Expected: green.

- [ ] **Step 6: Commit**

  ```bash
  git add public/css/style.css tests/Feature/LayoutFeatureTest.php
  git commit -m "style: apply anthracite gradient and primary-accent border to topbar"
  ```

---

## Task 3: Style active nav link as accent chip

**Files:**
- Modify: `public/css/style.css`
- Modify: `tests/Feature/LayoutFeatureTest.php`

- [ ] **Step 1: Write the failing test**

  Add to `tests/Feature/LayoutFeatureTest.php`:

  ```php
  public function testCssDefinesNavActiveChipStyle(): void
  {
      $stylePath = dirname(__DIR__) . '/../public/css/style.css';
      $styleContent = file_get_contents($stylePath);

      $this->assertIsString($styleContent);
      // Active link must have a primary-tinted background chip
      $this->assertStringContainsString(
          'background: rgba(var(--theme-primary-rgb), 0.12)',
          $styleContent
      );
      // Must be visually contained with a border-radius
      $this->assertStringContainsString('border-radius: 0.375rem', $styleContent);
  }
  ```

- [ ] **Step 2: Run the test to verify it fails**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesNavActiveChipStyle
  ```

  Expected: `FAILED` — `Failed asserting that '...' contains 'background: rgba(var(--theme-primary-rgb), 0.12)'`

- [ ] **Step 3: Update the active nav link rule in `style.css`**

  Find:

  ```css
  .navbar-dark .nav-link.active,
  .navbar-dark .nav-link.dropdown-toggle.active {
      color: var(--theme-primary, #E8A817);
  }
  ```

  Replace with:

  ```css
  .navbar-dark .nav-link.active,
  .navbar-dark .nav-link.dropdown-toggle.active {
      color: var(--theme-primary, #E8A817);
      background: rgba(var(--theme-primary-rgb), 0.12);
      border-radius: 0.375rem;
  }
  ```

- [ ] **Step 4: Run the test to verify it passes**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesNavActiveChipStyle
  ```

  Expected: `OK (1 test, 3 assertions)`

- [ ] **Step 5: Run the full suite**

  ```bash
  ddev exec php vendor/bin/phpunit
  ```

  Expected: green.

- [ ] **Step 6: Commit**

  ```bash
  git add public/css/style.css tests/Feature/LayoutFeatureTest.php
  git commit -m "style: add primary-accent chip treatment to active nav links"
  ```

---

## Task 4: Harmonize table `thead` styles

**Files:**
- Modify: `public/css/style.css` (add new section after the `@media (max-width: 767.98px)` block)
- Modify: `tests/Feature/LayoutFeatureTest.php`

**Context:** Templates use three `thead` variants: `thead.table-dark` (events, users, evaluations, finances index, roles), `thead.table-light` (finances report, sponsoring, newsletters), and `thead` with no class (song downloads, song manage). All three must render identically under the corporate neutral style.

- [ ] **Step 1: Write the failing test**

  Add to `tests/Feature/LayoutFeatureTest.php`:

  ```php
  public function testCssDefinesListheadHarmonizationRules(): void
  {
      $stylePath = dirname(__DIR__) . '/../public/css/style.css';
      $styleContent = file_get_contents($stylePath);

      $this->assertIsString($styleContent);
      // All three thead variants must be targeted
      $this->assertStringContainsString('thead.table-dark', $styleContent);
      $this->assertStringContainsString('thead.table-light', $styleContent);
      $this->assertStringContainsString('thead:not([class])', $styleContent);
      // Must use listhead tokens
      $this->assertStringContainsString('var(--listhead-bg', $styleContent);
      $this->assertStringContainsString('var(--listhead-text', $styleContent);
      // Accent bottom-border on thead
      $this->assertMatchesRegularExpression(
          '/thead\.(table-dark|table-light).*border-bottom: 3px solid var\(--header-accent-line/s',
          $styleContent
      );
      // Typographic treatment on th cells
      $this->assertStringContainsString('text-transform: uppercase', $styleContent);
      $this->assertStringContainsString('letter-spacing: 0.04em', $styleContent);
  }
  ```

- [ ] **Step 2: Run the test to verify it fails**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesListheadHarmonizationRules
  ```

  Expected: `FAILED`

- [ ] **Step 3: Add thead rules to `style.css`**

  Locate the end of the `@media (max-width: 767.98px)` block. It currently ends with:

  ```css
      .navbar-dark .navbar-brand {
          max-width: calc(100% - 2.75rem);
      }
  }
  ```

  Add the following new section immediately after that closing `}`, before the `/* Primary Button Overrides */` comment:

  ```css
  /* =====================================================================
     Corporate Listhead
     Applies to all three table header variants: .table-dark, .table-light,
     and bare thead with no class. All render as the same light-neutral surface
     with an accent bottom border.
     Bootstrap reads --bs-table-bg and --bs-table-color on thead and cascades
     them down to tr and td/th via its own .table > :not(caption) > * > * rule.
     The `thead th` block adds direct cell overrides as a safe fallback and
     applies the typographic treatment.
     ===================================================================== */
  thead.table-dark,
  thead.table-light,
  thead:not([class]) {
      --bs-table-bg: var(--listhead-bg, #eef1f5);
      --bs-table-color: var(--listhead-text, #1f2937);
      --bs-table-border-color: var(--listhead-border, #d9dee7);
      border-bottom: 3px solid var(--header-accent-line, #E8A817);
  }

  thead th {
      font-weight: 600;
      letter-spacing: 0.04em;
      font-size: 0.8125rem;
      text-transform: uppercase;
      color: var(--listhead-text, #1f2937);
      background-color: var(--listhead-bg, #eef1f5);
  }
  ```

- [ ] **Step 4: Run the test to verify it passes**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesListheadHarmonizationRules
  ```

  Expected: `OK (1 test, 8 assertions)`

- [ ] **Step 5: Run the full suite**

  ```bash
  ddev exec php vendor/bin/phpunit
  ```

  Expected: green.

- [ ] **Step 6: Commit**

  ```bash
  git add public/css/style.css tests/Feature/LayoutFeatureTest.php
  git commit -m "style: harmonize table thead styles with corporate listhead tokens"
  ```

---

## Task 5: Harmonize `.card-header` globally

**Files:**
- Modify: `public/css/style.css` (add new section after the thead section from Task 4)
- Modify: `tests/Feature/LayoutFeatureTest.php`

**Context:** Card-headers in templates use many different Bootstrap backgrounds: `bg-dark`, `bg-secondary`, `bg-white`, `bg-light`, and semantic ones (`bg-success`, `bg-danger`, `bg-warning`, `bg-info`). The CSS rule uses `:not()` guards to preserve semantic variants while overriding all others. The `!important` on background/color is required because Bootstrap's utility classes also use `!important` — the higher specificity of our selector wins among all `!important` declarations.

- [ ] **Step 1: Write the failing test**

  Add to `tests/Feature/LayoutFeatureTest.php`:

  ```php
  public function testCssDefinesCardHeaderHarmonizationRule(): void
  {
      $stylePath = dirname(__DIR__) . '/../public/css/style.css';
      $styleContent = file_get_contents($stylePath);

      $this->assertIsString($styleContent);
      // Global selector with semantic guards must exist
      $this->assertStringContainsString(
          '.card > .card-header:not(.bg-success):not(.bg-danger):not(.bg-warning)',
          $styleContent
      );
      // Must use the listhead background token with !important to beat Bootstrap utilities
      $this->assertStringContainsString(
          'var(--listhead-bg, #eef1f5) !important',
          $styleContent
      );
      // Must carry the accent bottom border
      $this->assertStringContainsString(
          'border-bottom: 3px solid var(--header-accent-line',
          $styleContent
      );
  }
  ```

- [ ] **Step 2: Run the test to verify it fails**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesCardHeaderHarmonizationRule
  ```

  Expected: `FAILED`

- [ ] **Step 3: Add the card-header rule to `style.css`**

  Locate the end of the thead section added in Task 4:

  ```css
  thead th {
      font-weight: 600;
      letter-spacing: 0.04em;
      font-size: 0.8125rem;
      text-transform: uppercase;
      color: var(--listhead-text, #1f2937);
      background-color: var(--listhead-bg, #eef1f5);
  }
  ```

  Add the following new section immediately after it:

  ```css
  /* =====================================================================
     Corporate Card Header
     Harmonized with listhead style. Semantic Bootstrap header colors
     (success / danger / warning / info) are excluded via :not() guards so
     they retain their communicative meaning.
     !important is required: Bootstrap utility classes (.bg-dark, .bg-white,
     .bg-light etc.) also declare background-color with !important. Our
     selector has higher specificity, so our !important rule wins.
     ===================================================================== */
  .card > .card-header:not(.bg-success):not(.bg-danger):not(.bg-warning):not(.bg-info) {
      background-color: var(--listhead-bg, #eef1f5) !important;
      color: var(--listhead-text, #1f2937) !important;
      border-bottom: 3px solid var(--header-accent-line, #E8A817);
      font-weight: 600;
  }
  ```

- [ ] **Step 4: Run the test to verify it passes**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesCardHeaderHarmonizationRule
  ```

  Expected: `OK (1 test, 4 assertions)`

- [ ] **Step 5: Run the full suite**

  ```bash
  ddev exec php vendor/bin/phpunit
  ```

  Expected: green.

- [ ] **Step 6: Commit**

  ```bash
  git add public/css/style.css tests/Feature/LayoutFeatureTest.php
  git commit -m "style: harmonize card-header with corporate listhead style"
  ```

---

## Task 6: Template cleanup — remove misleading dark classes from card-headers

The CSS from Task 5 already corrects the visuals. This task removes the now-incorrect Bootstrap classes from template markup to keep the HTML honest — a developer reading `class="card-header bg-dark text-white"` should not be surprised that the result renders as a light neutral surface.

**Files:**
- Modify: `templates/attendance/show.twig`
- Modify: `templates/evaluations/project_members.twig`
- Modify: `templates/profile/index.twig`
- Modify: `tests/Feature/LayoutFeatureTest.php`

- [ ] **Step 1: Write the failing test**

  Add to `tests/Feature/LayoutFeatureTest.php`:

  ```php
  public function testTemplateCardHeadersDoNotCarryMisleadingDarkClasses(): void
  {
      $base = dirname(__DIR__) . '/..';
      $templates = [
          $base . '/templates/attendance/show.twig',
          $base . '/templates/evaluations/project_members.twig',
          $base . '/templates/profile/index.twig',
      ];

      foreach ($templates as $path) {
          $content = file_get_contents($path);
          $this->assertIsString($content);
          $this->assertStringNotContainsString(
              'card-header bg-dark',
              $content,
              basename($path) . ' still carries card-header bg-dark'
          );
          $this->assertStringNotContainsString(
              'card-header bg-secondary',
              $content,
              basename($path) . ' still carries card-header bg-secondary'
          );
      }
  }
  ```

- [ ] **Step 2: Run the test to verify it fails**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testTemplateCardHeadersDoNotCarryMisleadingDarkClasses
  ```

  Expected: `FAILED` — at least `show.twig` triggers the assertion.

- [ ] **Step 3: Edit `templates/attendance/show.twig`**

  Find (line 71):

  ```twig
                      <div class="card-header bg-dark text-white">
  ```

  Replace with:

  ```twig
                      <div class="card-header">
  ```

- [ ] **Step 4: Edit `templates/evaluations/project_members.twig`**

  Find (line 49):

  ```twig
          <div class="card-header bg-dark text-white">
  ```

  Replace with:

  ```twig
          <div class="card-header">
  ```

- [ ] **Step 5: Edit `templates/profile/index.twig` — first card-header**

  Find (line 29):

  ```twig
              <div class="card-header bg-dark text-white">
  ```

  Replace with:

  ```twig
              <div class="card-header">
  ```

- [ ] **Step 6: Edit `templates/profile/index.twig` — second card-header**

  Find (line 90):

  ```twig
              <div class="card-header bg-secondary text-white">
  ```

  Replace with:

  ```twig
              <div class="card-header">
  ```

- [ ] **Step 7: Run the failing test to verify it now passes**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testTemplateCardHeadersDoNotCarryMisleadingDarkClasses
  ```

  Expected: `OK (1 test, 6 assertions)`

- [ ] **Step 8: Run TwigCS**

  ```bash
  ddev composer twigcs
  ```

  Expected: no errors or warnings on modified templates.

- [ ] **Step 9: Run the full suite**

  ```bash
  ddev exec php vendor/bin/phpunit
  ```

  Expected: green.

- [ ] **Step 10: Commit**

  ```bash
  git add templates/attendance/show.twig \
          templates/evaluations/project_members.twig \
          templates/profile/index.twig \
          tests/Feature/LayoutFeatureTest.php
  git commit -m "style: remove misleading bg-dark/bg-secondary from card-header templates"
  ```

---

## Task 7: Responsive refinements for narrow viewports

Reduce visual density of list heads and card headers at narrow widths. This is a second `@media (max-width: 767.98px)` block placed after the thead and card-header base rules (Tasks 4–5) so the narrower values correctly override the base styles in the cascade.

**Files:**
- Modify: `public/css/style.css` (add new media query section after the card-header section from Task 5)
- Modify: `tests/Feature/LayoutFeatureTest.php`

- [ ] **Step 1: Write the failing test**

  Add to `tests/Feature/LayoutFeatureTest.php`:

  ```php
  public function testCssDefinesNarrowViewportListheadRefinements(): void
  {
      $stylePath = dirname(__DIR__) . '/../public/css/style.css';
      $styleContent = file_get_contents($stylePath);

      $this->assertIsString($styleContent);
      // A narrow-viewport thead th override must exist
      // Verify by checking for the exact font-size reduction value
      $this->assertStringContainsString('font-size: 0.75rem', $styleContent);
      // The responsive card-header padding reduction must exist
      $this->assertStringContainsString('padding: 0.625rem 1rem', $styleContent);
      // Confirm the narrow styles live inside a 767.98px media query
      $this->assertMatchesRegularExpression(
          '/@media \(max-width: 767\.98px\).*font-size: 0\.75rem/s',
          $styleContent
      );
  }
  ```

- [ ] **Step 2: Run the test to verify it fails**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesNarrowViewportListheadRefinements
  ```

  Expected: `FAILED`

- [ ] **Step 3: Add the narrow-viewport section to `style.css`**

  Locate the end of the card-header section added in Task 5:

  ```css
  .card > .card-header:not(.bg-success):not(.bg-danger):not(.bg-warning):not(.bg-info) {
      background-color: var(--listhead-bg, #eef1f5) !important;
      color: var(--listhead-text, #1f2937) !important;
      border-bottom: 3px solid var(--header-accent-line, #E8A817);
      font-weight: 600;
  }
  ```

  Add the following section immediately after it:

  ```css
  /* Narrow-viewport refinements for listhead and card-header.
     This block must come after the base thead and card-header rules above
     so that at ≤767.98px these values override them in the cascade. */
  @media (max-width: 767.98px) {
      thead th {
          font-size: 0.75rem;
          letter-spacing: 0.02em;
          padding-top: 0.5rem;
          padding-bottom: 0.5rem;
      }

      .card > .card-header:not(.bg-success):not(.bg-danger):not(.bg-warning):not(.bg-info) {
          padding: 0.625rem 1rem;
      }
  }
  ```

- [ ] **Step 4: Run the test to verify it passes**

  ```bash
  ddev exec php vendor/bin/phpunit tests/Feature/LayoutFeatureTest.php --filter testCssDefinesNarrowViewportListheadRefinements
  ```

  Expected: `OK (1 test, 4 assertions)`

- [ ] **Step 5: Run the full suite**

  ```bash
  ddev exec php vendor/bin/phpunit
  ```

  Expected: green. `LayoutFeatureTest` now shows 11 test methods.

- [ ] **Step 6: Commit**

  ```bash
  git add public/css/style.css tests/Feature/LayoutFeatureTest.php
  git commit -m "style: reduce listhead and card-header density on narrow viewports"
  ```

---

## Task 8: Final suite run and acceptance QA

No code changes — verification only.

- [ ] **Step 1: Run the complete PHPUnit suite**

  ```bash
  ddev exec php vendor/bin/phpunit
  ```

  Expected: all tests pass. `LayoutFeatureTest` shows 11 tests, 0 failures, 0 errors.

- [ ] **Step 2: Run TwigCS on all templates**

  ```bash
  ddev composer twigcs
  ```

  Expected: no errors or warnings.

- [ ] **Step 3: Manual viewport checks**

  Open each URL in a browser. Resize the window to each breakpoint.

  | Page | 586px | 768px | 992px | 1200px |
  |---|---|---|---|---|
  | /finances | ✓ | ✓ | ✓ | ✓ |
  | /users | ✓ | ✓ | ✓ | ✓ |
  | /sponsoring | ✓ | ✓ | ✓ | ✓ |
  | /downloads | ✓ | ✓ | ✓ | ✓ |

  **For each cell, verify:**
  - Topbar shows a visible gradient (lighter at top-left, darker at bottom-right)
  - A primary-color accent line (3px) is visible at the bottom of the topbar
  - Active nav item has a faint primary-colored chip background with rounded corners
  - No heavy black bars in any table header
  - Table header text is light-neutral background, dark text, uppercase, slightly smaller than body
  - Primary-color accent line (3px) visible at bottom of each table header
  - Card headers in /attendance, /evaluations, /profile show the neutral light surface (not black)
  - Finances report: green and red semantic card-headers are visually unchanged
  - No horizontal overflow or unconstrained text wrapping at any tested width
  - Burger menu at 586px: toggler visible; tap expands/collapses nav correctly

- [ ] **Step 4: Final commit**

  ```bash
  git add -A
  git commit -m "style: header and listhead visual refresh complete"
  ```

---

## Self-Review Checklist (completed before saving)

**Spec coverage:**
- Section 5.2 Topbar gradient → Task 2 ✓
- Section 5.2 Active nav chip → Task 3 ✓
- Section 5.3 Table/list header harmonization (both variants + bare) → Task 4 ✓
- Section 5.4 Card-header unification → Tasks 5 + 6 ✓
- Section 5.1 New tokens → Task 1 ✓
- Section 6 Responsive → Task 7 ✓
- Section 8.1 Automated tests → every task ✓
- Section 8.2 Manual QA spec pages + breakpoints → Task 8 Step 3 ✓
- Section 9 Phase 1 (topbar) → Tasks 1–3 ✓
- Section 9 Phase 2 (listhead/card) → Tasks 4–5 ✓
- Section 9 Phase 3 (template cleanup + QA) → Tasks 6–8 ✓

**No gaps found.**

**Placeholder scan:** No TBD / TODO / "similar to Task N" / "handle edge cases" phrases present.

**Type consistency:** CSS selector `.card > .card-header:not(.bg-success):not(.bg-danger):not(.bg-warning):not(.bg-info)` appears identically in Task 5 Step 3 (implementation), Task 5 Step 1 (test assertion), and Task 7 Step 3 (narrow media query). Token names (`--header-accent-line`, `--listhead-bg`, `--listhead-text`, `--listhead-border`) are consistent across all tasks that reference them. ✓
