<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ActivityLog extends Model
{
    protected $fillable = [
        'transaction_id',
        'user_id',
        'action',
        'description',
        'old_value',
        'new_value',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        foreach (['action', 'user_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('transaction', fn($t) => $t->where('receipt_number', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query;
    }

    public static function logAuth(int $userId, string $action, string $description): void
    {
        static::create([
            'transaction_id' => null,
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
        ]);
    }
}
