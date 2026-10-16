<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The heads the spending is filed under. A category is renamed rather than rewritten: the name a
 * payment was posted under stays on that expense, so a description already written in the ledger
 * cannot be changed after the fact by editing a label.
 */
class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Everything in use, plus whatever a caller has already picked, so a choice is never dropped. */
    public static function options(?int $selectedId = null): array
    {
        return static::orderBy('name')
            ->where(function (Builder $query) use ($selectedId) {
                $query->where('is_active', true);

                if ($selectedId !== null) {
                    $query->orWhere('id', $selectedId);
                }
            })
            ->get()
            ->pluck('name', 'id')
            ->all();
    }
}
