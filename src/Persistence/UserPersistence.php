<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Models\User;
use Psr\Log\LoggerInterface;

class UserPersistence
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function save(User $user): bool
    {
        return $user->save();
    }

    /**
     * Loescht einen Benutzer endgueltig.
     *
     * Die Anwendung kennt derzeit keinen Aufrufer: Benutzer werden ueber
     * deactivate() archiviert, nicht geloescht. Das Event liegt hier trotzdem
     * an der Datenmutation, damit ein spaeter ergaenzter Loeschpfad
     * (etwa eine DSGVO-Loeschung) ohne Zutun protokolliert wird.
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
