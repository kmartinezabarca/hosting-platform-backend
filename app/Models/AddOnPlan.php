<?php

namespace App\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

/**
 * Pivot model for the add_on_plan table.
 * Needed so the `uuid` column is auto-populated on attach().
 */
class AddOnPlan extends Pivot
{
    use HasUuidColumn;

    protected $table = 'add_on_plan';

    // Pivot models don't normally call boot traits — override creating manually.
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
