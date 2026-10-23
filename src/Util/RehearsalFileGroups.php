<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Gliedert die Dateien eines Lieds für die Probenmaterial-Seite nach Art.
 *
 * Ein Lied trägt oft zwei Dutzend Dateien - je Stimme ein Übe-Audio, dasselbe
 * langsam, dazu MIDI und Noten. In einer einzigen Liste findet darin niemand
 * seine Stimme. Gegliedert wird deshalb in feste Gruppen (Noten, Übe-Audio,
 * MIDI, weitere Dateien), und innerhalb einer Gruppe in Stimmreihenfolge.
 *
 * Die Stimme steht nur im Dateinamen, und dafür gibt es keine Konvention. Die
 * Reihenfolge ist deshalb ein Angebot, keine Zuordnung: Ein Name, in dem eine
 * Stimmgruppe als eigenes Wort vorkommt ("Alt1", "Bass_langsam"), rückt an
 * deren Platz; alles andere (Tutti, Klavier, Gesamt) steht davor.
 */
final class RehearsalFileGroups
{
    /** Bis zu so vielen Dateien sind alle Gruppen offen - Einklappen spart dann nichts. */
    private const OPEN_ALL_UP_TO = 4;

    private const PDF_MIME_TYPES = ['application/pdf'];
    private const AUDIO_MIME_TYPES = ['audio/mpeg'];
    public const MIDI_MIME_TYPES = ['audio/midi', 'audio/x-midi', 'application/x-midi'];

    /** Reihenfolge und Beschriftung der Gruppen. */
    private const KINDS = [
        'sheet' => 'Noten',
        'audio' => 'Übe-Audio',
        'midi' => 'MIDI',
        'other' => 'Weitere Dateien',
    ];

    /**
     * @param iterable<object> $attachments Anhänge mit original_name und mime_type
     * @param list<string> $voiceNames Stimmgruppen in Anzeigereihenfolge (Sopran, Alt, Tenor, Bass)
     * @return list<array{kind: string, label: string, open: bool, files: list<array{attachment: object, label: string}>}>
     */
    public static function build(iterable $attachments, string $songTitle, array $voiceNames): array
    {
        $byKind = array_fill_keys(array_keys(self::KINDS), []);
        $total = 0;

        foreach ($attachments as $attachment) {
            $kind = self::kindOf((string) $attachment->mime_type);
            $byKind[$kind][] = [
                'attachment' => $attachment,
                'label' => self::label((string) $attachment->original_name, $songTitle, $kind !== 'other'),
            ];
            $total++;
        }

        $groups = [];
        foreach ($byKind as $kind => $files) {
            if ($files === []) {
                continue;
            }

            $files = self::dropSharedPrefix($files);

            usort($files, static function (array $a, array $b) use ($voiceNames): int {
                return self::voiceRank($a['label'], $voiceNames) <=> self::voiceRank($b['label'], $voiceNames)
                    ?: strnatcasecmp($a['label'], $b['label']);
            });

            $groups[] = [
                'kind' => $kind,
                'label' => self::KINDS[$kind],
                'open' => $kind === 'sheet' || $total <= self::OPEN_ALL_UP_TO,
                'files' => $files,
            ];
        }

        return $groups;
    }

    private static function kindOf(string $mimeType): string
    {
        $mimeType = strtolower($mimeType);

        return match (true) {
            in_array($mimeType, self::PDF_MIME_TYPES, true) => 'sheet',
            in_array($mimeType, self::AUDIO_MIME_TYPES, true) => 'audio',
            in_array($mimeType, self::MIDI_MIME_TYPES, true) => 'midi',
            default => 'other',
        };
    }

    /**
     * Der Liedtitel steht über der Liste; im Dateinamen wiederholt er sich nur.
     * Leer-, Unter- und Bindestriche gelten dabei als gleichwertig, damit
     * "Ave verum" auch "Ave_verum_Tenor.mp3" kürzt. Die Endung fällt nur weg,
     * wenn die Gruppe schon sagt, was die Datei ist.
     */
    private static function label(string $fileName, string $songTitle, bool $dropExtension): string
    {
        $label = $fileName;
        if ($dropExtension) {
            $label = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $label);
        }

        $words = preg_split('/[\s_\-]+/u', trim($songTitle), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words !== []) {
            $pattern = '/^' . implode('[\s_\-]+', array_map(static fn (string $w): string => preg_quote($w, '/'), $words))
                . '[\s_\-–·.]*/iu';
            $shortened = (string) preg_replace($pattern, '', $label);
            if ($shortened !== '') {
                $label = $shortened;
            }
        }

        return $label;
    }

    /**
     * Ein Vorspann, den alle Dateien einer Gruppe teilen ("Einzelstimmen - "),
     * sagt nichts darüber, worin sie sich unterscheiden. Gekürzt wird nur bis zu
     * einem Trennzeichen, nie mitten im Wort, und nur, wenn danach in jedem
     * Namen noch ein Buchstabe steht - "1" und "2" allein wären keine Namen.
     *
     * @param list<array{attachment: object, label: string}> $files
     * @return list<array{attachment: object, label: string}>
     */
    private static function dropSharedPrefix(array $files): array
    {
        if (count($files) < 2) {
            return $files;
        }

        $common = $files[0]['label'];
        foreach ($files as $file) {
            while ($common !== '' && !str_starts_with($file['label'], $common)) {
                $common = mb_substr($common, 0, -1);
            }
        }

        if (preg_match('/^.*[\s_\-–·.]/us', $common, $match) !== 1) {
            return $files;
        }

        $length = strlen($match[0]);
        $shortened = [];
        foreach ($files as $file) {
            $rest = substr($file['label'], $length);
            if (preg_match('/\pL/u', $rest) !== 1) {
                return $files;
            }
            $shortened[] = ['attachment' => $file['attachment'], 'label' => $rest];
        }

        return $shortened;
    }

    /**
     * Platz der ersten Stimmgruppe, die als eigenes Wort im Namen steht; -1 ohne
     * Treffer. Ziffern zählen nicht als Teil des Wortes ("Alt1"), Buchstaben
     * schon ("Altstimme" ist keine Stimmgruppe "Alt").
     *
     * @param list<string> $voiceNames
     */
    private static function voiceRank(string $label, array $voiceNames): int
    {
        $best = null;
        $bestOffset = PHP_INT_MAX;

        foreach ($voiceNames as $rank => $voice) {
            $pattern = '/(?<!\pL)' . preg_quote($voice, '/') . '(?!\pL)/iu';
            if (preg_match($pattern, $label, $match, PREG_OFFSET_CAPTURE) === 1 && $match[0][1] < $bestOffset) {
                $best = $rank;
                $bestOffset = $match[0][1];
            }
        }

        return $best ?? -1;
    }
}
