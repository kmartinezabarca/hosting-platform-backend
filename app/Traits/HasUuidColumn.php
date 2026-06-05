<?php

namespace App\Traits;

use Illuminate\Support\Str;

/**
 * Automatically generates a UUID value for the `uuid` column when a model
 * is first created.  Add this trait to any Eloquent model whose table has
 * a separate `uuid` column (i.e. NOT the primary key — just a public
 * identifier stored alongside a regular integer auto-increment `id`).
 *
 * Usage:
 *   use App\Traits\HasUuidColumn;
 *
 *   class MyModel extends Model
 *   {
 *       use HasUuidColumn;
 *   }
 */
trait HasUuidColumn
{
    protected static function bootHasUuidColumn(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Route model binding resolves by `uuid` (public-facing identifier).
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
