<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NewsletterArchive;

/**
 * Wer einen Newsletter ansehen darf.
 *
 * Die Regel stand bisher als privater Helfer im NewsletterController. Mit den
 * Anhängen braucht sie eine zweite Leserin - die AttachmentAccessRegistry -, und
 * zwei Kopien derselben Zugriffsregel laufen auseinander, sobald eine von beiden
 * angepasst wird.
 *
 * Zwei Wege führen hierher: die Newsletter-Verwaltung, die auch einen Entwurf
 * vor dem Versand prüfen können muss, und jede Person mit einer Archiv-Zeile,
 * also jede, an die der Newsletter tatsächlich ging.
 */
class NewsletterPolicy
{
    private int $userId;
    private bool $canManageNewsletters;

    /**
     * @param array<string, mixed> $session
     */
    public function __construct(array $session)
    {
        // Auf Wahrheitswert prüfen, nicht strikt auf true - RoleMiddleware und
        // Controller lesen denselben Sitzungsschlüssel ebenfalls nur truthy.
        $this->userId = (int) ($session['user_id'] ?? 0);
        $this->canManageNewsletters = (bool) ($session['can_manage_newsletters'] ?? false);
    }

    /**
     * @param int|null $userId Kennung der anfragenden Person. Fehlt sie, gilt die
     *                         aus der Sitzung; ein aus der Anfrage übernommener
     *                         Wert gehört hier nie hinein.
     */
    public function canView(int $newsletterId, ?int $userId = null): bool
    {
        if ($this->canManageNewsletters) {
            return true;
        }

        $userId = $userId ?? $this->userId;

        if ($newsletterId <= 0 || $userId <= 0) {
            return false;
        }

        return NewsletterArchive::query()
            ->where('newsletter_id', $newsletterId)
            ->where('user_id', $userId)
            ->exists();
    }
}
