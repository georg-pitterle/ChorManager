<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine fremde Anwendung, die sich Anmeldungen von ChorManager ausstellen lässt.
 *
 * Das Client-Secret steht nur als Passwort-Hash in der Datenbank. Es verlässt
 * den OidcClientService genau einmal, beim Anlegen über bin/oidc_admin.php -
 * verloren heißt neu ausstellen, nicht nachschlagen.
 *
 * `is_trusted` entscheidet, ob die Anmeldung ohne Einwilligungsseite abläuft.
 * Es gibt keine solche Seite, ein nicht vertrauenswürdiger Client wird deshalb
 * schlicht abgewiesen.
 */
class OidcClient extends Model
{
    protected $table = 'oidc_clients';
    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'client_secret_hash',
        'name',
        'redirect_uris',
        'post_logout_redirect_uris',
        'is_trusted',
        'is_active',
        'created_at',
        'updated_at',
    ];

    /**
     * Der Hash ist zwar nicht das Secret selbst, taugt aber zum Abgleich gegen
     * ein geratenes - in Logs und JSON-Antworten hat er nichts verloren.
     *
     * @var list<string>
     */
    protected $hidden = [
        'client_secret_hash',
    ];

    protected $casts = [
        'is_trusted' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public function redirectUris(): array
    {
        return self::decodeUriList($this->redirect_uris);
    }

    /**
     * @return list<string>
     */
    public function postLogoutRedirectUris(): array
    {
        return self::decodeUriList($this->post_logout_redirect_uris);
    }

    /**
     * @param list<string> $uris
     */
    public static function encodeUriList(array $uris): string
    {
        return json_encode(array_values($uris), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<string>
     */
    private static function decodeUriList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', $decoded));
    }
}
