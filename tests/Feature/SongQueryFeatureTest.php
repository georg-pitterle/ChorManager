<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Song;
use App\Queries\SongQuery;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Abfragen der Liedbibliothek stehen in der Abfrageschicht.
 *
 * Die Suche trifft Titel, Komponist, Arrangeur und Verlag; die Kategorien kommen nach
 * ihrer Reihenfolge, bei Gleichstand nach Name.
 */
final class SongQueryFeatureTest extends TestCase
{
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->marker = 'Suchwort' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testSearchMatchesComposerArrangerAndPublisher(): void
    {
        Song::create(['title' => 'Eins', 'composer' => $this->marker]);
        Song::create(['title' => 'Zwei', 'arranger' => $this->marker]);
        Song::create(['title' => 'Drei', 'publisher' => $this->marker]);
        Song::create(['title' => 'Vier', 'composer' => 'Anderer']);

        $titles = (new SongQuery())->forIndex($this->marker, 0)->pluck('title')->all();

        self::assertSame(['Drei', 'Eins', 'Zwei'], $titles);
    }

    public function testTheCategoryFilterOnlyKeepsSongsOfThatCategory(): void
    {
        $category = Category::create(['name' => 'Filter ' . bin2hex(random_bytes(3)), 'sort_order' => 1]);
        $inside = Song::create(['title' => 'Drin ' . $this->marker]);
        Song::create(['title' => 'Draußen ' . $this->marker]);
        $inside->categories()->sync([(int) $category->id]);

        $titles = (new SongQuery())->forIndex($this->marker, (int) $category->id)->pluck('title')->all();

        self::assertSame(['Drin ' . $this->marker], $titles);
    }

    public function testCategoriesFollowSortOrderThenName(): void
    {
        // Die Reihenfolge der übrigen Kategorien der Datenbank bleibt unberührt:
        // gefiltert wird auf die drei eigenen, über ein Sortierfeld weit hinten.
        $suffix = ' ' . bin2hex(random_bytes(3));
        Category::create(['name' => 'B' . $suffix, 'sort_order' => 900002]);
        Category::create(['name' => 'Z' . $suffix, 'sort_order' => 900001]);
        Category::create(['name' => 'A' . $suffix, 'sort_order' => 900001]);

        $names = (new SongQuery())->categoriesInOrder()
            ->filter(static fn (Category $category): bool => (int) $category->sort_order >= 900001)
            ->pluck('name')
            ->values()
            ->all();

        self::assertSame(['A' . $suffix, 'Z' . $suffix, 'B' . $suffix], $names);
    }

    /**
     * Ein Prozentzeichen oder Unterstrich im Suchfeld ist ein gesuchtes Zeichen,
     * kein Platzhalter. Unmaskiert machte "%" aus der Suche ein "alles" und "_"
     * traf jeden Titel mit mindestens einem Zeichen an der Stelle - die Trefferliste
     * war dann breiter als das, was dasteht.
     */
    public function testSearchTreatsPercentAndUnderscoreAsLiteralCharacters(): void
    {
        $tag = bin2hex(random_bytes(4));
        Song::create(['title' => 'Prozent 100% ' . $tag]);
        Song::create(['title' => 'Ohne Zeichen 1000 ' . $tag]);
        Song::create(['title' => 'Unterstrich a_b ' . $tag]);
        Song::create(['title' => 'Unterstrich axb ' . $tag]);

        $query = new SongQuery();

        self::assertSame(
            ['Prozent 100% ' . $tag],
            $query->forIndex('100% ' . $tag, 0)->pluck('title')->all()
        );
        self::assertSame(
            ['Unterstrich a_b ' . $tag],
            $query->forIndex('a_b ' . $tag, 0)->pluck('title')->all()
        );
    }
}
