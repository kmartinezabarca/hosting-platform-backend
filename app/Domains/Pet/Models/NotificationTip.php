<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Consejo reutilizable de la biblioteca de notificaciones de ROKE Pet.
 * El admin lo redacta una vez y lo envía/programa como campaña cuantas veces
 * quiera. Ver [[roke-chat-architecture]] para el resto del stack de notifs.
 */
class NotificationTip extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table      = 'pet_notification_tips';

    public const CATEGORIES = ['consejo', 'alimentacion', 'juego', 'salud', 'novedad'];

    protected $fillable = [
        'title', 'body', 'category', 'url', 'icon', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
