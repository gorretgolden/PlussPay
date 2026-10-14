<?php

namespace App\Support;

use App\Models\ActivityLog;

/**
 * Add `use Auditable;` to a model and every create, update and delete writes an
 * audit row with the before and after values, so new tables are covered by default
 * instead of relying on someone remembering a manual ActivityLog::create() call.
 *
 * Models that already log a change with their own wording (transactions, settings,
 * exchange rates, users) keep doing that; this trait is for the records that had no
 * audit trail at all.
 */
trait Auditable
{
    /**
     * Extra columns to keep out of the trail beyond the always-secret set.
     *
     * @var array<int, string>
     */
    protected array $auditExcept = [];

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                ...ActivityLog::subjectAttributes($model),
                'action' => 'created',
                'description' => class_basename($model) . " #{$model->getKey()} created",
                'new_value' => json_encode($model->auditVisible($model->getAttributes())),
            ]);
        });

        static::updated(function ($model) {
            $changed = $model->getChanges();
            unset($changed['created_at'], $changed['updated_at']);

            if ($changed === []) {
                return;
            }

            $except = $model->auditExcept();
            $old = [];
            $new = [];

            foreach ($changed as $column => $value) {
                if (in_array($column, $except, true)) {
                    continue;
                }

                $old[$column] = $model->getOriginal($column);
                $new[$column] = $value;
            }

            if ($new === []) {
                return;
            }

            ActivityLog::create([
                'user_id' => auth()->id(),
                ...ActivityLog::subjectAttributes($model),
                'action' => 'updated',
                'description' => class_basename($model) . " #{$model->getKey()} updated",
                'old_value' => json_encode($old),
                'new_value' => json_encode($new),
            ]);
        });

        static::deleted(function ($model) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                ...ActivityLog::subjectAttributes($model),
                'action' => 'deleted',
                'description' => class_basename($model) . " #{$model->getKey()} deleted",
                'old_value' => json_encode($model->auditVisible($model->getAttributes())),
            ]);
        });
    }

    /**
     * Passwords, tokens and secrets never belong in an audit row, whatever the model.
     *
     * @return array<int, string>
     */
    protected function auditExcept(): array
    {
        return array_merge(
            ['password', 'remember_token', 'two_factor_secret', 'created_at', 'updated_at', 'deleted_at'],
            $this->auditExcept
        );
    }

    protected function auditVisible(array $attributes): array
    {
        foreach ($this->auditExcept() as $column) {
            unset($attributes[$column]);
        }

        return $attributes;
    }
}
