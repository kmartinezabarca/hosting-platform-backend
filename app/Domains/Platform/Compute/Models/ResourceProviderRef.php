<?php

namespace App\Domains\Platform\Compute\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia interna a un objeto en un proveedor (Coolify, Pterodactyl,
 * Cloudflare). ÚNICO lugar donde viven IDs externos — este modelo no debe
 * serializarse nunca en respuestas de API de cliente.
 *
 * PK compuesta (resource_id, provider): sin autoincrement.
 */
class ResourceProviderRef extends Model
{
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'resource_id',
        'provider',
        'external_id',
        'external_meta',
    ];

    protected $casts = [
        'external_meta' => 'array',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
