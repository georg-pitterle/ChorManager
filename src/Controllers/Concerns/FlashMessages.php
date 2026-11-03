<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

/**
 * Die Flash-Meldungen einer Seite: einmal lesen, dabei leeren.
 *
 * Eine Flash-Meldung gehört genau dem nächsten Seitenaufruf. Sie zu lesen und
 * sie zu löschen sind deshalb kein zwei Schritte, sondern einer - wer das Leeren
 * vergisst, zeigt dieselbe Meldung auf der nächsten Seite noch einmal.
 *
 * Der Dreisatz "beide lesen, beide leeren" stand wortgleich 28-mal in 21
 * Controllern. Nicht falsch, aber 25 Stellen, an denen der eine Schritt ohne den
 * anderen landen kann, und 25 Stellen, die eine Änderung am Mechanismus
 * nachziehen müsste.
 *
 * Nicht erfasst sind die sechs Stellen, die bewusst nur einen der beiden
 * Schlüssel anfassen (etwa PasswordResetController, der nur `error` kennt).
 * Sie über diesen Helfer zu führen würde den jeweils anderen Schlüssel
 * mitleeren und damit das Verhalten ändern.
 */
trait FlashMessages
{
    /**
     * @return array{success: string|null, error: string|null}
     */
    private function takeFlash(): array
    {
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return ['success' => $success, 'error' => $error];
    }
}
