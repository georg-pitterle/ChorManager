<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Die Frage, die der Authorize-Endpunkt vor dem Ausstellen stellt: Lässt sich
 * überhaupt signieren?
 *
 * Nötig ist das, weil sich das Modul einschalten lässt, ohne dass
 * OIDC_SIGNING_KEY_SECRET gesetzt oder je ein Schlüssel erzeugt worden wäre.
 * Der Schlüssel wird aber erst am Token-Endpunkt gebraucht. Ohne diese Prüfung
 * liefe die Anmeldung bis dahin durch, das Mitglied käme angemeldet in der
 * anderen Anwendung an, und erst der Tausch bräche ab - mit einem
 * Serverfehler an der Stelle, an der niemand mehr etwas damit anfangen kann,
 * und einer Zeile in oidc_auth_codes je Versuch.
 *
 * Der Schlüsseldienst kommt als Fabrik und nicht fertig gebaut herein: Sein
 * Konstruktor wirft ja gerade dann, wenn das Geheimnis fehlt. Läge er als
 * Abhängigkeit im Konstruktor, scheiterte schon das Auflösen des Controllers -
 * und statt der sauberen Abweisung stünde wieder der Serverfehler da, nur eine
 * Ebene früher.
 */
class OidcSigningReadiness
{
    /** @var Closure(): OidcSigningKeyService */
    private Closure $keyServiceFactory;

    /**
     * @param Closure(): OidcSigningKeyService $keyServiceFactory
     */
    public function __construct(
        Closure $keyServiceFactory,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->keyServiceFactory = $keyServiceFactory;
    }

    /**
     * Wahr, wenn ein aktiver Schlüssel hinterlegt ist und sich öffnen lässt.
     *
     * Beide Fälle - kein Schlüssel und unlesbarer Schlüssel - führen zum selben
     * Ergebnis, weil sie für den Aufrufer dasselbe bedeuten. Woran es lag,
     * steht im Log.
     */
    public function canSign(): bool
    {
        try {
            ($this->keyServiceFactory)()->activePrivateKey();

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('OIDC signing key unavailable.', [
                'event' => 'oidc.signing_key.unavailable',
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
