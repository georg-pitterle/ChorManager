<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Sponsor;
use App\Models\SponsoringContact;
use PHPUnit\Framework\TestCase;

/**
 * Zu jedem Sponsor-Typ und jeder Kontaktart gehört eine vollständige deutsche
 * Darstellung, und sie steht am Modell - nicht in einer Vorlage und nicht in
 * einem Controller.
 *
 * Vorher stand sie an vier bzw. zwei Stellen: Die Beschriftung von
 * `sponsors.type` stand dreimal in den Sponsoring-Vorlagen, und die beiden
 * Fassungen waren sich nicht einig - die Einzelansicht und die Auswahlfelder
 * schrieben "Organisation oder Firma", die Zeile unter dem Namen in der
 * Übersicht nur "Organisation". Die Kontaktarten standen als Twig-Zuordnung in
 * `sponsoring/sponsors/detail.twig` und ein zweites Mal als lokales Array in
 * `SponsoringDashboardController::mapRecentContact()`, dort bei jedem Aufruf neu
 * gebaut.
 *
 * Gleiche Begründung wie bei `MailQueue::MAIL_TYPE_LABELS` und
 * `Task::STATUS_LABELS`: Eine Zuordnung, die mehrfach dasteht, wird irgendwann
 * nur an einer Stelle gepflegt - und ein Wert, den eine Kette oder ein
 * `default`-Filter nicht kennt, steht in der deutschen Oberfläche als
 * englischer Schlüssel da.
 *
 * Die beiden Fassungen der Sponsor-Beschriftung bleiben erhalten, aber benannt:
 * `TYPE_LABELS` ist die ausgeschriebene für Auswahlfeld und Kopfzeile,
 * `TYPE_SHORT_LABELS` die knappe für die Meta-Zeile der Übersicht, in der sie
 * mit Kategorie und Kontaktperson in einer Zeile steht. Das hält die Oberfläche
 * unverändert und nimmt der Doppelung trotzdem die Gelegenheit, auseinanderzulaufen.
 */
final class SponsorTypeValuesTest extends TestCase
{
    /**
     * Zuordnung mit ihrem Namen für die Fehlermeldung und der Werteliste, die
     * sie vollständig abdecken muss.
     *
     * @return array<string, array{array<string, string>, list<string>}>
     */
    public static function labelMapProvider(): array
    {
        return [
            'Sponsor::TYPE_LABELS' => [Sponsor::TYPE_LABELS, Sponsor::TYPES],
            'Sponsor::TYPE_SHORT_LABELS' => [Sponsor::TYPE_SHORT_LABELS, Sponsor::TYPES],
            'SponsoringContact::TYPE_LABELS' => [SponsoringContact::TYPE_LABELS, SponsoringContact::TYPES],
        ];
    }

    /**
     * @param array<string, string> $labels
     * @param list<string> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('labelMapProvider')]
    public function testJedeZuordnungDecktGenauIhreWerteAb(array $labels, array $values): void
    {
        $keys = array_keys($labels);
        sort($keys);
        sort($values);

        self::assertSame($values, $keys);
    }

    /**
     * @param array<string, string> $labels
     * @param list<string> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('labelMapProvider')]
    public function testKeineBeschriftungIstLeer(array $labels, array $values): void
    {
        foreach ($labels as $value => $label) {
            self::assertNotSame('', trim($label), sprintf('Der Wert "%s" hat keine Beschriftung.', $value));
        }

        self::assertNotSame([], $values);
    }

    /**
     * Die Beschriftungen stehen nicht mehr in den Vorlagen und nicht mehr im
     * Controller.
     *
     * Geprüft werden genau die Konstruktionen, die vorher dort standen, und
     * nicht die Zeichenketten überhaupt: "E-Mail" und "Sonstiges" kommen im
     * Baum an vielen Stellen vor, die mit Sponsoren nichts zu tun haben.
     *
     * @return array<string, array{string, string}>
     */
    public static function forbiddenSourceProvider(): array
    {
        return [
            'Sponsor-Typ ausgeschrieben in der Einzelansicht' => [
                'templates/sponsoring/sponsors/detail.twig',
                'Organisation oder Firma',
            ],
            'Sponsor-Typ knapp in der Einzelansicht' => [
                'templates/sponsoring/sponsors/detail.twig',
                'Einzelperson',
            ],
            'Sponsor-Typ ausgeschrieben in der Übersicht' => [
                'templates/sponsoring/sponsors/index.twig',
                'Organisation oder Firma',
            ],
            'Sponsor-Typ knapp in der Übersicht' => [
                'templates/sponsoring/sponsors/index.twig',
                'Einzelperson',
            ],
            'Kontaktarten als Twig-Zuordnung' => [
                'templates/sponsoring/sponsors/detail.twig',
                'contact_type_labels = {',
            ],
            'Kontaktarten als Array im Controller' => [
                'src/Controllers/SponsoringDashboardController.php',
                "'call' => 'Anruf'",
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenSourceProvider')]
    public function testDieBeschriftungStehtNichtMehrInVorlageOderController(
        string $relativePath,
        string $needle
    ): void {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);

        self::assertIsString($content, $relativePath . ' wurde nicht gefunden.');
        self::assertStringNotContainsString(
            $needle,
            $content,
            sprintf(
                '%s trägt "%s" wieder selbst. Die Beschriftung gehört an das Modell (Sponsor::TYPE_LABELS, '
                    . 'Sponsor::TYPE_SHORT_LABELS, SponsoringContact::TYPE_LABELS) und kommt von dort über '
                    . 'den Controller in die Vorlage.',
                $relativePath,
                $needle
            )
        );
    }
}
