<?php

namespace App\Models\Pet;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InboxNotification extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table      = 'pet_inbox_notifications';

    protected $fillable = [
        'owner_id', 'title', 'body', 'notif_type', 'url', 'tag',
        'read_at', 'archived_at',
    ];

    protected $casts = [
        'read_at'     => 'datetime',
        'archived_at' => 'datetime',
    ];

    public static function createForOwner(
        string  $ownerId,
        string  $title,
        string  $body    = '',
        ?string $notifType = null,
        ?string $url     = null,
        ?string $tag     = null,
    ): self {
        return self::create([
            'owner_id'   => $ownerId,
            'title'      => $title,
            'body'       => $body,
            'notif_type' => $notifType,
            'url'        => $url,
            'tag'        => $tag,
        ]);
    }
}
