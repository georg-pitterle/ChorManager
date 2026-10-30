<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Mitgliederliste nach der Kritik vom Oktober 2026 (24/40).
 *
 * Die Befunde, die diese Tests festhalten: Das Archiv sah aus wie die aktive
 * Liste; für eine Aktion standen drei Wörter ("Deaktivieren", "archivieren",
 * "archivieren (deaktivieren)"); vor dem ersten Mitglied standen ein zweiter
 * Titel, ein ausgegrauter roter Knopf und doppelte Trefferzahlen; der Knopf für
 * die Einladung sendete sofort und stand direkt neben "Abbrechen".
 */
final class UserListLayoutFeatureTest extends TestCase
{
    private function template(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/' . $path);
        $this->assertIsString($source);

        return $source;
    }

    public function testTheArchiveNamesItselfAndExplainsWhatArchivedMeans(): void
    {
        $twig = $this->template('users/manage.twig');

        $this->assertMatchesRegularExpression(
            '/\{% if show_archived %\}\s*<h1 class="h2 mb-1">Archivierte Mitglieder<\/h1>/',
            $twig
        );
        $this->assertStringContainsString(
            'Archivierte Mitglieder können sich nicht anmelden. Wiederherstellen macht sie wieder aktiv.',
            $twig
        );
        $this->assertStringContainsString('Zurück zu den Mitgliedern', $twig);
    }

    public function testTheArchiveDropsColumnsAndFiltersThatMeanNothingThere(): void
    {
        $twig = $this->template('users/manage.twig');

        $this->assertStringContainsString(
            '{% set show_projects = (can_manage_project_members or can_edit_users) and not show_archived and _running_total > 0 %}',
            $twig
        );
        $this->assertStringContainsString(
            'data-invitation-filter="{{ session.can_manage_users and not show_archived ? "1" : "0" }}"',
            $twig
        );
    }

    public function testOneVerbForArchiving(): void
    {
        foreach (['users/manage.twig', 'partials/user_edit_form.twig'] as $path) {
            $twig = $this->template($path);

            $this->assertStringNotContainsString('Deaktivieren', $twig, $path);
            $this->assertStringNotContainsString('deaktivieren', $twig, $path);
        }
    }

    public function testTheArchiveConfirmationNamesThePersonAndSaysItCanBeUndone(): void
    {
        $twig = $this->template('users/manage.twig');

        $this->assertStringContainsString(
            'data-confirm="{{ user|person_name }} archivieren? Die Person kann sich danach nicht mehr anmelden. '
            . 'Im Archiv lässt sie sich jederzeit wiederherstellen."',
            $twig
        );
    }

    public function testNothingStandsBetweenTheToolbarAndTheFirstMember(): void
    {
        $twig = $this->template('users/manage.twig');

        $this->assertStringNotContainsString('dashboard-section-head', $twig);
        $this->assertStringNotContainsString('Mitgliederübersicht', $twig);
        // Der Sammelknopf steht nicht mehr ausgegraut im Seitenkopf, sondern
        // erscheint mit der Auswahl in einer eigenen Leiste.
        $header = $this->between($twig, '{% block page_header %}', '{% endblock page_header %}');
        $this->assertStringNotContainsString('bulkDeactivateForm', $header);
        $this->assertMatchesRegularExpression(
            '/\{% if not show_archived %\}\s*\{\{ include\("partials\/user_selection_bar\.twig"\) \}\}/',
            $twig
        );
        $this->assertStringContainsString('id="userSelectionBar"', $this->template('partials/user_selection_bar.twig'));
    }

    public function testTheSelectionBarOffersBulkInvitationOnlyToMemberManagers(): void
    {
        $partial = $this->template('partials/user_selection_bar.twig');
        $bar = $this->between($partial, 'id="userSelectionBar"', '</div><!-- /userSelectionBar -->');

        $this->assertMatchesRegularExpression(
            '/\{% if session\.can_manage_users %\}\s*<form action="\/users\/bulk-invite"/',
            $bar
        );
        $this->assertStringContainsString('action="/users/bulk-deactivate"', $bar);
        $this->assertStringContainsString('id="clearUserSelection"', $bar);
    }

    public function testCellsCarryMeaningInsteadOfDecoration(): void
    {
        $twig = $this->template('users/manage.twig');

        // Blau heißt im System "Hinweis"; eine Anzahl ist keiner.
        $this->assertStringNotContainsString('btn-outline-info', $twig);
        // Rollen sind Schlagworte: hell mit Rand, damit der Name führt.
        $this->assertStringContainsString('badge text-bg-light border', $twig);
        $this->assertStringNotContainsString('badge text-bg-secondary', $twig);
        // Stimme als Klartext statt umrandeter Box mit wiederholtem Gruppennamen.
        $this->assertStringNotContainsString('d-inline-block border p-1 rounded', $twig);
    }

    public function testABlockedArchiveExplainsItselfWithoutATooltip(): void
    {
        $twig = $this->template('users/manage.twig');

        // Ein title-Attribut gibt es am Handy nicht. Der Grund steht deshalb als
        // Text unter dem gesperrten Eintrag im geöffneten Menü.
        $this->assertStringContainsString(
            '<span class="dropdown-item-text user-archive-hint">{{ _archive_block_reason }}</span>',
            $twig
        );
        $this->assertStringNotContainsString('title="{{ _archive_title }}"', $twig);
    }

    public function testABlockedArchiveIsGreyAndOffersTheWayOut(): void
    {
        $twig = $this->template('users/manage.twig');

        // Rot nur, wenn der Klick auch durchgeht; gesperrt wirkte der rote
        // Eintrag klickbar und tat nichts.
        $this->assertStringContainsString('class="dropdown-item{{ _may_archive ? " text-danger" }}"', $twig);
        // Der Hinweis "Erst alle Rollen ... abnehmen" führt direkt dorthin.
        $this->assertMatchesRegularExpression(
            '/\{% if not _is_self %\}\s*<li><button type="button" class="dropdown-item js-edit-user" '
            . 'data-user-id="\{\{ user\.id \}\}">Rollen bearbeiten …<\/button><\/li>/',
            $twig
        );
    }

    public function testOpenInvitationsShowUnderTheNameForMemberManagers(): void
    {
        $twig = $this->template('users/manage.twig');

        $this->assertStringContainsString(
            '{% set _pending = session.can_manage_users and not show_archived and user.invitationPending() %}',
            $twig
        );
        $this->assertStringContainsString('<span class="user-status">Einladung offen</span>', $twig);
    }

    public function testTheListLeavesOutTheDefaultRole(): void
    {
        $twig = $this->template('users/manage.twig');

        // Fast alle sind einfache Mitglieder; nur, wer mehr ist, bekommt ein Schlagwort.
        $this->assertStringContainsString(
            '{% set _extra_roles = user.roles|filter(r => r.id not in default_role_ids|default([])) %}',
            $twig
        );
    }

    public function testTheInvitationButtonLivesInTheAccountActivityAwayFromCancel(): void
    {
        $form = $this->template('partials/user_edit_form.twig');
        $details = $this->between($form, '<summary>Kontoaktivität</summary>', '</details>');
        $footer = $this->between($form, '<div class="modal-footer">', '</div>');

        $this->assertStringContainsString('js-invite-btn', $details);
        $this->assertStringNotContainsString('js-invite-btn', $footer);
    }

    public function testPastProjectsAreFoldedAwayInTheEditDialog(): void
    {
        $form = $this->template('partials/user_edit_form.twig');

        // Jede Saison kam ein Projekt dazu; die Liste wuchs ohne Ende. Abgelaufene
        // Projekte bleiben im Formular (sie werden mitgeschickt), nur eingeklappt.
        $this->assertStringContainsString(
            '{% set _past_projects = projects|filter(p => p.end_date and p.end_date|date("Y-m-d") < _today) %}',
            $form
        );
        $this->assertMatchesRegularExpression('/<details class="past-projects[^"]*">\s*<summary>/', $form);
    }

    public function testTheEditDialogRendersCurrentAndPastProjectsApart(): void
    {
        $current = new \App\Models\Project(['name' => 'Laufendes Projekt', 'end_date' => '2099-06-30']);
        $current->id = 1;
        $open = new \App\Models\Project(['name' => 'Projekt ohne Ende']);
        $open->id = 2;
        $past = new \App\Models\Project(['name' => 'Altes Projekt', 'end_date' => '2020-06-30']);
        $past->id = 3;

        $user = new \App\Models\User(['first_name' => 'Ada', 'last_name' => 'Alt', 'email' => 'ada@example.test']);
        $user->id = 7;
        $user->project_ids = [3];
        $user->voice_group_ids = [];
        $user->voice_group_pivots = [];

        $twig = \Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates');
        $twig->getEnvironment()->addFilter(new \Twig\TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new \App\Services\NameFormatterService())->formatPerson($person)
        ));
        $html = $twig->fetch('partials/user_edit_form.twig', [
            'user' => $user,
            'roles' => [],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => new \Illuminate\Database\Eloquent\Collection([$current, $open, $past]),
            'can_edit_profile' => true,
            'can_edit_email' => true,
            'can_invite' => false,
            // Die Projekt-Kästchen hängen an genau der Antwort, die update() vor dem
            // Schreiben einholt (UserEditPolicy::canEditProjects()). Vorher las die
            // Vorlage `can_manage_project_members or can_edit_users` selbst.
            'can_edit_projects' => true,
            'edit_state' => [],
        ]);

        $details = $this->between($html, '<details class="past-projects', '</details>');
        $this->assertStringContainsString('Frühere Projekte (1)', $details);
        $this->assertStringContainsString('Altes Projekt', $details);
        $this->assertStringNotContainsString('Laufendes Projekt', $details);
        $this->assertStringNotContainsString('Projekt ohne Ende', $details);
        // Die frühere Teilnahme bleibt angekreuzt und wird mitgeschickt.
        $this->assertMatchesRegularExpression('/value="3"\s+id="proj_7_3"\s+checked/', $details);
        $this->assertSame(3, substr_count($html, 'name="projects[]"'));
    }

    public function testVoiceGroupsAreOneFlatListWithTheSystemTerm(): void
    {
        foreach (['users/manage.twig', 'partials/user_edit_form.twig'] as $path) {
            $twig = $this->template($path);

            $this->assertStringNotContainsString('Stimmengruppen', $twig, $path);
            $this->assertStringContainsString('Stimmgruppen', $twig, $path);
            // Kein Kasten im Dialog (One-Frame-Rule) und kein fetter Gruppenname.
            $this->assertStringNotContainsString('mb-2 p-2 border rounded bg-light', $twig, $path);
            $this->assertStringContainsString('class="voice-group-choice"', $twig, $path);
        }
    }

    public function testTheCreateDialogPreselectsTheDefaultRole(): void
    {
        $twig = $this->template('users/manage.twig');

        // Die Grundrolle ist angekreuzt, solange das Formular nicht mit eigener
        // Auswahl zurückkommt; weitere Rollen nie von selbst.
        $this->assertStringContainsString(
            '{{ _self.create_role_choice(role, _create_roles_touched ? role.id in modal_form_create.form.roles : true) }}',
            $twig
        );
        $this->assertStringContainsString(
            '{{ _self.create_role_choice(role, _create_roles_touched and role.id in modal_form_create.form.roles) }}',
            $twig
        );
    }

    public function testThePageLinksItsHelpTopicInBothViews(): void
    {
        $header = $this->between(
            $this->template('users/manage.twig'),
            '{% block page_header %}',
            '{% endblock page_header %}'
        );
        $actions = substr($header, (int) strpos($header, 'page-actions'));

        // Vor der Weiche Archiv/aktiv, damit der Link in beiden Ansichten steht.
        $this->assertLessThan(
            (int) strpos($actions, '{% if show_archived %}'),
            (int) strpos($actions, 'href="/help/members"')
        );
    }

    public function testFurtherRolesSitBehindADisclosureInBothDialogs(): void
    {
        foreach (['users/manage.twig', 'partials/user_edit_form.twig'] as $path) {
            $twig = $this->template($path);

            // Die Grundrolle steht offen; alles, was Rechte dazugibt, ist einen
            // bewussten Klick entfernt.
            $this->assertMatchesRegularExpression(
                '/<details class="further-roles"\{% if _further_open %\} open\{% endif %\}>\s*<summary>Weitere Rollen<\/summary>/',
                $twig,
                $path
            );
            $this->assertStringContainsString('Weitere Rollen geben zusätzliche Rechte in der App.', $twig, $path);
        }
    }

    public function testTheCreateDialogOffersTheRunningProjects(): void
    {
        $twig = $this->template('users/manage.twig');

        $this->assertStringContainsString(
            '{% if session.can_edit_users or session.can_manage_project_members %}',
            $twig
        );
        $this->assertStringContainsString('id="proj_add_{{ p.id }}"', $twig);
    }

    public function testTheEditDialogNamesThePerson(): void
    {
        $form = $this->template('partials/user_edit_form.twig');

        $this->assertStringContainsString('{{ user|person_name }} bearbeiten', $form);
    }

    public function testTheListCarriesWhatTheBulkConfirmationsNeed(): void
    {
        $twig = $this->template('users/manage.twig');

        $this->assertStringContainsString('data-archivable="{{ _may_archive ? "1" : "0" }}"', $twig);
        $this->assertStringContainsString('data-invitation="|{{ _invitation_tokens }}|"', $twig);
        $this->assertStringContainsString('<script src="/js/users-bulk-confirm.js', $twig);
    }

    public function testResendingAnInvitationAsksFirstEverywhere(): void
    {
        // Eine erneute Einladung entwertet den Link, den die Person vielleicht
        // gerade benutzen will. Die Sammelaktion fragte nach, die Einzelaktion nicht.
        $confirm = 'data-invite-confirm="{{ user.invitation_sent_at ? "Einladung erneut an " ~ user|person_name '
            . '~ " senden? Der Link vom " ~ user.invitation_sent_at|date("d.m.Y") ~ " verliert seine Gültigkeit." }}"';

        $this->assertStringContainsString($confirm, $this->template('users/manage.twig'));
        $this->assertStringContainsString($confirm, $this->template('partials/user_edit_form.twig'));

        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/users.js');
        $this->assertStringContainsString(
            'if (btn.dataset.inviteConfirm && !window.confirm(btn.dataset.inviteConfirm))',
            $js
        );
    }

    public function testTheProjectCellSaysInOrOutOfTheRunningProject(): void
    {
        $twig = $this->template('users/manage.twig');

        // Leere Zellen sahen kaputt aus, ein unterstrichener Link für eine
        // Abwesenheit wie eine Aktion. Jetzt: leise "dabei", gelb "nicht dabei".
        $this->assertStringContainsString(
            '{% set _in_all_running = _current_projects|length == _running_total %}',
            $twig
        );
        $this->assertStringContainsString('<span class="text-muted">dabei</span>', $twig);
        $this->assertStringContainsString('<span class="badge text-bg-warning">nicht dabei</span>', $twig);
        // Ohne laufendes Projekt sagt die Spalte nichts und entfällt.
        $this->assertStringContainsString(
            '{% set show_projects = (can_manage_project_members or can_edit_users) and not show_archived and _running_total > 0 %}',
            $twig
        );
    }

    public function testTheProjectFilterFindsMembersOutsideEveryRunningProject(): void
    {
        $twig = $this->template('users/manage.twig');

        // Wer in keinem laufenden Projekt ist, ist Kandidat fürs Archiv. Die Zeile
        // trägt dafür eine eigene Marke; die Auswahl gibt es nur, wenn auch die
        // Spalte "Laufendes Projekt" steht.
        $this->assertStringContainsString(
            '{% set project_filter_ids = project_filter_ids ~ (_current_projects is empty ? "none_running|" : "") %}',
            $twig
        );
        $this->assertLessThan(
            (int) strpos($twig, '{% set project_filter_ids = project_filter_ids ~ (_current_projects'),
            (int) strpos($twig, '{% set _current_projects = user.projects|filter(')
        );
        $this->assertStringContainsString('data-project-none-filter="{{ show_projects ? "1" : "0" }}"', $twig);
    }

    public function testRowsTellTheBulkInvitationWhoAlreadyUsesTheApp(): void
    {
        $this->assertStringContainsString(
            'data-uses-app="{{ user.usesApp() ? "1" : "0" }}"',
            $this->template('users/manage.twig')
        );
    }

    public function testTheOnlyRunningProjectIsPreselectedWhenCreating(): void
    {
        $twig = $this->template('users/manage.twig');

        $this->assertStringContainsString(
            '{% set _project_checked = _create_projects_touched ? p.id in modal_form_create.form.projects : _running_projects|length == 1 %}',
            $twig
        );
    }

    public function testGroupedViewOpensItsGroupsAndUsesATwoSidedSwitch(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/table-plugins/users-group-plugin.js');

        // Gemerkt werden die zugeklappten Gruppen; alles andere steht offen.
        // Vorher war alles zu, und die Liste schien nach dem Umschalten weg.
        $this->assertStringContainsString('var isVgOpen = !closedBlockIds.has(vgCollapseId);', $js);
        $this->assertStringContainsString("var LS_CLOSED_KEY = 'chorte.users.manage.accordionClosed';", $js);
        // Zwei Seiten statt eines Knopfs, der den jeweils anderen Zustand nannte.
        $this->assertStringContainsString("makeSide('Liste', false)", $js);
        $this->assertStringContainsString("makeSide('Nach Stimme', true)", $js);
        $this->assertStringNotContainsString("'Listenansicht'", $js);
    }

    public function testTheCreateDialogOffersInvitingOnlyToMemberManagers(): void
    {
        $twig = $this->template('users/manage.twig');
        $footer = $this->between($twig, '<div class="modal-footer user-create-footer">', '</div>');

        $this->assertMatchesRegularExpression(
            '/\{% if session\.can_manage_users %\}\s*<button type="submit"\s+class="btn btn-outline-secondary"/',
            $footer
        );
        $this->assertStringContainsString('value="save">Speichern</button>', $footer);
    }

    public function testBulkButtonsAreLockedWhenTheyWouldAffectNobody(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/users.js');

        // Drei ausgewählt, alle drei nutzen die App: "Einladung senden" täte nichts.
        $this->assertStringContainsString('button.disabled = !texts || texts.archivable === 0;', $js);
        $this->assertStringContainsString('inviteButton.disabled = !texts || texts.invitable === 0;', $js);
    }

    public function testDialogCloseButtonsSpeakGerman(): void
    {
        foreach (['users/manage.twig', 'partials/user_edit_form.twig'] as $path) {
            $this->assertStringNotContainsString('aria-label="Close"', $this->template($path), $path);
        }
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        $this->assertNotFalse($from, 'Anfang nicht gefunden: ' . $start);
        $to = strpos($haystack, $end, $from + strlen($start));
        $this->assertNotFalse($to, 'Ende nicht gefunden: ' . $end);

        return substr($haystack, $from, $to - $from);
    }
}
