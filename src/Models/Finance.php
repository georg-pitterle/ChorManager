<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEntityAttachments;
use Illuminate\Database\Eloquent\Model;

class Finance extends Model
{
    use HasEntityAttachments;

    /** Der Wert, unter dem Anhänge an dieser Buchung hängen. */
    public const ENTITY_TYPE = 'finance';

    /**
     * Richtung einer Buchung - Einnahme oder Ausgabe. Die Werte spiegeln das
     * ENUM der Spalte `finances.type`.
     *
     * Wie bei `MailQueue::STATUS_*` und `NewsletterRecipient::STATUS_*` auch:
     * Vorher standen die beiden Werte nur als Zeichenketten da - im
     * FinanceController (Bericht und Filter), im FinanceAccountService und im
     * Seed. Ein Tippfehler an einer davon wäre still geblieben, denn das ENUM
     * weist einen unbekannten Wert nicht in jedem Modus ab, und eine
     * `where('type', 'incom')` hätte schlicht nichts summiert.
     */
    public const TYPE_INCOME = 'income';
    public const TYPE_EXPENSE = 'expense';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_INCOME,
        self::TYPE_EXPENSE,
    ];

    /**
     * Wie bezahlt wurde. Die Werte spiegeln das ENUM der Spalte
     * `finances.payment_method`.
     *
     * Nicht zu verwechseln mit `FinanceAccount::TYPE_CASH`/`TYPE_BANK`: Das ist
     * die Art des Kontos, nicht die Zahlungsart der Buchung. Beide Spalten
     * schreiben `cash` gleich, meinen aber Verschiedenes, und die Übersetzung
     * zwischen ihnen stand in `FinanceAccount::paymentMethod()` und
     * `FinanceAccountService::defaultAccountForPaymentMethod()` mit unbenannten
     * Zeichenketten da - an beiden Stellen war nicht zu sehen, welche der zwei
     * Bedeutungen gerade gemeint ist.
     */
    public const PAYMENT_METHOD_CASH = 'cash';
    public const PAYMENT_METHOD_BANK_TRANSFER = 'bank_transfer';

    /** @var list<string> */
    public const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_CASH,
        self::PAYMENT_METHOD_BANK_TRANSFER,
    ];

    protected $table = 'finances';
    public $timestamps = false;

    protected $fillable = [
        'running_number',
        'invoice_date',
        'payment_date',
        'description',
        'type',
        'amount',
        'payment_method',
        'finance_account_id',
        'import_hash',
        'reversal_of_id'
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    /**
     * Alle Gruppen der Buchung: Kostenart und beliebig viele Anlässe.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function groups()
    {
        return $this->belongsToMany(FinanceGroup::class, 'finance_group_assignments', 'finance_id', 'finance_group_id');
    }

    /**
     * Namen der Gruppen, alphabetisch und ohne Rücksicht auf Groß-/Kleinschreibung.
     * Liste, Export und Journal zeigen sie in dieser Reihenfolge, damit dieselbe
     * Zuordnung immer gleich aussieht.
     *
     * @return list<string>
     */
    public function groupNames(): array
    {
        $names = $this->groups->map(static fn(FinanceGroup $group): string => (string) $group->name)->all();
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($names);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo */
    public function financeAccount()
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id', 'id');
    }

    /** Die Buchung, die diese Gegenbuchung storniert. */
    public function reversalOf()
    {
        return $this->belongsTo(Finance::class, 'reversal_of_id', 'id');
    }

    /** Die Gegenbuchung, die diese Buchung storniert. */
    public function reversedBy()
    {
        return $this->hasOne(Finance::class, 'reversal_of_id', 'id');
    }

    /**
     * Storno-Status bewusst als Accessor statt als isReversal()-Methode und
     * statt eines direkten Relationszugriffs im Template:
     *
     * - Eine parameterlose Methode hält Eloquent beim Property-Zugriff für eine
     *   Relation und wirft "must return a relationship instance".
     * - Auf eine ungeladene Relation greift Twig per Methodenaufruf zu und
     *   bekommt das Relation-Objekt zurück, das immer truthy ist.
     *
     * Accessoren liefern dagegen zuverlässig den Wahrheitswert.
     */
    public function getIsReversalAttribute(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function getIsReversedAttribute(): bool
    {
        if ($this->relationLoaded('reversedBy')) {
            return $this->getRelation('reversedBy') !== null;
        }

        return $this->reversedBy()->exists();
    }
}
