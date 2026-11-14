<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Util\MailboxAllowlist;

/**
 * Die Freigabeliste, mit der Tests einen Controller bauen, die selbst nichts
 * über die Liste aussagen wollen.
 *
 * Ohne sie griffe der Controller zur Liste aus der Umgebung - und die ist im
 * Testlauf leer, gibt also nichts frei. Eine Angabe in phpunit.xml hätte
 * dasselbe geleistet, aber prozessweit: Jede Klasse hinge dann an einem Wert,
 * den eine andere im selben Prozess verändern kann.
 */
final class TestMailboxAllowlist
{
    /**
     * `127.0.0.1` steht für die Tests des SSRF-Schutzes auf der Liste: Sie
     * wollen sehen, dass ein freigegebener Host trotzdem nicht angesprochen
     * wird, wenn er auf eine interne Adresse zeigt.
     */
    public static function standard(): MailboxAllowlist
    {
        return new MailboxAllowlist(
            ['example.org'],
            ['imap.example.org', 'smtp.example.org', '127.0.0.1']
        );
    }
}
