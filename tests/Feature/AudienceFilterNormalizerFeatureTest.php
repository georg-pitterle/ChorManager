<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilterCondition as C;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\InvalidAudienceFilterException;
use PHPUnit\Framework\TestCase;

/**
 * Formularwerte einer Freigabe-Zeile: nur existierende Kennungen, sortiert,
 * ohne Doppelte; eine leere Zeile nur mit ausdrücklichem "Alle Mitglieder".
 */
class AudienceFilterNormalizerFeatureTest extends TestCase
{
    use FileFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    public function testKeepsExistingIdsSortedAndUnique(): void
    {
        $member = $this->createMember();
        $a = $this->createRoleFor($member, 'A');
        $b = $this->createRoleFor($member, 'B');

        $result = (new AudienceFilterNormalizer())->normalize([
            'conditions' => [
                'unsinn' => [1],
                C::CATEGORY_ROLE => [(string) $b->id, (string) $a->id, (string) $b->id, '999999', 'x'],
            ],
        ]);

        $expected = [(int) $a->id, (int) $b->id];
        sort($expected);
        $this->assertSame([C::CATEGORY_ROLE => $expected], $result);
    }

    public function testEmptyWithoutAllIsRejectedEvenIfOnlyUnknownIdsWereSent(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        (new AudienceFilterNormalizer())->normalize(['conditions' => [C::CATEGORY_ROLE => ['999999']]]);
    }

    public function testCategoryWithOnlyVanishedValuesIsRejectedInsteadOfDropped(): void
    {
        // Würde die Kategorie still wegfallen, träfe der Rest-Filter mehr Mitglieder
        // als vorher - aus "Rolle: gelöscht · Stimmgruppe: Sopran" (niemand) würde
        // "Stimmgruppe: Sopran" (alle Soprane).
        $member = $this->createMember();
        $group = $this->createVoiceGroupFor($member);

        try {
            (new AudienceFilterNormalizer())->normalize(['conditions' => [
                C::CATEGORY_ROLE => ['999999'],
                C::CATEGORY_VOICE_GROUP => [(string) $group->id],
            ]]);
            $this->fail('Kategorie mit nur verschwundenen Werten still entfernt.');
        } catch (InvalidAudienceFilterException $exception) {
            $this->assertStringContainsString('Rolle', $exception->getMessage());
        }
    }

    public function testVanishedValueNextToValidOneIsDroppedBecauseItMatchedNobody(): void
    {
        $member = $this->createMember();
        $group = $this->createVoiceGroupFor($member);

        $this->assertSame(
            [C::CATEGORY_VOICE_GROUP => [(int) $group->id]],
            (new AudienceFilterNormalizer())->normalize(['conditions' => [
                C::CATEGORY_VOICE_GROUP => ['999999', (string) $group->id],
            ]])
        );
    }

    public function testAllMembersYieldsEmptyConditionsAndIgnoresFields(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);

        $this->assertSame([], (new AudienceFilterNormalizer())->normalize([
            'all' => '1',
            'conditions' => [C::CATEGORY_ROLE => [(string) $role->id]],
        ]));
    }

    public function testSignatureIgnoresOrder(): void
    {
        $normalizer = new AudienceFilterNormalizer();

        $this->assertSame(
            $normalizer->signature([C::CATEGORY_ROLE => [1, 2], C::CATEGORY_PROJECT => [5]]),
            $normalizer->signature([C::CATEGORY_PROJECT => [5], C::CATEGORY_ROLE => [2, 1]])
        );
        $this->assertNotSame($normalizer->signature([]), $normalizer->signature([C::CATEGORY_ROLE => [1]]));
    }
}
