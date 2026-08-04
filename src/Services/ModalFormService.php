<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Standardisierter Service für Modal-Formularfehlerbehandlung und Datenpersistierung.
 *
 * Pattern:
 * - scope: eindeutige ID (z.B. "user_create", "event_type_edit", "voice_group_sub_create")
 * - Session-Keys: {scope}_form (Formulardaten), {scope}_error (Error-Info), {scope}_open_modal
 *
 * Verwendungsbeispiel im Controller:
 *
 *   $service = new ModalFormService('user_create');
 *   if (!$data['email']) {
 *       $service->setError('E-Mail erforderlich.', ['first_name' => $data['first_name']]);
 *       return $redirect;
 *   }
 *   $service->clear();  // Bei Erfolg
 */
class ModalFormService
{
    private string $scope;

    public function __construct(string $scope)
    {
        $this->scope = $scope;
    }

    /**
     * Speichert Formulardaten in Session und markiert Modal zum Öffnen
     */
    public function remember(array $formData): void
    {
        $_SESSION[$this->scope . '_form'] = $formData;
        $_SESSION[$this->scope . '_open_modal'] = true;
    }

    /**
     * Setzt Fehler und speichert optional Formulardaten
     *
     * @param string $errorMessage Die Fehlermeldung
     * @param array $formData Optional: Formulardaten zum Speichern
     * @param array $errorMeta Optional: Zusätzliche Fehler-Metadaten (z.B. ['id' => 5])
     */
    public function setError(
        string $errorMessage,
        array $formData = [],
        array $errorMeta = [],
        bool $global = true
    ): void {
        // Lazily-loaded modals (e.g. the user edit form fragment) consume their state
        // in a follow-up request, after index() has already read+cleared the global
        // $_SESSION['error']. Keep the message inside the scoped state so the fragment
        // can still render it, and let those callers suppress the global banner.
        if ($global) {
            $_SESSION['error'] = $errorMessage;
        }

        $_SESSION[$this->scope . '_message'] = $errorMessage;

        if (!empty($formData)) {
            $_SESSION[$this->scope . '_form'] = $formData;
        }

        $_SESSION[$this->scope . '_open_modal'] = true;
        $_SESSION[$this->scope . '_error'] = !empty($errorMeta) ? $errorMeta : $this->scope;
    }

    /**
     * Gibt alle Modal-State-Daten zurück (für Template)
     */
    public function getState(): array
    {
        return [
            'form' => $_SESSION[$this->scope . '_form'] ?? [],
            'error' => $_SESSION[$this->scope . '_error'] ?? null,
            'message' => $_SESSION[$this->scope . '_message'] ?? null,
            'open_modal' => !empty($_SESSION[$this->scope . '_open_modal']),
        ];
    }

    /**
     * Löscht alle Modal-State-Daten (nach erfolgreichem Speichern)
     */
    public function clear(): void
    {
        unset(
            $_SESSION[$this->scope . '_form'],
            $_SESSION[$this->scope . '_error'],
            $_SESSION[$this->scope . '_message'],
            $_SESSION[$this->scope . '_open_modal']
        );
    }

    /**
     * Gibt den Scope zurück (für Template-Identifikation)
     */
    public function getScope(): string
    {
        return $this->scope;
    }
}
