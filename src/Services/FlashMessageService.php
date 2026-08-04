<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Liest die Flash-Meldungen einmalig aus der Session und entfernt sie dabei.
 *
 * Controller legen Erfolgs- und Fehlermeldungen vor einem Redirect in der
 * Session ab. Zielseiten, die diese Meldungen nicht selbst auslesen (etwa das
 * Dashboard), ließen sie früher liegen - die Meldung tauchte dann erst beim
 * nächsten Seitenaufruf auf, der zufällig danach griff. Das Layout konsumiert
 * die Reste über diesen Service, damit jede Meldung genau einmal und sofort
 * erscheint.
 */
final class FlashMessageService
{
    /**
     * @return array{success: ?string, error: ?string}
     */
    public function consume(): array
    {
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;

        unset($_SESSION['success'], $_SESSION['error']);

        return [
            'success' => $success !== null ? (string) $success : null,
            'error' => $error !== null ? (string) $error : null,
        ];
    }
}
