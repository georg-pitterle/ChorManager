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
     * Geprüft wird immer für die Person aus der Sitzung.
     *
     * Hier stand zuvor ein zweiter Parameter, mit dem sich diese Person
     * überschreiben ließ, und daneben der Kommentar, dass dort nie ein Wert aus
     * der Anfrage hineingehört. Beides ist weg: Übergeben wurde ohnehin an jeder
     * Stelle genau die Kennung, die der Konstruktor schon gelesen hatte, und ohne
     * den Parameter kann die Prüfung gar nicht mehr für jemand anderen antworten
     * als für den Anfragenden. Eine Regel, die die Signatur durchsetzt, braucht
     * keinen Kommentar, der vor ihr warnt.
     */
    public function canView(int $newsletterId): bool
    {
        if ($this->canManageNewsletters) {
            return true;
        }

        if ($newsletterId <= 0 || $this->userId <= 0) {
            return false;
        }

        return NewsletterArchive::query()
            ->where('newsletter_id', $newsletterId)
            ->where('user_id', $this->userId)
            ->exists();
    }
}
