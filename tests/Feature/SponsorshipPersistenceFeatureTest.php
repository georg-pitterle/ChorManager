<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\SponsorshipBenefit;
use App\Models\User;
use App\Persistence\SponsorshipPersistence;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Schreiben der Vereinbarungen steht in der Persistenz, nicht im Controller.
 *
 * Eine Leistungszeile, deren Text gleich bleibt, behält ihren Haken - sonst müsste
 * nach jeder Korrektur der Liste alles neu abgehakt werden. Ein zweiter Haken ändert
 * Datum und Person des ersten nicht.
 */
final class SponsorshipPersistenceFeatureTest extends TestCase
{
    private SponsorshipPersistence $persistence;
    private Sponsorship $sponsorship;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->persistence = new SponsorshipPersistence();
        $sponsor = Sponsor::create(['name' => 'Persistenz ' . bin2hex(random_bytes(4))]);
        $this->sponsorship = $this->persistence->create([
            'sponsor_id' => $sponsor->id,
            'amount' => '100.00',
            'status' => SponsorshipStatus::REQUESTED,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function member(): User
    {
        return User::create([
            'first_name' => 'Haken',
            'last_name' => 'Person',
            'email' => 'haken.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }

    public function testALineKeepsItsTickWhenItsTextStays(): void
    {
        $this->persistence->syncLines($this->sponsorship->benefits(), ['Logo', 'Banner', 'Logo']);
        $logos = SponsorshipBenefit::where('sponsorship_id', $this->sponsorship->id)
            ->where('description', 'Logo')
            ->orderBy('sort_order')
            ->get();
        $first = $logos->first();
        $this->persistence->tickLine($first, 'delivered', true, null);
        $firstId = (int) $first->id;

        $this->persistence->syncLines($this->sponsorship->benefits(), ['Banner', 'Logo', 'Flyer']);

        $lines = SponsorshipBenefit::where('sponsorship_id', $this->sponsorship->id)
            ->orderBy('sort_order')
            ->get();
        self::assertSame(['Banner', 'Logo', 'Flyer'], $lines->pluck('description')->all());
        self::assertSame([0, 1, 2], $lines->pluck('sort_order')->map('intval')->all());
        $logo = $lines->firstWhere('description', 'Logo');
        self::assertSame($firstId, (int) $logo->id);
        self::assertNotNull($logo->delivered_at);
        self::assertNull($lines->firstWhere('description', 'Flyer')->delivered_at);
    }

    public function testAnEmptyListRemovesAllLines(): void
    {
        $this->persistence->syncLines($this->sponsorship->benefits(), ['Logo', 'Banner']);

        $this->persistence->syncLines($this->sponsorship->benefits(), []);

        self::assertSame(0, SponsorshipBenefit::where('sponsorship_id', $this->sponsorship->id)->count());
    }

    public function testTickingTwiceKeepsTheFirstDate(): void
    {
        $this->persistence->syncLines($this->sponsorship->benefits(), ['Logo']);
        $line = SponsorshipBenefit::where('sponsorship_id', $this->sponsorship->id)->firstOrFail();
        $first = $this->member();
        $second = $this->member();

        $this->persistence->tickLine($line, 'delivered', true, (int) $first->id);
        $firstDate = $line->fresh()->delivered_at;
        $this->persistence->tickLine($line->fresh(), 'delivered', true, (int) $second->id);

        $after = $line->fresh();
        self::assertEquals($firstDate, $after->delivered_at);
        self::assertSame((int) $first->id, (int) $after->delivered_by_user_id);
    }

    public function testUntickingClearsDateAndPerson(): void
    {
        $this->persistence->syncLines($this->sponsorship->benefits(), ['Logo']);
        $line = SponsorshipBenefit::where('sponsorship_id', $this->sponsorship->id)->firstOrFail();
        $this->persistence->tickLine($line, 'delivered', true, (int) $this->member()->id);

        $this->persistence->tickLine($line->fresh(), 'delivered', false, null);

        $after = $line->fresh();
        self::assertNull($after->delivered_at);
        self::assertNull($after->delivered_by_user_id);
    }
}
