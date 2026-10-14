<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ActivityLog extends Model
{
    protected $fillable = [
        'transaction_id',
        'subject_type',
        'subject_id',
        'user_id',
        'user_name',
        'user_role',
        'user_country',
        'ip_address',
        'request_path',
        'action',
        'description',
        'old_value',
        'new_value',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $request = request();

            // A resolved route means this came from a real HTTP request. Console
            // and queue writes have one too, and recording it would be misleading.
            if ($request && $request->route()) {
                $log->ip_address ??= $request->ip();
                $log->request_path ??= $request->method().' '.$request->path();
            }

            // The doer is snapshotted onto the row at write time, so every existing
            // ActivityLog::create() call site carries name, role and country without
            // changing. A user renamed or deleted later cannot rewrite history.
            static::snapshotUser($log);
        });
    }

    /**
     * Fill the who-behind-the-row columns from the acting user, whether the caller
     * passed a user_id or left it to the session. A console write with nobody signed
     * in is left blank rather than guessed at.
     */
    private static function snapshotUser(self $log): void
    {
        if ($log->user_name !== null) {
            return;
        }

        $user = $log->user_id
            ? User::find($log->user_id)
            : auth()->user();

        if (! $user) {
            return;
        }

        $log->user_id ??= $user->id;
        $log->user_name = $user->name;
        $log->user_role = $user->getRoleNames()->first();
        $log->user_country = $user->country;
    }

    /**
     * Point a log at the record it is about when that record is not a transfer, so
     * an audit entry can name its subject without a bespoke column per model.
     */
    public static function subjectAttributes(Model $subject): array
    {
        return [
            'subject_type' => $subject->getTable(),
            'subject_id' => $subject->getKey(),
        ];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->action));
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
                    ->orWhere('ip_address', 'like', "%{$search}%")
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

    /**
     * Entries written before the ip_address column existed only carry the address
     * inside the login and logout description, so read it back out of there.
     */
    public function sourceIp(): ?string
    {
        if ($this->ip_address) {
            return $this->ip_address;
        }

        return preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', (string) $this->description, $m) ? $m[0] : null;
    }

    /**
     * The changes() result flattened onto one line for the Excel exports, which
     * have no room for the modal's layout.
     */
    public function changeText(): string
    {
        $changes = $this->changes();

        return match ($changes['kind']) {
            'scalar' => "was: {$changes['old']} | now: {$changes['new']}",
            'fields' => implode('; ', array_map(
                fn ($row) => "{$row['label']}: {$row['old']} -> {$row['new']}",
                array_filter($changes['rows'], fn ($row) => $row['changed'])
            )),
            'items' => trim(implode(' | ', array_filter([
                count($changes['added']) ? '+ ' . implode(', ', $changes['added']) : '',
                count($changes['removed']) ? '- ' . implode(', ', $changes['removed']) : '',
            ]))),
            default => '',
        };
    }

    /**
     * Turns the stored old_value/new_value pair into something renderable.
     * The columns hold three shapes: a JSON object of fields, a JSON list of
     * names, or a plain scalar such as a rate or a status.
     */
    public function changes(): array
    {
        $old = $this->decode($this->old_value);
        $new = $this->decode($this->new_value);

        if ($old === null && $new === null) {
            return ['kind' => 'none'];
        }

        if (is_array($old) || is_array($new)) {
            if ($this->isAssoc($old) || $this->isAssoc($new)) {
                return ['kind' => 'fields', 'rows' => $this->fieldRows($old, $new)];
            }

            $old = is_array($old) ? $old : [];
            $new = is_array($new) ? $new : [];

            return [
                'kind' => 'items',
                'added' => array_values(array_diff($new, $old)),
                'removed' => array_values(array_diff($old, $new)),
                'kept' => array_values(array_intersect($old, $new)),
            ];
        }

        return ['kind' => 'scalar', 'old' => $this->render($old), 'new' => $this->render($new)];
    }

    private function fieldRows($old, $new): array
    {
        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $rows = [];

        foreach ($keys as $key) {
            $before = $this->render($old[$key] ?? null);
            $after = $this->render($new[$key] ?? null);

            $rows[] = [
                'label' => ucwords(str_replace(['_', '-'], ' ', (string) $key)),
                'old' => $before,
                'new' => $after,
                'changed' => $before !== $after,
            ];
        }

        return $rows;
    }

    private function decode(?string $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function isAssoc($value): bool
    {
        return is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }

    private function render($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => $this->render($item), $value));
        }

        return (string) $value;
    }
}
