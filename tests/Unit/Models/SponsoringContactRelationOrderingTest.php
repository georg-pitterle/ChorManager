<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Kontakthistorie steht überall gleich herum: der jüngste Kontakt zuerst.
 *
 * `SponsoringContactController::index()` sortierte schon so - `contact_date`
 * absteigend, `id` bricht den Gleichstand -, die beiden Beziehungen
 * `Sponsor::contacts()` und `Sponsorship::contacts()` dagegen gar nicht. Die
 * Steckbriefseite half sich mit einem `|sort` in Twig, an drei Stellen
 * wiederholt, und dieselbe Reihenfolge stand damit viermal im Baum.
 *
 * Der zweite Schlüssel `id` ist hier nicht Beiwerk, sondern der eigentliche
 * Punkt: `sponsoring_contacts.contact_date` ist ein `date`, kein `datetime`.
 * Zwei Kontakte am selben Tag sind nicht die Ausnahme, sondern der Normalfall -
 * man ruft an und schickt hinterher die Unterlagen. Für sie entschied bisher
 * die Datenbank, und PHPs Sortierung ist zwar stabil, gibt also die Eingangs-
 * reihenfolge weiter - nur ist genau die ohne `ORDER BY` nicht zugesichert.
 * Die Kontakthistorie konnte sich damit zwischen zwei Aufrufen umdrehen.
 *
 * Die Tests legen deshalb alle Kontakte auf denselben Tag.
 */
final class SponsoringContactRelationOrderingTest extends TestCase
{
    /** @var list<string> */
    private const NEWEST_FIRST = ['Unterlagen geschickt.', 'Rückruf erhalten.', 'Zuerst angerufen.'];

    private const SAME_DAY = '2026-04-17';

    private Sponsor $sponsor;

    private Sponsorship $sponsorship;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->sponsor = Sponsor::create([
            'type' => Sponsor::TYPE_ORGANIZATION,
            'name' => 'Bäckerei Reihenfolge ' . bin2hex(random_bytes(3)),
        ]);

        $this->sponsorship = Sponsorship::create([
            'sponsor_id' => $this->sponsor->id,
            'amount' => '250.00',
        ]);
    }

    protected function tearDown(): void
    {
        SponsoringContact::query()->where('sponsor_id', $this->sponsor->id)->delete();
        $this->sponsorship->delete();
        $this->sponsor->delete();

        parent::tearDown();
    }

    /**
     * Legt die Kontakte in umgekehrter Reihenfolge an, alle auf denselben Tag.
     * Dass das Datum wirklich bei allen gleich ist, prüft die Methode danach -
     * sonst liefe der Test am Gleichstand vorbei.
     */
    private function addContacts(?int $sponsorshipId): void
    {
        foreach (array_reverse(self::NEWEST_FIRST) as $summary) {
            SponsoringContact::create([
                'sponsor_id' => $this->sponsor->id,
                'sponsorship_id' => $sponsorshipId,
                'contact_date' => self::SAME_DAY,
                'type' => SponsoringContact::TYPE_CALL,
                'summary' => $summary,
                'follow_up_done' => 0,
            ]);
        }

        $distinctDates = SponsoringContact::query()
            ->where('sponsor_id', $this->sponsor->id)
            ->distinct()
            ->pluck('contact_date')
            ->all();

        self::assertCount(
            1,
            $distinctDates,
            'Alle Kontakte müssen auf denselben Tag fallen, sonst prüft der Test den Gleichstand nicht.'
        );
    }

    public function testTheSponsorShowsTheMostRecentContactFirst(): void
    {
        $this->addContacts(null);

        self::assertSame(self::NEWEST_FIRST, $this->sponsor->contacts()->pluck('summary')->all());
    }

    public function testTheSponsorshipShowsTheMostRecentContactFirst(): void
    {
        $this->addContacts($this->sponsorship->id);

        self::assertSame(self::NEWEST_FIRST, $this->sponsorship->contacts()->pluck('summary')->all());
    }

    /**
     * Die Beziehung bringt dieselbe Reihenfolge mit wie die eigenständige
     * Kontaktliste. Liefen die beiden auseinander, stünde derselbe Kontakt auf
     * der Steckbriefseite anders als unter `/sponsoring/contacts`.
     */
    public function testTheRelationAgreesWithTheStandaloneList(): void
    {
        $this->addContacts($this->sponsorship->id);

        $standalone = SponsoringContact::query()
            ->where('sponsor_id', $this->sponsor->id)
            ->orderBy('contact_date', 'desc')
            ->orderBy('id', 'desc')
            ->pluck('summary')
            ->all();

        self::assertSame($standalone, $this->sponsor->contacts()->pluck('summary')->all());
    }
}
