<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Models\User;
use App\Services\Oidc\AuthorizationCodeService;
use Psr\Log\LoggerInterface;

class UserPersistence
{
    private LoggerInterface $logger;
    private AuthorizationCodeService $authorizationCodeService;

    public function __construct(
        LoggerInterface $logger,
        ?AuthorizationCodeService $authorizationCodeService = null
    ) {
        $this->logger = $logger;
        $this->authorizationCodeService = $authorizationCodeService ?? new AuthorizationCodeService($logger);
    }

    /**
     * Speichert ein Mitglied - und zieht beim Archivieren die ausgestellten
     * Anmeldungen für angeschlossene Anwendungen mit ein.
     *
     * Der Widerruf hängt hier und nicht an den beiden Aufrufstellen in
     * UserController: Dort stünde er zweimal, und ein dritter Pfad, der ein
     * Mitglied inaktiv setzt, ließe ihn still aus.
     *
     * Was er nicht kann: eine bereits laufende Sitzung in der anderen Anwendung
     * beenden. Die läuft nach deren eigener Sitzungsdauer aus - so in der ersten
     * Ausbaustufe entschieden. Ein gesperrtes Mitglied kommt aber nicht mehr neu
     * hinein.
     */
    public function save(User $user): bool
    {
        $deactivating = $user->isDirty('is_active') && !(bool) $user->is_active;

        $saved = $user->save();

        if ($saved && $deactivating) {
            $revoked = $this->authorizationCodeService->revokeForUser((int) $user->id);

            if ($revoked['codes'] > 0 || $revoked['tokens'] > 0) {
                $this->logger->info('OIDC grants revoked for deactivated user.', [
                    'event' => 'oidc.grants.revoked',
                    'user_id' => (int) $user->id,
                    'codes' => $revoked['codes'],
                    'tokens' => $revoked['tokens'],
                ]);
            }
        }

        return $saved;
    }

    /**
     * Löscht einen Benutzer endgültig.
     *
     * Die Anwendung kennt derzeit keinen Aufrufer: Benutzer werden über
     * deactivate() archiviert, nicht gelöscht. Das Event liegt hier trotzdem
     * an der Datenmutation, damit ein später ergänzter Löschpfad
     * (etwa eine DSGVO-Löschung) ohne Zutun protokolliert wird.
     */
    public function delete(User $user): bool
    {
        $userId = (int) $user->id;
        $deleted = $user->delete() === true;

        if ($deleted) {
            $this->logger->info('User deleted.', [
                'event' => 'user.deleted',
                'user_id' => $userId,
            ]);
        }

        return $deleted;
    }

    public function syncRoles(User $user, array $roleIds): void
    {
        $user->roles()->sync($roleIds);
    }

    public function syncVoiceGroups(User $user, array $voiceGroupData): void
    {
        // Eloquent sync with pivot data
        // $voiceGroupData format: [ voice_group_id => ['sub_voice_id' => $subId], ... ]
        $user->voiceGroups()->sync($voiceGroupData);
    }
}
