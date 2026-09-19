<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Newsletter;
use Carbon\Carbon;

class NewsletterLockingService
{
    private const LOCK_TIMEOUT_MINUTES = 30;

    /**
     * Acquire exclusive lock for editing
     *
     * @param Newsletter $newsletter
     * @param int $userId
     * @return bool
     */
    public function acquireLock(Newsletter $newsletter, int $userId): bool
    {
        $now = Carbon::now();
        $expiredBefore = $now->copy()->subMinutes(self::LOCK_TIMEOUT_MINUTES);

        // Bedingtes Update statt Lesen-und-dann-Schreiben: Zwei gleichzeitige
        // Requests haben denselben, noch ungesperrten Stand gelesen. Ohne die
        // Bedingung im UPDATE überschriebe der zweite die Sperre des ersten.
        $acquired = Newsletter::query()
            ->whereKey((int) $newsletter->id)
            ->where(function ($query) use ($userId, $expiredBefore): void {
                $query->whereNull('locked_by')
                    ->orWhere('locked_by', $userId)
                    ->orWhereNull('locked_at')
                    ->orWhere('locked_at', '<=', $expiredBefore);
            })
            ->update([
                'locked_by' => $userId,
                'locked_at' => $now,
            ]);

        if ($acquired === 0) {
            // MySQL meldet 0 geänderte Zeilen auch dann, wenn der Datensatz
            // bereits exakt diese Werte trägt. Deshalb entscheidet der
            // gespeicherte Stand, nicht die Zeilenzahl.
            return $this->holdsValidLock((int) $newsletter->id, $userId);
        }

        $newsletter->locked_by = $userId;
        $newsletter->locked_at = $now;
        $newsletter->syncOriginal();

        return true;
    }

    /**
     * Hält dieser Nutzer laut gespeichertem Stand eine gültige Sperre?
     */
    private function holdsValidLock(int $newsletterId, int $userId): bool
    {
        $stored = Newsletter::query()->whereKey($newsletterId)->first();
        if ($stored === null || (int) $stored->locked_by !== $userId) {
            return false;
        }

        return !$this->isLockExpired($stored);
    }

    /**
     * Release lock
     *
     * @param Newsletter $newsletter
     * @return void
     */
    public function releaseLock(Newsletter $newsletter): void
    {
        $newsletter->update([
            'locked_by' => null,
            'locked_at' => null,
        ]);
    }

    /**
     * Trägt der Entwurf gerade eine Sperre, die noch gilt?
     *
     * `Newsletter::isLocked()` liest nur die beiden Spalten und weiß nichts von
     * LOCK_TIMEOUT_MINUTES. Ein Vermerk, den jemand vor Stunden liegen gelassen
     * hat, sieht dort deshalb aus wie eine laufende Bearbeitung, obwohl
     * acquireLock() ihn längst überschreiben würde. Wer wissen will, ob gerade
     * wirklich jemand an dem Entwurf sitzt, fragt hier - nicht am Modell.
     *
     * Bewusst ohne releaseLock(): Eine Abfrage beantwortet nur, ob gesperrt ist -
     * sie schreibt nicht. Der abgelaufene Vermerk stört niemanden mehr, und wer
     * als Nächster bearbeitet, überschreibt ihn ohnehin in acquireLock().
     */
    public function hasActiveLock(Newsletter $newsletter): bool
    {
        return $newsletter->isLocked() && !$this->isLockExpired($newsletter);
    }

    /**
     * Sperrt gerade jemand anderes den Entwurf? Das ist die Frage hinter jedem
     * "wird gerade von einer anderen Person bearbeitet" - beim Versand, bei der
     * Testmail und beim Löschen.
     *
     * Ohne angemeldete Person lautet die Antwort ja: Wer niemand ist, kann auch
     * nicht der Inhaber sein.
     */
    public function isLockedByOther(Newsletter $newsletter, ?int $userId): bool
    {
        if (!$this->hasActiveLock($newsletter)) {
            return false;
        }

        return $userId === null || (int) $newsletter->locked_by !== $userId;
    }

    /**
     * Check if user can edit (has lock or no lock exists)
     *
     * @param Newsletter $newsletter
     * @param int|null $userId
     * @return bool
     */
    public function canEdit(Newsletter $newsletter, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return !$this->isLockedByOther($newsletter, $userId);
    }

    /**
     * Check if newsletter is locked by specific user
     *
     * @param Newsletter $newsletter
     * @param int|null $userId
     * @return bool
     */
    public function isLockedBy(Newsletter $newsletter, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->hasActiveLock($newsletter) && (int) $newsletter->locked_by === $userId;
    }

    /**
     * Check if lock has expired
     *
     * @param Newsletter $newsletter
     * @return bool
     */
    private function isLockExpired(Newsletter $newsletter): bool
    {
        if ($newsletter->locked_at === null) {
            return true;
        }

        // copy(): Carbons addMinutes() verändert die Instanz. Eloquent gibt bei
        // jedem Zugriff auf ein datetime-Feld zwar eine frische Instanz heraus,
        // heute geht also nichts kaputt - wer den Wert aber einmal in einer
        // Variablen festhält und danach weiterbenutzt, bekäme einen um eine
        // halbe Stunde verschobenen Zeitpunkt.
        $expiryTime = $newsletter->locked_at->copy()->addMinutes(self::LOCK_TIMEOUT_MINUTES);

        return Carbon::now()->gt($expiryTime);
    }
}
