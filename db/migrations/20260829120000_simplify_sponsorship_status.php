<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Reduziert die sechs Status einer Vereinbarung auf die fünf, die im Chor
 * tatsächlich unterschieden werden. "Kontaktiert" und "Verhandlung" waren in
 * der Praxis nicht trennscharf, "Interessent" doppelte den Sponsor-Status und
 * eine Absage ließ sich überhaupt nicht festhalten.
 */
final class SimplifySponsorshipStatus extends AbstractMigration
{
    private const LEGACY_VALUES = "'prospect','contacted','negotiating','active','paused','closed'";
    private const CURRENT_VALUES = "'requested','reminded','accepted','declined','closed'";
    private const COMBINED_VALUES = "'prospect','contacted','negotiating','active','paused','closed',"
        . "'requested','reminded','accepted','declined'";

    /**
     * "Pausiert" beschrieb eine bestehende, ruhende Vereinbarung - keine
     * offene Anfrage. Auf "Erinnert" abgebildet landete ihr Betrag in der
     * Pipeline der offenen Anfragen, und der Sponsor erschien als "Anfrage
     * läuft"; Mitglieder hätten bei jemandem nachgefasst, bei dem gar nichts
     * offen ist. "Abgeschlossen" sagt weder eine Anfrage noch zugesagtes Geld
     * zu und ist damit die ehrlichste Abbildung; wer eine pausierte
     * Vereinbarung wieder aufnimmt, setzt den Status von Hand.
     *
     * @var array<string, string>
     */
    private const FORWARD_MAP = [
        'prospect' => 'requested',
        'contacted' => 'requested',
        'negotiating' => 'reminded',
        'paused' => 'closed',
        'active' => 'accepted',
    ];

    /**
     * Rückrichtung. Verlustbehaftet und deshalb bewusst grob: "Angefragt" und
     * "Erinnert" fallen auf die nächstliegenden Altwerte zurück, eine Absage
     * hat im alten Set überhaupt keine Entsprechung und landet auf
     * "Abgeschlossen".
     *
     * @var array<string, string>
     */
    private const BACKWARD_MAP = [
        'requested' => 'contacted',
        'reminded' => 'negotiating',
        'accepted' => 'active',
        'declined' => 'closed',
    ];

    public function up(): void
    {
        $this->widenEnum();

        foreach (self::FORWARD_MAP as $from => $to) {
            $this->execute(sprintf(
                "UPDATE sponsorships SET status = '%s' WHERE status = '%s'",
                $to,
                $from
            ));
        }

        $this->narrowEnum(self::CURRENT_VALUES, 'requested');
    }

    public function down(): void
    {
        $this->widenEnum();

        foreach (self::BACKWARD_MAP as $from => $to) {
            $this->execute(sprintf(
                "UPDATE sponsorships SET status = '%s' WHERE status = '%s'",
                $to,
                $from
            ));
        }

        $this->narrowEnum(self::LEGACY_VALUES, 'prospect');
    }

    /**
     * Zwischenschritt mit allen alten und neuen Werten: ohne ihn scheitert das
     * UPDATE, weil das Ziel noch kein gültiger Enum-Wert ist.
     */
    private function widenEnum(): void
    {
        $this->execute(sprintf(
            'ALTER TABLE sponsorships MODIFY COLUMN status enum(%s) NOT NULL',
            self::COMBINED_VALUES
        ));
    }

    private function narrowEnum(string $values, string $default): void
    {
        $this->execute(sprintf(
            "ALTER TABLE sponsorships MODIFY COLUMN status enum(%s) NOT NULL DEFAULT '%s'",
            $values,
            $default
        ));
    }
}
