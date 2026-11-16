<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Die WebDAV-Adresse, die in der Noten-App einzutragen ist.
 *
 * Sie stand im WebdavController, und der DownloadController rief sie von dort.
 */
final class WebdavUrl
{
    public const BASE_PATH = '/webdav';

    /**
     * Über AppUrlResolver und nicht selbst zusammengesetzt. Zwei Gründe:
     *
     *  - `Uri::getAuthority()` lieferte hinter dem Proxy
     *    `https://chor.example.org:80/webdav/` - Schema aus
     *    `X-Forwarded-Proto`, Port aus `SERVER_PORT` des internen Servers. Das
     *    nimmt kein Klient an.
     *  - Der `Host`-Kopf allein taugt auch nicht: Er ist frei wählbar. Der
     *    Resolver zieht deshalb `APP_URL` vor und wertet Weiterleitungsköpfe
     *    nur von einem vertrauten Proxy aus - dieselbe Grundlage, auf der die
     *    Links in Einladungen und Passwort-Mails stehen.
     *
     * Eine Adresse, die dauerhaft in einer App auf einem Tablet steht, gehört
     * erst recht nicht aus einer einzelnen Anfrage abgeleitet.
     */
    public static function base(ServerRequestInterface $request): string
    {
        return AppUrlResolver::resolveBaseUrl($request) . self::BASE_PATH . '/';
    }
}
