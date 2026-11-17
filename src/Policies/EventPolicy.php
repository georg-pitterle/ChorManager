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
 * - Ändern: Recht **und** Sichtbarkeit. Das Recht allein genügt nicht, sonst
 *   änderte jemand einen Termin, den er nicht zu sehen bekommt.
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

    public function canEditEvent(Event $event): bool
    {
        return $this->canManage && $this->canAccessEvent($event);
    }
}
