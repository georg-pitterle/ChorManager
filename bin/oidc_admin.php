<?php

/**
 * Verwaltung des OpenID-Connect-Providers.
 *
 * In der ersten Ausbaustufe gibt es bewusst keine Oberfläche: Signierschlüssel,
 * angeschlossene Anwendung, Rolle-zu-Gruppe und die Kennungen der Bestandskonten
 * sind Einrichtungsschritte, die einmal beim Anschluss anfallen und danach
 * selten. Die Logik liegt in den Diensten unter src/Services/Oidc, dieses Skript
 * ist nur Ein- und Ausgabe.
 *
 * Aufruf:
 *   ddev php bin/oidc_admin.php key:generate
 *   ddev php bin/oidc_admin.php key:list
 *   ddev php bin/oidc_admin.php key:prune [--grace-days=30]
 *
 *   ddev php bin/oidc_admin.php client:create "Nextcloud" --redirect-uri="https://cloud.example.org/apps/user_oidc/code" [--post-logout-uri=...] [--untrusted]
 *   ddev php bin/oidc_admin.php client:list
 *   ddev php bin/oidc_admin.php client:delete <client_id>
 *
 *   ddev php bin/oidc_admin.php group:list
 *   ddev php bin/oidc_admin.php group:set "Vorstand" vorstand
 *   ddev php bin/oidc_admin.php group:unset "Vorstand"
 *
 *   ddev php bin/oidc_admin.php user:list-uid
 *   ddev php bin/oidc_admin.php user:set-uid max@example.org mmustermann
 *   ddev php bin/oidc_admin.php user:unset-uid max@example.org
 *
 * Das Client-Secret erscheint bei `client:create` genau einmal im Klartext.
 * Gespeichert wird nur sein Hash - verloren heißt neu ausstellen, nicht
 * nachschlagen.
 */

declare(strict_types=1);

use App\Models\OidcClient;
use App\Services\Oidc\OidcAdminService;
use App\Services\Oidc\OidcClientService;
use App\Services\Oidc\OidcSigningKeyService;
use App\Services\SecretBoxCryptoService;
use App\Util\CliBootstrap;
use Illuminate\Database\Capsule\Manager as Capsule;

require __DIR__ . '/bootstrap_cli.php';

$container = CliBootstrap::container();
$logger = CliBootstrap::logger();
$container->get(Capsule::class);

$command = $argv[1] ?? '';
$positional = [];
$options = [];

foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--')) {
        $body = substr($argument, 2);
        $separator = strpos($body, '=');
        if ($separator === false) {
            $options[$body][] = '';
            continue;
        }

        $options[substr($body, 0, $separator)][] = substr($body, $separator + 1);
        continue;
    }

    $positional[] = $argument;
}

/**
 * @param array<string, list<string>> $options
 * @return list<string>
 */
function optionValues(array $options, string $name): array
{
    return array_values(array_filter($options[$name] ?? [], static fn(string $v): bool => $v !== ''));
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$keyService = new OidcSigningKeyService(
    new SecretBoxCryptoService(OidcSigningKeyService::KEY_ENV, null, $logger),
    $logger
);
$clientService = new OidcClientService($logger);
$adminService = new OidcAdminService();

try {
    switch ($command) {
        case 'key:generate':
            $key = $keyService->generateKey();
            echo 'Neuer Signierschlüssel angelegt: ' . $key->kid . PHP_EOL;
            echo 'Der bisherige bleibt in JWKS stehen, bis er mit key:prune entfernt wird.' . PHP_EOL;
            break;

        case 'key:list':
            $keys = $keyService->allKeys();
            if ($keys === []) {
                echo 'Es ist kein Signierschlüssel hinterlegt. Zuerst key:generate ausführen.' . PHP_EOL;
                break;
            }

            foreach ($keys as $key) {
                printf(
                    "%-34s %-8s angelegt am %s\n",
                    (string) $key->kid,
                    $key->is_active ? 'aktiv' : 'abgelöst',
                    (string) $key->created_at
                );
            }
            break;

        case 'key:prune':
            $graceDays = (int) (optionValues($options, 'grace-days')[0] ?? '30');
            $removed = $keyService->pruneRetiredKeys($graceDays);
            printf("%d abgelöste Schlüssel entfernt (älter als %d Tage).\n", $removed, $graceDays);
            break;

        case 'client:create':
            $name = $positional[0] ?? '';
            if ($name === '') {
                fail('Der Name der Anwendung fehlt. Beispiel: client:create "Nextcloud" --redirect-uri=...');
            }

            $redirectUris = optionValues($options, 'redirect-uri');
            if ($redirectUris === []) {
                fail('Mindestens eine --redirect-uri ist nötig.');
            }

            $postLogoutUris = optionValues($options, 'post-logout-uri');
            foreach ([...$redirectUris, ...$postLogoutUris] as $uri) {
                if (!$clientService->isRegistrableRedirectUri($uri)) {
                    fail(sprintf(
                        'Die Adresse "%s" lässt sich nicht eintragen. Erlaubt sind absolute '
                            . 'https-Adressen ohne Fragment (http nur gegen localhost).',
                        $uri
                    ));
                }
            }

            $created = $clientService->createClient(
                $name,
                $redirectUris,
                $postLogoutUris,
                !isset($options['untrusted'])
            );

            echo 'Anwendung eingetragen: ' . $name . PHP_EOL;
            echo 'Client-ID:     ' . (string) $created['client']->client_id . PHP_EOL;
            echo 'Client-Secret: ' . $created['secret'] . PHP_EOL;
            echo PHP_EOL;
            echo 'Das Secret erscheint nur dieses eine Mal. Gespeichert ist nur sein Hash -' . PHP_EOL;
            echo 'verloren heißt neu ausstellen, nicht nachschlagen.' . PHP_EOL;
            break;

        case 'client:list':
            $clients = OidcClient::query()->orderBy('name')->get();
            if ($clients->isEmpty()) {
                echo 'Es ist keine Anwendung eingetragen.' . PHP_EOL;
                break;
            }

            foreach ($clients as $client) {
                printf(
                    "%-20s %-34s %s%s\n  Rücksprung: %s\n",
                    (string) $client->name,
                    (string) $client->client_id,
                    $client->is_active ? 'aktiv' : 'gesperrt',
                    $client->is_trusted ? '' : ', nicht vertrauenswürdig',
                    implode(', ', $client->redirectUris())
                );
            }
            break;

        case 'client:delete':
            $clientId = $positional[0] ?? '';
            if ($clientId === '') {
                fail('Die Client-ID fehlt.');
            }

            $deleted = (int) OidcClient::query()->where('client_id', $clientId)->delete();
            if ($deleted === 0) {
                fail(sprintf('Es gibt keine Anwendung mit der Client-ID "%s".', $clientId));
            }

            echo 'Anwendung entfernt. Ihre offenen Codes und Token sind damit wertlos.' . PHP_EOL;
            break;

        case 'group:list':
            foreach ($adminService->listRoleGroups() as $row) {
                printf("%-30s %s\n", $row['name'], $row['group'] ?? '- keine Zuordnung -');
            }
            echo PHP_EOL;
            echo 'Nur Rollen mit Zuordnung wandern in den groups-Anspruch. Eine Rolle ohne' . PHP_EOL;
            echo 'Zuordnung geht gar nicht hinaus - es gibt keinen Rückfall auf den Rollennamen.' . PHP_EOL;
            break;

        case 'group:set':
            $roleName = $positional[0] ?? '';
            $group = $positional[1] ?? '';
            if ($roleName === '' || $group === '') {
                fail('Aufruf: group:set "<Rollenname>" <gruppe>');
            }

            $adminService->setRoleGroup($roleName, $group);
            printf("Die Rolle \"%s\" geht jetzt als Gruppe \"%s\" hinaus.\n", $roleName, $group);
            break;

        case 'group:unset':
            $roleName = $positional[0] ?? '';
            if ($roleName === '') {
                fail('Aufruf: group:unset "<Rollenname>"');
            }

            $adminService->setRoleGroup($roleName, null);
            printf("Die Rolle \"%s\" geht nicht mehr hinaus.\n", $roleName);
            break;

        case 'user:list-uid':
            $rows = $adminService->listUsersWithExternalUid();
            if ($rows === []) {
                echo 'Kein Mitglied hat eine eigene Kennung. Alle bekommen die Form cm-<id>.' . PHP_EOL;
                break;
            }

            foreach ($rows as $row) {
                printf("%-36s %-28s %s\n", $row['email'], $row['name'], $row['uid']);
            }
            break;

        case 'user:set-uid':
            $email = $positional[0] ?? '';
            $uid = $positional[1] ?? '';
            if ($email === '' || $uid === '') {
                fail('Aufruf: user:set-uid <e-mail> <kennung>');
            }

            $adminService->setExternalUid($email, $uid);
            printf("%s meldet sich künftig als \"%s\" an.\n", $email, $uid);
            break;

        case 'user:unset-uid':
            $email = $positional[0] ?? '';
            if ($email === '') {
                fail('Aufruf: user:unset-uid <e-mail>');
            }

            $adminService->unsetExternalUid($email);
            printf("%s bekommt wieder die abgeleitete Kennung cm-<id>.\n", $email);
            break;

        default:
            fail(
                'Unbekannter Unterbefehl. Bekannt sind: key:generate, key:list, key:prune, '
                    . 'client:create, client:list, client:delete, group:list, group:set, '
                    . 'group:unset, user:list-uid, user:set-uid, user:unset-uid.'
            );
    }
} catch (Throwable $exception) {
    $logger->error('OIDC admin command failed.', [
        'event' => 'oidc.admin.failed',
        'command' => $command,
        'exception' => $exception,
    ]);

    fail($exception->getMessage());
}

exit(0);
