<?php

namespace App\Models;

use Illuminate\Support\Str;

/**
 * Custom DatabaseNotification model that uses an integer primary key with a
 * separate `uuid` column, consistent with the rest of the platform's schema.
 *
 * Laravel's built-in `DatabaseNotification` uses UUID as the primary key.
 * We extend it here and override the key behaviour so it matches our table.
 *
 * Wire this up in User by overriding the notifications() relationship:
 *
 *   public function notifications()
 *   {
 *       return $this->morphMany(DatabaseNotification::class, 'notifiable')
 *                   ->orderBy('created_at', 'desc');
 *   }
 */
class DatabaseNotification extends \Illuminate\Notifications\DatabaseNotification
{
    protected $table = 'notifications';

    // Integer auto-increment primary key
    public $incrementing = true;
    protected $keyType   = 'int';

    protected static function boot(): void
    {
        // Skip the parent boot which assumes UUID = PK; call grandparent instead
        \Illuminate\Database\Eloquent\Model::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Route model binding / external references use the `uuid` column.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
