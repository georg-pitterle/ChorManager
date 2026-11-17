<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Song;
use App\Persistence\SongPersistence;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;
use Throwable;

/**
 * Das Schreiben der Liedbibliothek steht in der Persistenz, nicht im Controller.
 *
 * Ein neues Lied und seine Kategorien entstehen in einer Transaktion: Scheitert die
 * Zuordnung, bleibt kein Lied ohne seine Kategorien zurück.
 */
final class SongPersistenceFeatureTest extends TestCase
{
    private SongPersistence $persistence;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->persistence = new SongPersistence();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testANewSongIsNotLeftWithoutItsCategories(): void
    {
        $title = 'Ohne Kategorie ' . bin2hex(random_bytes(3));

        try {
            $this->persistence->createSong(['title' => $title], [999999]);
            self::fail('Eine unbekannte Kategorie hätte die Anlage abbrechen müssen.');
        } catch (Throwable) {
            // erwartet
        }

        self::assertSame(0, Song::where('title', $title)->count());
    }

    public function testANewSongGetsItsCategories(): void
    {
        $category = Category::create(['name' => 'Kat ' . bin2hex(random_bytes(3)), 'sort_order' => 1]);

        $song = $this->persistence->createSong(['title' => 'Mit Kategorie'], [(int) $category->id]);

        self::assertSame([(int) $category->id], $song->categories()->pluck('repertoire_categories.id')->map('intval')->all());
    }

    public function testSyncingCategoriesReplacesTheOldOnes(): void
    {
        $first = Category::create(['name' => 'Erste ' . bin2hex(random_bytes(3)), 'sort_order' => 1]);
        $second = Category::create(['name' => 'Zweite ' . bin2hex(random_bytes(3)), 'sort_order' => 2]);
        $song = $this->persistence->createSong(['title' => 'Wechsel'], [(int) $first->id]);

        $this->persistence->syncCategories($song, [(int) $second->id]);

        self::assertSame([(int) $second->id], $song->categories()->pluck('repertoire_categories.id')->map('intval')->all());
    }
}
