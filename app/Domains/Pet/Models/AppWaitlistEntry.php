<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Lead de la lista de espera de la app móvil de ROKE Pet (captura "avísame"
 * mientras la app no está publicada). Ver PublicController::joinAppWaitlist.
 */
class AppWaitlistEntry extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table      = 'pet_app_waitlist';

    protected $fillable = [
        'name', 'email', 'phone', 'platform', 'source', 'notified',
    ];

    protected $casts = [
        'notified' => 'boolean',
    ];
}
