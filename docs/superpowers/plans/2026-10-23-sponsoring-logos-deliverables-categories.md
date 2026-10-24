# Sponsoring: Kategorien, Dublettenwarnung, Leistungen des Sponsors, Logos mit Fristen – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das Sponsoring-Modul bekommt Kategorien am Sponsor, eine Dublettenwarnung, eine abhakbare
Liste der Leistungen des Sponsors und ein Logo je Sponsor mit Fristen aus dem Projekt samt
Dashboard-Liste „Logo fehlt“.

**Architecture:** Vier unabhängige Teile, je ein Commit. Schema über Phinx-Migrationen, Logik in
kleinen `App\Util`-Klassen mit eigenen Tests, Controller und Twig nach dem Muster der bestehenden
Gegenleistungen (`BenefitList`, `syncBenefits`, `toggleBenefit`, `openBenefits`). Rechte bleiben in
`SponsoringPolicy`.

**Tech Stack:** PHP 8 / Slim 4 / Eloquent (Capsule) / Twig / Phinx / PHPUnit, ddev.

**Spec:** `docs/superpowers/specs/2026-10-23-sponsoring-logos-deliverables-categories-design.md`

## Global Constraints

- Schema nur über Phinx-Migrationen (`/phinx-migration`). Jede Phinx-Kette endet mit
  `create()`/`save()`/`update()`. `down()` prüft vor dem Löschen auf eingetragene Daten, wie
  `20261012120000_add_sponsoring_benefits_and_payment::down()`.
- TDD: Test zuerst, rot sehen, dann umsetzen. Filterläufe:
  `ddev php vendor/bin/phpunit --filter "<Muster>"`. Volle Suite einmal am Ende:
  `ddev composer test:parallel`.
- `ddev composer phpcs` für PHP, `ddev composer twigcs` für Twig, beide ohne Verstöße.
- Bezeichner englisch, UI-Texte und Kommentare deutsch mit echten Umlauten.
- Kein Inline-CSS und kein Inline-JS. JS und CSS liegen lokal in `public/`. Mobil benutzbar.
- Logging über `LoggerInterface` mit stabilem `event`-Key; Ausnahmen unter `exception`.
- Seed-Daten pro Teil (`/dev-seed-completeness`), Zähler im Seed-Bericht.
- **Keine DB-Abfragen gegen die Dev-DB ohne Rückfrage**, falls dort Echtdaten liegen. Seed-Läufe
  nur nach Bestätigung durch Georg, dass die Dev-DB Seed-Daten enthält.
- Keine neuen Rollenrechte.
- Kein Hilfethema.

## Review Focus

1. Kategorie in anderer Schreibweise und mit Rand-Leerzeichen („ gastronomie “) verwendet die
   vorhandene Kategorie und legt keine neue an. Test in Task 1.
2. Dublette nur durch Rechtsform oder Satzzeichen unterschieden („Huber GmbH“ / „huber.“) wird
   erkannt. „Huber“ und „Hubert“ werden nicht gemeldet. Ein Sponsor ist beim Bearbeiten ohne
   Namensänderung keine Dublette seiner selbst. Tests in Task 2.
3. Bearbeiten einer Vereinbarung behält den Erhalten-Haken unveränderter Sponsor-Leistungen. Ein
   Array statt Text löscht die Liste nicht. Tests in Task 3.
4. Die zuständige Person darf nur das Logo hochladen, ersetzen und ansehen, nicht die anderen
   Anhänge, und nur bei einer zugesagten Vereinbarung. Nach Absage oder Abschluss endet das Recht.
   Tests in Task 4.
5. Ein Sponsor mit zwei zugesagten Vereinbarungen erscheint einmal mit der frühesten Frist.
   Druck-Bedarf ohne Druck-Frist fällt auf die Online-Frist zurück. Ohne Projekt oder Datum steht er
   unter „ohne Frist“ am Ende. Tests in Task 5.

---

### Task 1: Kategorien am Sponsor

**Files:**
- Create: `db/migrations/20261023120000_add_sponsor_categories.php`
- Create: `src/Models/SponsorCategory.php`
- Create: `src/Util/SponsorCategoryResolver.php`
- Modify: `src/Models/Sponsor.php` (fillable `category_id`, `category()`-Relation)
- Modify: `src/Controllers/SponsorController.php` (`validateSponsorInput`, `create`, `update`,
  `index` mit Filter, `buildSponsorRows`, `detail`)
- Modify: `templates/sponsoring/sponsors/index.twig`, `templates/sponsoring/sponsors/detail.twig`
- Modify: `src/Services/DevSeedService.php` (`seedSponsors`, Bericht `sponsor_categories`)
- Test: `tests/Feature/SponsorCategoryFeatureTest.php`

**Interfaces:**
- Produces: Tabelle `sponsor_categories(id INT PK, name VARCHAR(100) NOT NULL UNIQUE)`, Kollation
  utf8mb4_unicode_ci. Groß- und Kleinschreibung zählt damit nicht.
  `sponsors.category_id INT NULL`, FK → `sponsor_categories.id` ON DELETE SET NULL.
- Produces: `SponsorCategoryResolver::resolve(mixed $input): array{error: ?string, id: ?int}`.
  Leer heißt id null. Den Namen trimmt der Resolver und fasst mehrfache Leerzeichen zusammen.
  Vorhanden: dessen id. Neu: wird angelegt. Länger als 100 Zeichen: Fehler
  „Eine Kategorie darf höchstens 100 Zeichen lang sein.“
- Formularfeld heißt `category`, also Name statt id.

- [ ] **Step 1: Failing tests schreiben** in `SponsorCategoryFeatureTest`, nach dem Muster von
  `SponsoringBenefitsFeatureTest`: Transaktion, `TestHttpHelpers`, Controller direkt aufgerufen.
  - `testTheMigrationProvidesGeneralDefaultCategories`: Die Namen `Einzelhandel`, `Gastronomie`,
    `Handwerk`, `Dienstleistung`, `Banken & Versicherungen`, `Öffentliche Einrichtungen`, `Kultur`,
    `Medien`, `Privatperson` existieren.
  - `testCreatingASponsorWithAnExistingCategoryInOtherCaseReusesIt`: `category=' gastronomie '` →
    `sponsor.category_id` ist die id von `Gastronomie`. Die Zahl der Kategorien bleibt gleich.
  - `testCreatingASponsorWithANewCategoryCreatesIt`: `category='Skigebiete'` → eine Kategorie mehr,
    am Sponsor gesetzt.
  - `testAnEmptyCategoryLeavesTheSponsorWithoutCategory`: Update mit `category=''` → `category_id`
    ist null.
  - `testATooLongCategoryIsRejected`: 101 Zeichen → Fehlermeldung in `$_SESSION['error']`, keine
    neue Kategorie.
  - `testTheSponsorListFiltersByCategory`: zwei Sponsoren in verschiedenen Kategorien, GET
    `/sponsoring/sponsors?category=<id>` → nur der eine Name im HTML.
  - `testTheFormOffersExistingCategoriesAsSuggestions`: Das gerenderte Formular enthält
    `<datalist id="sponsor-category-options">` mit `Gastronomie`.

- [ ] **Step 2: Rot sehen:** `ddev php vendor/bin/phpunit --filter SponsorCategoryFeatureTest`.
  Erwartet: Tabelle oder Klasse fehlt.

- [ ] **Step 3: Migration schreiben.** Tabelle anlegen, die 9 Standard-Kategorien einfügen und
  `category_id` an `sponsors` mit FK ergänzen. `down()` bricht ab, wenn Sponsoren eine Kategorie
  tragen oder es mehr als die 9 Standard-Namen gibt. Ausführen:
  `ddev exec ./vendor/bin/phinx migrate` (die Test-DB migriert sich selbst).

- [ ] **Step 4: Model, Resolver und Controller umsetzen.**
  - Das Formular ist ein `<input list="sponsor-category-options" name="category">` mit
    `<datalist>`, im Anlegen- und im Bearbeiten-Modal.
  - Die Liste bekommt eine Spalte „Kategorie“ und ein Filter-Select `category`. Der Wert ist eine
    Kategorie-id, ungültig wird ignoriert.
  - Die Detailseite zeigt die Kategorie.

- [ ] **Step 5: Grün sehen** mit demselben Filterlauf. Dazu `ddev composer phpcs` und
  `ddev composer twigcs`.

- [ ] **Step 6: Seed.** In `seedSponsors` erhält jeder Sponsor bis auf zwei eine der
  Standard-Kategorien, einer zusätzlich eine eigene „Skigebiete“. Bericht-Zähler
  `sponsor_categories`. Die Tabelle `sponsor_categories` leert der Reset ohnehin, da er alle
  Tabellen außer `phinxlog` leert. Die Standard-Kategorien legt der Seed deshalb selbst wieder an
  (`firstOrCreate`).

- [ ] **Step 7: Commit** über `/git-commit` mit dem Betreff
  `feat(sponsoring): Kategorien am Sponsor, wählbar und direkt erweiterbar`.

### Task 2: Dublettenwarnung

**Files:**
- Create: `src/Util/SponsorNameNormalizer.php`
- Create: `src/Util/SponsorDuplicateFinder.php`
- Modify: `src/Controllers/SponsorController.php` (`create`, `update`, `index`, `detail`)
- Modify: `templates/sponsoring/sponsors/index.twig`, `templates/sponsoring/sponsors/detail.twig`
- Modify: `src/Services/DevSeedService.php` (Sponsorpaar mit ähnlichem Namen)
- Test: `tests/Unit/Util/SponsorNameNormalizerTest.php`, `tests/Feature/SponsorDuplicateWarningFeatureTest.php`

**Interfaces:**
- Produces: `SponsorNameNormalizer::normalize(string $name): string`.
  - Schritte: Kleinschreibung (`mb_strtolower`), `&` zu Leerzeichen, alle Zeichen außer
    Buchstaben, Ziffern und Leerraum entfernen, Leerraum zusammenfassen, trimmen.
  - Danach so lange eine Rechtsform am Ende abschneiden, bis keine mehr passt. Rechtsformen
    (nach dem Entfernen der Satzzeichen): `gmbh`, `gmbh co kg`, `co kg`, `kg`, `og`, `ag`, `eu`,
    `ev`, `co`, `mbh`, `gesmbh`, `ges m b h`. Die längste zuerst.
  - Konstante `LEGAL_FORMS`.
- Produces: `SponsorDuplicateFinder::find(string $name, ?int $exceptId = null): ?Sponsor`. Vergleicht
  die normalisierten Namen aller Sponsoren, ausgenommen `$exceptId`. Die Zahl der Sponsoren ist
  klein (Hunderte), PHP-seitiger Vergleich reicht. Ist der normalisierte Name leer, wird nichts
  gefunden.
- Formularfeld `confirm_duplicate=1` übergeht die Warnung.
- `ModalFormService`-Scopes: `sponsor_create` und `sponsor_edit_<id>`.

- [ ] **Step 1: Failing Unit-Tests** in `SponsorNameNormalizerTest`, per Data-Provider:
  - `'Bäckerei Huber GmbH'` → `'bäckerei huber'`
  - `'bäckerei  huber.'` → `'bäckerei huber'`
  - `'Huber GmbH & Co. KG'` → `'huber'`
  - `'Sport-Müller e.U.'` → `'sportmüller'`
  - `'Huber'` ≠ `'Hubert'`
  - `'GmbH'` → `''`

- [ ] **Step 2: Failing Feature-Tests** in `SponsorDuplicateWarningFeatureTest`:
  - `testCreatingASimilarNameWarnsAndDoesNotCreate`: Vorhanden ist „Bäckerei Huber GmbH“, POST
    create mit „bäckerei huber“. Erwartet:
    - Sponsor-Zahl unverändert.
    - `ModalFormService('sponsor_create')->getState()` mit `open_modal` true.
    - `form.name` ist „bäckerei huber“.
    - `error` ist `['duplicate_id' => <id>, 'duplicate_name' => 'Bäckerei Huber GmbH']`.
  - `testConfirmingTheDuplicateCreatesTheSponsor`: dasselbe mit `confirm_duplicate=1` → angelegt.
  - `testRenamingToASimilarNameWarns`: Update von Sponsor B auf den Namen von A mit Rechtsform →
    Name von B unverändert, Warnung im Scope `sponsor_edit_<B>`.
  - `testSavingASponsorWithoutRenamingDoesNotWarnAboutItself`: Update von A mit unverändertem
    Namen → gespeichert.
  - `testTheWarningLinksTheExistingSponsor`: Liste rendern, nachdem die Warnung gesetzt ist. Das HTML
    enthält `href="/sponsoring/sponsors/<id>"`, „Es gibt bereits einen ähnlichen Sponsor“ und den
    Knopf „Trotzdem anlegen“.

- [ ] **Step 3: Rot sehen:** `ddev php vendor/bin/phpunit --filter "SponsorNameNormalizer|SponsorDuplicateWarning"`

- [ ] **Step 4: Umsetzen.** In `create` und `update` wird nach erfolgreicher Validierung, ohne
  `confirm_duplicate` und nur bei geändertem Namen geprüft:
  - Bei einem Treffer: `setError('Es gibt bereits einen ähnlichen Sponsor.', $data, [...], false)`,
    dann Redirect.
  - Die Templates öffnen das Modal über `modal_form.twig` (`modal_open_attr`) mit den
    gemerkten Werten. Sie zeigen einen Hinweis mit Link und einen Submit-Knopf
    „Trotzdem anlegen“ bzw. „Trotzdem speichern“, der `confirm_duplicate=1` mitsendet.
  - `index` bzw. `detail` lesen den Zustand und räumen ihn danach ab (`clear()`).

- [ ] **Step 5: Grün sehen,** dazu phpcs und twigcs.

- [ ] **Step 6: Seed.** Ein zusätzlicher Sponsor, dessen Name sich nur durch eine Rechtsform von
  einem vorhandenen unterscheidet. Er wird mit Bestätigung angelegt, wie im Betrieb auch.

- [ ] **Step 7: Commit:** `feat(sponsoring): Hinweis auf ähnlich benannte Sponsoren beim Anlegen`

### Task 3: Leistungen des Sponsors

**Files:**
- Create: `db/migrations/20261023121000_add_sponsoring_deliverables.php`
- Create: `src/Models/SponsorPackageDeliverable.php`, `src/Models/SponsorshipDeliverable.php`
- Modify: `src/Util/BenefitList.php` (Bezeichnung der Liste als Parameter)
- Modify: `src/Models/SponsorPackage.php`, `src/Models/Sponsorship.php` (Relation `deliverables()`,
  sortiert wie `benefits()`)
- Modify: `src/Controllers/SponsorPackageController.php`, `src/Controllers/SponsorshipController.php`
  (Feld `deliverables`, `syncDeliverables`, `toggleDeliverable`)
- Modify: `src/Controllers/SponsorController.php` (Detail-Zeilen), `src/Controllers/SponsoringDashboardController.php`
  (`openDeliverables()`)
- Modify: `src/Routes.php`: `POST /sponsoring/sponsorship-deliverables/{id:[0-9]+}/toggle` neben der
  Benefit-Toggle-Route
- Modify: `templates/sponsoring/packages/index.twig`, `templates/sponsoring/sponsors/detail.twig`,
  `templates/sponsoring/dashboard.twig`, `templates/sponsoring/partials/benefit_list.twig`. Der
  Partial wird so verallgemeinert, dass er beide Listen rendert.
- Modify: `src/Services/DevSeedService.php`
- Test: `tests/Feature/SponsoringDeliverablesFeatureTest.php`

**Interfaces:**
- Produces: `sponsor_package_deliverables(id, package_id FK CASCADE, description VARCHAR(255), sort_order INT)`
  und `sponsorship_deliverables(id, sponsorship_id FK CASCADE, description VARCHAR(255), sort_order INT, received_at DATE NULL, received_by_user_id INT NULL FK users SET NULL)`.
  Indizes wie bei den Benefits.
- Produces: `BenefitList::parse(mixed $input, string $label = 'Gegenleistung'): array{error: ?string, items: ?list<string>}`.
  Die Meldungen setzen `$label` ein, z. B. „Eine Leistung des Sponsors darf höchstens 255 Zeichen
  lang sein.“ Der Aufruf für Sponsor-Leistungen übergibt `'Leistung des Sponsors'` (Plural in der
  Anzahl-Meldung: „Leistungen des Sponsors“). Für den Plural eine zweite Konstante oder einen
  Parameter, nach Wahl des Implementers. Bestehende Meldungen der Gegenleistungen bleiben wörtlich
  gleich.
- Toggle-Feld `received=1|0`. Das Recht prüft `SponsoringPolicy::canTickBenefits`.

- [ ] **Step 1: Failing tests** in `SponsoringDeliverablesFeatureTest`, als Spiegel der
  entsprechenden Tests in `SponsoringBenefitsFeatureTest`:
  - `testAPackageStoresItsDeliverablesOnePerLine`
  - `testANewAgreementTakesOverThePackageDeliverables`
  - `testEditingKeepsTheReceivedStateOfUnchangedLines`
  - `testAListThatIsNotTextIsRejectedInsteadOfClearingTheDeliverables`. Hier lautet die Meldung
    „Die Leistungen des Sponsors bitte als Text eintragen, eine pro Zeile.“
  - `testTheAssignedPersonMayTickOffADeliverable`
  - `testAnUninvolvedContributorMayNotTickOffADeliverable`
  - `testTheDashboardListsOpenDeliverablesOfAcceptedAgreementsOnly`: eine Vereinbarung
    `accepted` mit offenem Punkt, eine `requested` → nur die erste im HTML.
  - `testTheBenefitMessagesStayUnchanged`: `BenefitList::parse(str_repeat('x', 256))['error']`
    ist `BenefitList::LENGTH_ERROR`.

- [ ] **Step 2: Rot sehen:** `--filter SponsoringDeliverablesFeatureTest`

- [ ] **Step 3: Migration** nach dem Muster von `20261012120000_add_sponsoring_benefits_and_payment`,
  mit gesichertem `down()`. Danach ausführen.

- [ ] **Step 4: Umsetzen.**
  - `syncDeliverables` ist die Kopie der Logik von `syncBenefits`. Gemeinsame Teile auslagern,
    wenn es ohne Verrenkung geht, sonst bewusst dupliziert mit Verweis im Kommentar.
  - Auf der Detailseite steht die Liste „Leistungen des Sponsors“ unter den Gegenleistungen jeder
    Vereinbarung, Haken mit „erhalten am … von …“.
  - Das Dashboard bekommt den Abschnitt „Offene Leistungen des Sponsors“ hinter „Offene
    Gegenleistungen“. Sichtbar für alle mit Sponsoring-Zugang, nur `accepted`.

- [ ] **Step 5: Grün sehen,** phpcs, twigcs.

- [ ] **Step 6: Seed.**
  - Pakete bekommen Sponsor-Leistungen, z. B. „Banner/Beachflag übergeben“ und „Vertrag
    unterschrieben zurück“.
  - Zugesagte Vereinbarungen übernehmen sie, gut die Hälfte davon als erhalten markiert.
  - Bericht-Zähler `sponsor_package_deliverables` und `sponsorship_deliverables`.

- [ ] **Step 7: Commit:** `feat(sponsoring): Leistungen des Sponsors als abhakbare Liste`

### Task 4: Logo am Sponsor mit eigenem Recht

**Files:**
- Create: `db/migrations/20261023122000_add_sponsor_logo.php`
- Modify: `src/Models/Sponsor.php` (fillable `logo_attachment_id`, Relation `logo()`)
- Modify: `src/Policies/SponsoringPolicy.php`
- Modify: `src/Services/AttachmentAccessRegistry.php` (`maySeeSponsor` → Logo-Ausnahme)
- Modify: `src/Controllers/SponsorController.php` (`uploadLogo`, `deleteLogo`, `detail`,
  `buildSponsorRows`)
- Modify: `src/Routes.php`:
  - `POST /sponsoring/sponsors/{id:[0-9]+}/logo` → `uploadLogo`
  - `POST /sponsoring/sponsors/{id:[0-9]+}/logo/delete` → `deleteLogo`
  - beide in der allgemeinen Sponsoring-Gruppe, das Recht prüft der Controller
- Modify: `templates/sponsoring/sponsors/detail.twig` (Bereich „Logo“),
  `templates/sponsoring/sponsors/index.twig` (Symbol am Namen: Logo vorhanden)
- Modify: `src/Services/DevSeedService.php` (`seedSponsorLogoAttachments` setzt `logo_attachment_id`)
- Test: `tests/Feature/SponsorLogoFeatureTest.php`

**Interfaces:**
- Produces: `sponsors.logo_attachment_id INT NULL`, FK → `attachments.id` ON DELETE SET NULL.
- Produces: `SponsoringPolicy::canManageLogo(Sponsor $sponsor): bool`. Wahr bei
  `canEditSponsor($sponsor)`, oder wenn eine Vereinbarung des Sponsors mit Status `accepted` und
  `assigned_user_id = currentUserId()` existiert.
- Produces: `SponsoringPolicy::canDeleteLogo(Sponsor $sponsor): bool` = `canEditSponsor($sponsor)`.
- `AttachmentAccessRegistry`: Ein Anhang mit `entity_type='sponsor'` ist sichtbar bei
  `canSeeSponsorDetails`, oder wenn seine id gleich `logo_attachment_id` des Sponsors ist und
  `canManageLogo` gilt.
- Upload-Feld `logo`, genau eine Datei. Erlaubt sind nur MIME-Typen, die mit `image/` beginnen,
  sowie `application/pdf`, und nur, soweit `EntityAttachmentService` sie ohnehin annimmt. Sonst
  Fehler „Bitte ein Bild oder PDF als Logo hochladen.“
- Log-Events: `sponsoring.logo.uploaded`, `sponsoring.logo.replaced`, `sponsoring.logo.deleted`.

- [ ] **Step 1: Failing tests** in `SponsorLogoFeatureTest`:
  - `testTheEditorUploadsALogo` → `logo_attachment_id` gesetzt, ein Anhang mehr.
  - `testReplacingTheLogoDeletesTheOldOne` → alte Anhang-Zeile weg, neue verknüpft, Zahl der
    Anhänge des Sponsors gleich.
  - `testANonImageIsRejectedAsLogo`
  - `testTheAssigneeOfAnAcceptedAgreementMayUploadTheLogo`
  - `testTheAssigneeOfARequestedOrDeclinedAgreementMayNot` (Data-Provider: `requested`,
    `declined`, `closed`)
  - `testTheAssigneeMayNotUploadOrdinaryAttachments` → `uploadAttachment` gibt 403
  - `testTheAssigneeSeesTheLogoButNotOtherAttachments`: `AttachmentAccessRegistry::mayAccess`
    wahr für das Logo, falsch für einen anderen Sponsor-Anhang
  - `testTheAssigneeMayNotDeleteTheLogo`
  - `testTheEditorDeletesTheLogo` → `logo_attachment_id` null, Anhang weg

- [ ] **Step 2: Rot sehen:** `--filter SponsorLogoFeatureTest`

- [ ] **Step 3: Migration**, ausführen.

- [ ] **Step 4: Umsetzen.**
  - `uploadLogo` speichert über `EntityAttachmentService::storeUploads(..., Sponsor::ENTITY_TYPE, $id)`.
  - Nur wenn das Speichern gelingt, wird das alte Logo per `deleteForEntity` entfernt. In der
    Transaktion: erst die neue id setzen, dann das alte löschen.
  - Der Detail-Bereich „Logo“ zeigt die Vorschau über die vorhandene
    `/attachments/{id}/preview`-Route, den Knopf „Logo hochladen“ bzw. „Logo ersetzen“ für
    `canManageLogo` und „Logo löschen“ für `canDeleteLogo`.
  - Das Logo erscheint nicht zusätzlich in der normalen Anhangliste.

- [ ] **Step 5: Grün sehen** und die bestehenden Rechte-Tests mitlaufen lassen:
  `--filter "SponsorLogo|SponsoringPermissions|SponsoringAttachmentOverview"`. Danach phpcs und
  twigcs.

- [ ] **Step 6: Seed.** `seedSponsorLogoAttachments` verknüpft die erzeugten Logos über
  `logo_attachment_id`. Wenigstens ein zugesagter Sponsor bleibt ohne Logo, für Task 5.

- [ ] **Step 7: Commit:** `feat(sponsoring): ein Logo je Sponsor, ersetzbar, auch durch die Zuständigen`

### Task 5: Logo-Bedarf, Fristen am Projekt, Dashboard „Logo fehlt“

**Files:**
- Create: `db/migrations/20261023123000_add_logo_needs_and_deadlines.php`
- Create: `src/Util/LogoDeadline.php`
- Modify: `src/Models/SponsorPackage.php`, `src/Models/Sponsorship.php`, `src/Models/Project.php`
  (fillable, Casts)
- Modify: `src/Controllers/SponsorPackageController.php`, `src/Controllers/SponsorshipController.php`
  (Häkchen, Übernahme aus Paket beim Anlegen)
- Modify: `src/Controllers/ProjectController.php` (`create`, `update`: zwei Datumsfelder über
  `normalizeProjectDate`), `templates/projects/index.twig` (Formularfelder)
- Modify: `src/Controllers/SponsoringDashboardController.php` (`missingLogos()`, Fristen-Formular)
- Create: Methode `updateLogoDeadlines` in `SponsoringDashboardController`, Route
  `POST /sponsoring/projects/{id:[0-9]+}/logo-deadlines` in der Admin-Gruppe des Sponsorings
  (`can_manage_sponsoring`)
- Modify: `templates/sponsoring/dashboard.twig`, `templates/sponsoring/packages/index.twig`,
  `templates/sponsoring/sponsors/detail.twig` (Häkchen im Vereinbarungsformular), `public/css/style.css`
  (Klassen für überschritten und bald)
- Modify: `src/Services/DevSeedService.php`
- Test: `tests/Unit/Util/LogoDeadlineTest.php`, `tests/Feature/SponsoringMissingLogosFeatureTest.php`

**Interfaces:**
- Produces: Spalten `sponsor_packages.needs_logo_print`, `sponsor_packages.needs_logo_online`,
  `sponsorships.needs_logo_print`, `sponsorships.needs_logo_online` (TINYINT(1) NOT NULL DEFAULT 0).
  Dazu `projects.logo_deadline_print`, `projects.logo_deadline_online` (DATE NULL).
- Produces: `LogoDeadline::for(bool $print, bool $online, ?Carbon $printDeadline, ?Carbon $onlineDeadline): ?array{date: ?Carbon, kind: 'print'|'online'}`.
  - null, wenn weder `$print` noch `$online`.
  - `$print` und Druck-Frist vorhanden → `['date' => print, 'kind' => 'print']`.
  - sonst Online-Frist vorhanden → `['date' => online, 'kind' => 'online']`.
  - sonst `['date' => null, 'kind' => $print ? 'print' : 'online']`.
- Produces: `SponsoringDashboardController::missingLogos(): list<array{sponsor_id:int, sponsor_name:string, project_name:?string, deadline:?string, kind:string, urgency:'overdue'|'soon'|'later'|'none', assignee:?string}>`.
  - Grundlage: Vereinbarungen mit `accepted`, mindestens einem `needs_logo_*` und einem Sponsor mit
    `logo_attachment_id IS NULL`.
  - Je Sponsor genau die Zeile mit der frühesten Frist.
  - Sortierung: Datum aufsteigend, null am Ende, bei Gleichstand nach Sponsorname.
  - `soon` heißt höchstens 14 Tage ab heute, `overdue` heißt vor heute.
- Beim Anlegen einer Vereinbarung mit Paket werden die Häkchen aus dem Paket übernommen, wenn das
  Formular sie nicht mitsendet. Analog zu den Benefits: Explizites gewinnt.

- [ ] **Step 1: Failing Unit-Tests** in `LogoDeadlineTest`, je Zeile der Regel oben ein Fall,
  einschließlich „Druck ohne Druck-Frist → Online-Frist, kind online“.

- [ ] **Step 2: Failing Feature-Tests** in `SponsoringMissingLogosFeatureTest`:
  - `testAnAcceptedAgreementWithLogoNeedAndNoLogoIsListed`
  - `testASponsorWithLogoIsNotListed`
  - `testRequestedAgreementsAreNotListed`
  - `testASponsorWithTwoAgreementsAppearsOnceWithTheEarliestDeadline`
  - `testEntriesWithoutDeadlineComeLast`
  - `testOverdueAndSoonAreMarked`: HTML enthält die CSS-Klassen `logo-deadline-overdue` und
    `logo-deadline-soon`.
  - `testANewAgreementTakesOverTheLogoNeedsOfItsPackage`
  - `testTheSponsoringManagerSetsTheProjectDeadlines` → Projekt-Spalten gesetzt
  - `testAContributorMayNotSetProjectDeadlines` → 403
  - `testTheProjectFormStoresLogoDeadlines` → über `ProjectController::update`

- [ ] **Step 3: Rot sehen:** `--filter "LogoDeadline|SponsoringMissingLogos"`

- [ ] **Step 4: Migration**, ausführen.

- [ ] **Step 5: Umsetzen.**
  - Dashboard: Abschnitt „Logo fehlt“ als erster Abschnitt. Je Zeile Sponsor (Link), Projekt,
    Frist (`d.m.Y`, sonst „ohne Frist“), Art („Druck“/„online“) und Zuständige:r.
  - Darunter, nur für `can_manage_sponsoring`, ein einklappbarer Bereich „Logo-Fristen“. Je
    Projekt mit zugesagter Vereinbarung gibt es ein Formular mit den zwei Datumsfeldern.
  - Paket- und Vereinbarungsformular bekommen die Häkchen „Logo für Druck“ und „Logo online“.

- [ ] **Step 6: Grün sehen,** phpcs, twigcs.

- [ ] **Step 7: Seed.**
  - Pakete mit Logo-Häkchen.
  - Ein Projekt mit Druck-Frist in der Vergangenheit, eines mit einer Frist in 7 Tagen und eines in
    60 Tagen.
  - Zugesagte Vereinbarungen ohne Logo in jedem davon.
  - Bericht-Zähler `sponsors_missing_logo`.

- [ ] **Step 8: Volle Suite** `ddev composer test:parallel` (grün) und Seed-Lauf
  `ddev php bin/dev_seed.php --mode=reset-and-seed`, nur nach Rückfrage wegen möglicher Echtdaten.
  Dashboard headless sichten, ohne Browserfenster.

- [ ] **Step 9: Commit:** `feat(sponsoring): Logo-Fristen am Projekt und Liste „Logo fehlt“`
