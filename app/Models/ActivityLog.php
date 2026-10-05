<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
