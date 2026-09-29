<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\FilePublicLink;
use App\Models\StoredFile;
use App\Services\Files\FileResponseFactory;
use App\Services\Files\FileShareService;
use App\Services\RateLimiterService;
use App\Util\AttachmentPreview;
use App\Util\ClientIpResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Öffentliche Links auf Dateien - ohne Anmeldung.
 *
 * Preisgegeben wird nur, was der Link freigibt: Dateiname, Größe, Inhalt.
 * Kein Ordnername, keine Person. Ungültige, abgelaufene und widerrufene Links
 * sehen gleich aus, damit sich Tokens nicht durchprobieren lassen, ohne dass
 * es auffällt. Das Passwort ist über den vorhandenen RateLimiterService
 * begrenzt, je Link und IP.
 */
final class PublicFileLinkController
{
    private const UNLOCK_SESSION_KEY = 'files_public_link_unlocked';
    private const MAX_PASSWORD_ATTEMPTS = 10;
    private const PASSWORD_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly Twig $view,
        private readonly FileShareService $shares,
        private readonly FileResponseFactory $responses,
        private readonly RateLimiterService $rateLimiter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $token = (string) $args['token'];
        $link = $this->shares->resolvePublic($token);
        if ($link === null) {
            return $this->unavailable($response);
        }

        $file = StoredFile::findOrFail($link->file_id);
        $error = $_SESSION['public_link_error'] ?? null;
        unset($_SESSION['public_link_error']);

        return $this->noIndex($this->view->render($response, 'files/public_link.twig', [
            'token' => $token,
            'file' => $file,
            'locked' => !$this->isUnlocked($link),
            'previewable' => AttachmentPreview::isInlineServable((string) $file->mime_type),
            'error' => $error,
        ]));
    }

    public function unlock(Request $request, Response $response, array $args): Response
    {
        $token = (string) $args['token'];
        $link = $this->shares->resolvePublic($token);
        if ($link === null) {
            return $this->unavailable($response);
        }

        $key = 'files:public-link:' . (int) $link->id . ':' . ClientIpResolver::resolve($request);
        $limit = $this->rateLimiter->hit($key, self::MAX_PASSWORD_ATTEMPTS, self::PASSWORD_WINDOW_SECONDS);
        $body = $request->getParsedBody();
        $password = is_array($body) ? (string) ($body['password'] ?? '') : '';

        if (!$limit['allowed']) {
            $this->logger->warning('Public file link password blocked by rate limit.', [
                'event' => 'files.public_link.rate_limited',
                'link_id' => (int) $link->id,
            ]);
            $_SESSION['public_link_error'] = 'Zu viele Versuche. Bitte in einigen Minuten erneut probieren.';
        } elseif ($this->shares->checkPassword($link, $password)) {
            $this->rateLimiter->reset($key);
            $unlocked = $_SESSION[self::UNLOCK_SESSION_KEY] ?? [];
            $unlocked[(int) $link->id] = true;
            $_SESSION[self::UNLOCK_SESSION_KEY] = $unlocked;
        } else {
            $this->logger->notice('Wrong password for public file link.', [
                'event' => 'files.public_link.password_failed',
                'link_id' => (int) $link->id,
            ]);
            $_SESSION['public_link_error'] = 'Das Passwort stimmt nicht.';
        }

        return $response->withHeader('Location', '/s/' . $token)->withStatus(302);
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        return $this->deliver($request, $response, (string) $args['token'], false);
    }

    public function view(Request $request, Response $response, array $args): Response
    {
        return $this->deliver($request, $response, (string) $args['token'], true);
    }

    private function deliver(Request $request, Response $response, string $token, bool $inline): Response
    {
        $link = $this->shares->resolvePublic($token);
        if ($link === null) {
            return $this->unavailable($response);
        }
        if (!$this->isUnlocked($link)) {
            return $response->withHeader('Location', '/s/' . $token)->withStatus(302);
        }

        $file = StoredFile::findOrFail($link->file_id);
        $version = $file->currentVersion;
        if ($version === null) {
            return $this->unavailable($response);
        }

        // Teilanfragen eines Players zählen nicht als weiterer Download.
        $range = $request->getHeaderLine('Range');
        if ($range === '' || str_starts_with($range, 'bytes=0-')) {
            $this->shares->recordDownload($link);
        }

        $result = $inline && AttachmentPreview::isInlineServable((string) $version->mime_type)
            ? $this->responses->inline($response, $version, (string) $file->name, $range)
            : $this->responses->download($response, $version, (string) $file->name);

        return $this->noIndex($result);
    }

    private function isUnlocked(FilePublicLink $link): bool
    {
        if (!$link->hasPassword()) {
            return true;
        }

        return !empty(($_SESSION[self::UNLOCK_SESSION_KEY] ?? [])[(int) $link->id]);
    }

    private function unavailable(Response $response): Response
    {
        return $this->noIndex($this->view->render($response->withStatus(404), 'files/public_link_unavailable.twig'));
    }

    /**
     * Der Token steht in der Adresse: Suchmaschinen sollen ihn nicht aufnehmen,
     * und kein Zwischenspeicher soll die Datei behalten. Fremde Seiten sehen
     * den Pfad ohnehin nicht - die globale Referrer-Policy
     * (strict-origin-when-cross-origin) schickt ihnen nur den Ursprung.
     */
    private function noIndex(Response $response): Response
    {
        return $response
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
