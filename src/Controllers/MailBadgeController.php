<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MailBadgeViewService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Liefert den Stand des Mail-Badges als schlankes JSON.
 *
 * Das Badge entsteht sonst nur beim Rendern einer ganzen Seite. Wer sein Postfach
 * liest, tut das aber in einem eigenen Tab - der ChorManager-Tab daneben stellt von
 * sich aus keine neue Anfrage und zeigte den Zähler von vor dem Lesen weiter an.
 * Über diesen Endpunkt holt sich das Frontend beim Zurückwechseln in den Tab den
 * aktuellen Stand, ohne die Seite neu zu laden.
 *
 * Diese Klasse hält beide Signale, mit denen die reguläre Wartezeit des
 * IMAP-Abgleichs gezielt übersprungen wird - ausgewertet werden sie in
 * `MailBadgeRefreshMiddleware`.
 */
class MailBadgeController
{
    /**
     * Pfad dieses Endpunkts.
     *
     * Ein Aufruf hier ist per Definition die Bitte um einen aktuellen Zählerstand,
     * nicht um den zwischengespeicherten - die Middleware erzwingt dafür einen
     * IMAP-Abgleich.
     */
    public const REFRESH_PATH = '/profile/mail-badge';

    /**
     * Einmalvermerk in der Session: Der nächste Request gleicht ab, egal wie kurz
     * der letzte Abgleich her ist. Gesetzt beim Start des Webmails, weil sich das
     * Postfach in einem separaten Tab öffnet und dieser Tab davon nichts mitbekommt.
     */
    public const FORCE_SESSION_KEY = 'mail_badge_refresh_due';

    public function __construct(private readonly MailBadgeViewService $badgeView)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        $badge = $this->badgeView->forCurrentUser();

        $response->getBody()->write((string) json_encode(['unseen_count' => $badge['unseen_count']]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            // Ohne no-store beantwortet der Browser den nächsten Fokuswechsel aus
            // seiner eigenen Kopie - also mit genau dem veralteten Zähler, den dieser
            // Abruf beheben soll.
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(200);
    }
}
