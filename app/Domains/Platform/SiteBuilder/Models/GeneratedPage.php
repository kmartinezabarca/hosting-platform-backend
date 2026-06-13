<?php

namespace App\Domains\Platform\SiteBuilder\Models;

use App\Models\User;
use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Página generada por IA y persistida (SiteBuilder). El HTML autocontenido se
 * guarda para previsualizar/descargar y, en fases posteriores, desplegar.
 *
 * Nota: el DTO transitorio del resultado del proveedor es
 * App\Domains\Platform\SiteBuilder\Data\GeneratedPage (mismo basename, otro
 * propósito). Este es el registro persistido.
 */
class GeneratedPage extends Model
{
    use HasUuidColumn;

    protected $fillable = [
        'uuid',
        'user_id',
        'prompt',
        'site_name',
        'locale',
        'spec',
        'status',
        'title',
        'html',
        'provider',
        'model',
        'warnings',
        'published_at',
    ];

    protected $casts = [
        'spec'         => 'array',
        'warnings'     => 'array',
        'published_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** URL pública donde el backend sirve el HTML (dominio configurable). */
    public function publicUrl(): string
    {
        $base = rtrim((string) config('page_generator.public_base', config('app.url')), '/');

        return "{$base}/p/{$this->uuid}";
    }
}

