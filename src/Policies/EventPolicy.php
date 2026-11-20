<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Services\EventAudienceService;

/**
 * Wer einen Termin sehen und wer ihn ändern darf.
 *
 * Ob jemand zur Zielgruppe gehört, beantwortet EventAudienceService; hier wird
 * es nur mit dem Recht `can_manage_events` verknüpft:
 *
 * - Sehen: Zielgruppe oder Recht. Terminverwalter müssen auch Termine außerhalb
 *   ihrer eigenen Zielgruppe sehen, sonst könnten sie genau die Termine nicht
 *   pflegen, für die sie zuständig sind.
 * - Ändern: Recht und Sichtbarkeit. Beides zusammen, damit niemand einen
 *   Termin ändert, den er nicht zu sehen bekommt - derzeit ist die zweite
 *   Bedingung von der ersten aber schon erfüllt, siehe canEditEvent().
 *
 * Die drei Prüfungen standen als private Methoden im EventController. Mit dem
 * eigenen Serien-Controller (Antwort auf Review-Lauf 77, Frage 2b) brauchen sie
 * zwei Aufrufer, und zwei Abschriften derselben Rechteprüfung laufen irgendwann
 * auseinander.
 */
class EventPolicy
{
    private int $userId;
    private bool $canManage;
    private EventAudienceService $audience;

    /**
     * Die Sitzung kommt von außen - siehe
     * tests/Unit/Policies/PoliciesReceiveTheSessionTest.
     *
     * @param array<string, mixed> $session
     */
    public function __construct(array $session, ?EventAudienceService $audience = null)
    {
        $this->userId = (int) ($session['user_id'] ?? 0);
        $this->canManage = (bool) ($session['can_manage_events'] ?? false);
        $this->audience = $audience ?? new EventAudienceService();
    }

    public function currentUserId(): int
    {
        return $this->userId;
    }

    public function canManageEvents(): bool
    {
        return $this->canManage;
    }

    public function canAccessEvent(Event $event): bool
    {
        if ($this->canManage) {
            return true;
        }

        if ($this->userId <= 0) {
            return false;
        }

        return $this->audience->isUserEligible($event, $this->userId);
    }

    /**
     * Beide Bedingungen, obwohl die zweite heute an der ersten hängt: Wer das
     * Recht hält, sieht laut canAccessEvent() jeden Termin, also fällt
     * `canEditEvent()` derzeit mit `canManageEvents()` zusammen.
     *
     * Der Kommentar oben las sich zuvor so, als sperrte die Sichtbarkeit einen
     * Verwalter von fremden Terminen aus. Das tut sie nicht, und sie soll es
     * auch nicht: Dass die Terminverwaltung auch außerhalb der eigenen
     * Zielgruppe pflegen kann, ist der ausdrückliche Grund für die Freigabe in
     * canAccessEvent(). Wer die Stelle gelesen und daraus eine Einschränkung
     * geschlossen hat, hätte sie an der falschen Stelle gesucht.
     *
     * Stehen bleibt die Verknüpfung trotzdem. Sie kostet nichts - bei gesetztem
     * Recht bricht canAccessEvent() vor der Zielgruppen-Abfrage ab -, und wird
     * die Sichtbarkeit je enger gefasst, folgt das Ändern ihr von selbst,
     * statt die Lücke erst beim nächsten Durchgang zu zeigen.
     */
    public function canEditEvent(Event $event): bool
    {
        return $this->canManage && $this->canAccessEvent($event);
    }
}
