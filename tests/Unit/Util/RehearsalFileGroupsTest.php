<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\RehearsalFileGroups;
use PHPUnit\Framework\TestCase;

/**
 * Probenmaterial eines Lieds, nach Art gegliedert: Noten, Übe-Audio, MIDI,
 * weitere Dateien. Anlass war ein Lied mit 25 Dateien in einer einzigen Liste.
 */
final class RehearsalFileGroupsTest extends TestCase
{
    private const VOICES = ['Sopran', 'Alt', 'Tenor', 'Bass'];

    private static function file(string $name, string $mime): object
    {
        return (object) ['original_name' => $name, 'mime_type' => $mime];
    }

    /**
     * @param list<array{kind: string, files: list<array{attachment: object, label: string}>}> $groups
     * @return array<string, list<string>>
     */
    private static function labelsByKind(array $groups): array
    {
        $result = [];
        foreach ($groups as $group) {
            $result[$group['kind']] = array_map(static fn (array $f): string => $f['label'], $group['files']);
        }

        return $result;
    }

    public function testGroupsByKindInFixedOrderAndOmitsEmptyGroups(): void
    {
        $groups = RehearsalFileGroups::build([
            self::file('Text.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            self::file('Sopran.mp3', 'audio/mpeg'),
            self::file('Partitur.pdf', 'application/pdf'),
            self::file('Sopran.mid', 'audio/midi'),
            self::file('Alt.mid', 'audio/x-midi'),
        ], 'Lied', self::VOICES);

        $this->assertSame(['sheet', 'audio', 'midi', 'other'], array_column($groups, 'kind'));
        $this->assertSame(['Noten', 'Übe-Audio', 'MIDI', 'Weitere Dateien'], array_column($groups, 'label'));

        $onlyAudio = RehearsalFileGroups::build([self::file('Sopran.mp3', 'audio/mpeg')], 'Lied', self::VOICES);
        $this->assertSame(['audio'], array_column($onlyAudio, 'kind'));
    }

    public function testNoFilesYieldNoGroups(): void
    {
        $this->assertSame([], RehearsalFileGroups::build([], 'Lied', self::VOICES));
    }

    public function testLabelDropsTheSongTitlePrefixAndTheExtensionOfKnownKinds(): void
    {
        $groups = RehearsalFileGroups::build([
            self::file('Pseudo-Yoik_Alt1_langsam.mp3', 'audio/mpeg'),
            self::file('pseudo yoik - Partitur.pdf', 'application/pdf'),
            self::file('Pseudo-Yoik.pdf', 'application/pdf'),
            self::file('Pseudo-Yoik_Text.docx', 'application/msword'),
        ], 'Pseudo-Yoik', self::VOICES);

        $labels = self::labelsByKind($groups);

        $this->assertSame(['Alt1_langsam'], $labels['audio']);
        // Bliebe vom Namen nichts übrig, steht er ganz da.
        $this->assertSame(['Partitur', 'Pseudo-Yoik'], $labels['sheet']);
        // Unter "Weitere Dateien" sagt erst die Endung, was die Datei ist.
        $this->assertSame(['Text.docx'], $labels['other']);
    }

    public function testLabelDropsAPrefixAllFilesOfAGroupShare(): void
    {
        $groups = RehearsalFileGroups::build([
            self::file('Pseudo-Yoik_Einzelstimmen - Alt 1 (70%).mid', 'audio/midi'),
            self::file('Pseudo-Yoik_Einzelstimmen - Alt 1.mid', 'audio/midi'),
            self::file('Pseudo-Yoik_Einzelstimmen - Gesamt.mid', 'audio/midi'),
            self::file('Pseudo-Yoik_Einzelstimmen - Sopran 1.mid', 'audio/midi'),
            self::file('Einspielung Alt.mp3', 'audio/mpeg'),
            self::file('Einspielung Altstimme.mp3', 'audio/mpeg'),
            self::file('Partitur.pdf', 'application/pdf'),
        ], 'Pseudo-Yoik', self::VOICES);

        $labels = self::labelsByKind($groups);

        $this->assertSame(['Gesamt', 'Sopran 1', 'Alt 1', 'Alt 1 (70%)'], $labels['midi']);
        // Gekürzt wird nur an einer Wortgrenze, nie mitten im Wort ("Altstimme" ist
        // keiner Stimmgruppe zugeordnet und steht deshalb vorn).
        $this->assertSame(['Altstimme', 'Alt'], $labels['audio']);
        // Eine einzelne Datei hat keinen gemeinsamen Teil.
        $this->assertSame(['Partitur'], $labels['sheet']);

        // Bliebe nur eine Nummer übrig, fehlte das, was sie unterscheidet.
        $numbered = RehearsalFileGroups::build([
            self::file('Sopran 1.mp3', 'audio/mpeg'),
            self::file('Sopran 2.mp3', 'audio/mpeg'),
        ], 'Lied', self::VOICES);
        $this->assertSame(['Sopran 1', 'Sopran 2'], self::labelsByKind($numbered)['audio']);
    }

    public function testTitleWithSpacesMatchesNamesWithUnderscores(): void
    {
        $groups = RehearsalFileGroups::build([self::file('Ave_verum_Tenor.mp3', 'audio/mpeg')], 'Ave verum', self::VOICES);

        $this->assertSame(['Tenor'], self::labelsByKind($groups)['audio']);
    }

    public function testFilesFollowVoiceOrderWithUnassignedFilesFirstAndNaturalOrderWithin(): void
    {
        $groups = RehearsalFileGroups::build([
            self::file('Lied_Bass1.mp3', 'audio/mpeg'),
            self::file('Lied_Alt2.mp3', 'audio/mpeg'),
            self::file('Lied_Alt10.mp3', 'audio/mpeg'),
            self::file('Lied_Alt1_langsam.mp3', 'audio/mpeg'),
            self::file('Lied_Alt1.mp3', 'audio/mpeg'),
            self::file('Lied_Tenor.mp3', 'audio/mpeg'),
            self::file('Lied_Sopran1.mp3', 'audio/mpeg'),
            self::file('Lied_Tutti.mp3', 'audio/mpeg'),
            self::file('Lied_Altstimme.mp3', 'audio/mpeg'),
        ], 'Lied', self::VOICES);

        $this->assertSame(
            ['Altstimme', 'Tutti', 'Sopran1', 'Alt1', 'Alt1_langsam', 'Alt2', 'Alt10', 'Tenor', 'Bass1'],
            self::labelsByKind($groups)['audio']
        );
    }

    public function testFewFilesOpenEveryGroupManyOpenOnlySheetMusic(): void
    {
        $few = RehearsalFileGroups::build([
            self::file('Noten.pdf', 'application/pdf'),
            self::file('Sopran.mp3', 'audio/mpeg'),
            self::file('Alt.mp3', 'audio/mpeg'),
            self::file('Alt.mid', 'audio/midi'),
        ], 'Lied', self::VOICES);
        $this->assertSame([true, true, true], array_column($few, 'open'));

        $files = [self::file('Noten.pdf', 'application/pdf')];
        foreach (self::VOICES as $voice) {
            $files[] = self::file($voice . '.mp3', 'audio/mpeg');
        }
        $many = RehearsalFileGroups::build($files, 'Lied', self::VOICES);
        $this->assertSame(['sheet' => true, 'audio' => false], array_combine(
            array_column($many, 'kind'),
            array_column($many, 'open')
        ));
    }

    public function testKeepsTheAttachmentObject(): void
    {
        $file = self::file('Noten.pdf', 'application/pdf');

        $groups = RehearsalFileGroups::build([$file], 'Lied', self::VOICES);

        $this->assertSame($file, $groups[0]['files'][0]['attachment']);
    }
}
