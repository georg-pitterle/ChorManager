<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetCategory extends Model
{
    /**
     * Einnahme oder Ausgabe - dieselben zwei Werte wie `finances.type`, und
     * nicht zufällig: `BudgetService::computeActual()` reicht
     * `budget_categories.type` unverändert als Bedingung in die Abfrage auf
     * `finances` weiter. Es ist also ein Vokabular, nicht zwei, und die Liste
     * wird deshalb von `Finance::TYPES` genommen statt ein zweites Mal
     * aufgeschrieben.
     *
     * Dass sie hier überhaupt steht: `ModelSchemaConsistencyTest` hält eine
     * Liste nur dann mit dem ENUM der Spalte zusammen, wenn es sie am Modell
     * der Tabelle gibt. Ohne sie stand `budget_categories.type` ungeprüft da,
     * und `BudgetController` brachte die beiden Werte als eigene Aufzählung mit.
     *
     * @var list<string>
     */
    public const TYPES = Finance::TYPES;

    protected $table = 'budget_categories';

    protected $fillable = [
        'fiscal_year_start',
        'finance_group_id',
        'type',
    ];

    protected $casts = [
        'fiscal_year_start' => 'integer',
        'finance_group_id' => 'integer',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany */
    public function items()
    {
        return $this->hasMany(BudgetItem::class, 'budget_category_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo */
    public function financeGroup()
    {
        return $this->belongsTo(FinanceGroup::class, 'finance_group_id', 'id');
    }

    /**
     * Display name resolved from the linked finance group.
     */
    public function getGroupNameAttribute(): string
    {
        return $this->financeGroup->name ?? '';
    }
}
