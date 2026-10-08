<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Policies\NewsletterPolicy;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Wer darf welchen Anhang sehen.
 *
 * Vorher stand die Antwort fünfmal in je einem Controller, und jede Fassung
 * kannte nur ihren eigenen Bereich. Mit einer gemeinsamen Auslieferungsroute
 * braucht es eine gemeinsame Tabelle, sonst wäre die Route ein Loch, das an
 * allen fünf Prüfungen vorbeiführt.
 *
 * Zwei Eigenschaften sind hier bewusst festgelegt:
 *
 *  - Ein `entity_type` ohne Eintrag wird abgelehnt. Ein neuer Anhang-Typ muss
 *    seine Regel hier hinterlegen, sonst ist er nicht abrufbar - das ist die
 *    sichere Richtung.
 *  - Ein abgeschaltetes Modul sperrt seine Anhänge. Die alten Routen lagen
 *    innerhalb der `if ($settings['modules'][...])`-Blöcke in Routes.php und
 *    verschwanden mit dem Modul; die zentrale Route liegt außerhalb.
 */
final class AttachmentAccessRegistry
{
    private SponsoringPolicy $sponsoringPolicy;
    private TaskPolicy $taskPolicy;
    private NewsletterPolicy $newsletterPolicy;

    /** @var array<string, bool> */
    private array $modules;

    /** @var array<string, mixed> */
    private array $session;

    private int $userId;

    /**
     * Die Sitzung kommt von außen, wie bei den Policies - siehe
     * tests/Unit/Policies/PoliciesReceiveTheSessionTest. Zuvor griff die Registry
     * an zwei Stellen selbst in das Sitzungs-Superglobal und nahm die Kennung der
     * anfragenden Person zusätzlich als Parameter: zwei Wege zur selben Angabe,
     * und der Parameter ließ sich mit einer fremden Kennung füllen.
     *
     * @param array<string, bool> $modules
     * @param array<string, mixed> $session
     */
    public function __construct(
        SponsoringPolicy $sponsoringPolicy,
        TaskPolicy $taskPolicy,
        array $modules,
        NewsletterPolicy $newsletterPolicy,
        array $session
    ) {
        $this->sponsoringPolicy = $sponsoringPolicy;
        $this->taskPolicy = $taskPolicy;
        $this->modules = $modules;
        $this->newsletterPolicy = $newsletterPolicy;
        $this->session = $session;
        $this->userId = (int) ($session['user_id'] ?? 0);
    }

    /**
     * Ob die anfragende Person diesen Anhang lesen darf.
     *
     * Gefragt wird immer für die Person aus der Sitzung. Früher nahm die
     * Methode deren Kennung als Parameter und reichte ihn an `maySeeSong()` und
     * `NewsletterPolicy::canView()` weiter, während die Sponsoring- und
     * Finanzregeln ihre Kennung schon selbst aus der Sitzung lasen. Ein
     * abweichender Parameter hätte dieselbe Prüfung zwei verschiedene Personen
     * beantworten lassen; davor warnte nur ein Kommentar. Ohne den Parameter ist
     * der Fall nicht mehr möglich.
     */
    public function mayAccess(Attachment $attachment): bool
    {
        $entityType = (string) $attachment->entity_type;
        $entityId = (int) $attachment->entity_id;

        return match ($entityType) {
            'finance'     => $this->moduleEnabled('finance') && $this->mayReadFinances(),
            'task'        => $this->moduleEnabled('tasks') && $this->taskPolicy->canManageTasks(),
            'sponsor'     => $this->moduleEnabled('sponsoring') && $this->maySeeSponsor($entityId),
            'sponsorship' => $this->moduleEnabled('sponsoring') && $this->maySeeSponsorship($entityId),
            'song'        => $this->maySeeSong($entityId),
            'newsletter'  => $this->moduleEnabled('newsletter')
                && $this->newsletterPolicy->canView($entityId),
            default       => false,
        };
    }

    private function sessionFlag(string $key): bool
    {
        return (bool) ($this->session[$key] ?? false);
    }

    private function moduleEnabled(string $module): bool
    {
        return (bool) ($this->modules[$module] ?? false);
    }

    /**
     * Dieselben zwei Rechte wie das Gate `requiresFinanceRead` in RoleMiddleware.
     */
    private function mayReadFinances(): bool
    {
        return $this->sessionFlag('can_read_finances')
            || $this->sessionFlag('can_manage_finances');
    }

    private function maySeeSponsor(int $sponsorId): bool
    {
        $sponsor = Sponsor::find($sponsorId);

        return $sponsor !== null && $this->sponsoringPolicy->canSeeSponsorDetails($sponsor);
    }

    private function maySeeSponsorship(int $sponsorshipId): bool
    {
        $sponsorship = Sponsorship::find($sponsorshipId);

        return $sponsorship !== null && $this->sponsoringPolicy->canSeeSponsorshipDetails($sponsorship);
    }

    /**
     * Zwei Wege zum selben Notenblatt: wer das Lied im Projekt singt, und wer
     * das Repertoire verwaltet. Der zweite Weg fehlte bisher - der Link auf der
     * Lied-Detailseite zeigte auf die mitgliedschaftsgebundene Route und lief
     * für eine Repertoire-Verwaltung ohne Projekt ins Leere.
     */
    private function maySeeSong(int $songId): bool
    {
        if ($this->sessionFlag('can_manage_song_library')) {
            return true;
        }

        if ($this->userId <= 0 || $songId <= 0) {
            return false;
        }

        return Capsule::table('project_song_assignments')
            ->join(
                'project_users',
                'project_users.project_id',
                '=',
                'project_song_assignments.project_id'
            )
            ->where('project_song_assignments.song_id', $songId)
            ->where('project_users.user_id', $this->userId)
            ->exists();
    }
}
