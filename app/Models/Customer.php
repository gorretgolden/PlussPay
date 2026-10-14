<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

class Customer extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'name',
        'phone',
        'type',
        'country',
        'notes',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Files the sender and the receiver of a transfer into the directory.
     * The corridor runs Uganda to China, so the paying side is Ugandan and the
     * receiving side is Chinese until someone corrects it on the customer record.
     */
    public static function syncFromTransaction(Transaction $transaction): void
    {
        $userId = auth()->id() ?? $transaction->created_by;

        if (! $userId) {
            return;
        }

        static::recordSide($transaction->sender_name, $transaction->sender_contact, 'sender', 'Uganda', $userId);
        static::recordSide($transaction->receiver_name, $transaction->receiver_contact, 'receiver', 'China', $userId);
    }

    private static function recordSide(?string $name, ?string $phone, string $side, string $country, int $userId): ?self
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $phone = User::normalizePhone($phone);

        // Trashed rows are matched so a customer someone deleted on purpose is not
        // quietly brought back by the next transfer, and not duplicated either.
        $existing = static::withTrashed()
            ->where(fn ($q) => $phone
                ? $q->where('phone', $phone)
                : $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]))
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                return $existing;
            }

            if (! $existing->phone && $phone) {
                $existing->phone = $phone;
            }

            if ($existing->type !== 'both' && $existing->type !== $side) {
                $existing->type = 'both';
            }

            if ($existing->isDirty()) {
                $existing->save();
            }

            return $existing;
        }

        return static::create([
            'name' => $name,
            'phone' => $phone,
            'type' => $side,
            'country' => $country,
            'is_active' => true,
            'created_by' => $userId,
        ]);
    }

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        foreach (['type', 'country'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        return $query;
    }
}
