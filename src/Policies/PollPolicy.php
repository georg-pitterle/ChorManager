<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Wer eine Umfrage sieht, abstimmt und das Ergebnis sieht.
 *
 * Ob jemand zur Zielgruppe gehört, beantwortet PollAudienceService; hier wird
 * es nur mit dem Recht can_manage_polls und dem Stand der Umfrage verknüpft.
 *
 * - Sehen: Zielgruppe oder Recht.
 * - Abstimmen: nur die Zielgruppe, und nur bis zum Fristende. Verwalten ist
 *   kein Stimmrecht.
 * - Ergebnis: nach der eigenen Stimme, nach Fristende für die ganze
 *   Zielgruppe, mit dem Recht immer. Vorher nicht, damit der Zwischenstand
 *   Unentschlossene nicht beeinflusst.
 */
class PollPolicy
{
    private int $userId;
    private bool $canManage;

    /**
     * Die Sitzung kommt von außen - siehe
     * tests/Unit/Policies/PoliciesReceiveTheSessionTest.
     *
     * @param array<string, mixed> $session
     */
    public function __construct(array $session)
    {
        $this->userId = (int) ($session['user_id'] ?? 0);
        $this->canManage = (bool) ($session['can_manage_polls'] ?? false);
    }

    public function canManage(): bool
    {
        return $this->canManage;
    }

    public function canView(bool $inAudience): bool
    {
        return $inAudience || $this->canManage;
    }

    /**
     * Die Frist gehört dazu: Nach ihrem Ablauf stimmt niemand mehr ab, auch
     * nicht die Zielgruppe.
     *
     * Sie stand zuvor als `&& !$isClosed` an der Aufrufstelle im
     * PollController, während canSeeResults() den Stand der Umfrage schon
     * selbst als Parameter nahm - dieselbe Angabe auf zwei Wegen. Eine zweite
     * Aufrufstelle hätte mit canVote($inAudience) einen Stimmzettel für eine
     * beendete Umfrage angeboten; abgewiesen hätte ihn erst PollVoteService,
     * das die Frist unter der Zeilensperre noch einmal prüft und dabei bleibt.
     * Mit der Frist in der Signatur kann die Prüfung sie nicht mehr übergehen.
     */
    public function canVote(bool $inAudience, bool $isClosed): bool
    {
        return $inAudience && !$isClosed;
    }

    public function canSeeResults(bool $inAudience, bool $hasVoted, bool $isClosed): bool
    {
        if ($this->canManage) {
            return true;
        }

        return $inAudience && ($hasVoted || $isClosed);
    }

    public function currentUserId(): int
    {
        return $this->userId;
    }
}
