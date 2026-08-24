<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\RememberLogin;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Entwertet sämtliche bestehenden Anmeldungen.
 *
 * Die Sperre besteht aus zwei Teilen, die nur gemeinsam wirken:
 * `session_valid_after` entwertet die laufenden Sitzungen, und das Löschen der
 * Remember-Me-Token verhindert, dass ein gespeichertes Cookie den Benutzer
 * unmittelbar danach wieder anmeldet. Die AuthMiddleware wertet ein solches
 * Cookie nämlich vor dem Epochenvergleich aus und erzeugt dabei eine frische
 * `auth_epoch`, die immer über der Sperre liegt - ein Aufrufer, der nur die
 * Einstellung setzt, sperrt Remember-Me-Anmeldungen also gar nicht aus.
 *
 * Deshalb gehört beides in einen gemeinsamen Aufruf und nicht in die einzelnen
 * Aufrufstellen.
 */
class SessionInvalidationService
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public function invalidateAllLogins(): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => 'session_valid_after'],
            [
                'setting_value' => (string) time(),
                'binary_content' => '',
                'mime_type' => 'text/plain',
            ]
        );

        $deletedTokens = (int) RememberLogin::query()->delete();

        $this->logger->info('All logins invalidated.', [
            'event' => 'auth.sessions.invalidated',
            'deleted_remember_tokens' => $deletedTokens,
        ]);
    }
}
