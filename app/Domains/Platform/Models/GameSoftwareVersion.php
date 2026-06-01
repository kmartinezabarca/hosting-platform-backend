<?php

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Versiones de software de servidores de juego compatibles con Pterodactyl.
 *
 * @property int         $id
 * @property string      $software_identifier   'paper' | 'vanilla' | 'fabric' | …
 * @property string      $version               '1.21.4' | 'latest' | …
 * @property bool        $is_active
 * @property bool        $is_recommended
 * @property int         $sort_order            DESC → mayor valor = primera posición
 * @property string|null $notes
 */
class GameSoftwareVersion extends Model
{
    protected $fillable = [
        'software_identifier',
        'version',
        'is_active',
        'is_recommended',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'is_recommended' => 'boolean',
        'sort_order'     => 'integer',
    ];

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /** Solo versiones activas. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Filtra por identificador de software. */
    public function scopeForSoftware(Builder $query, string $identifier): Builder
    {
        return $query->where('software_identifier', strtolower($identifier));
    }

    /** Orden de presentación: más reciente primero. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('sort_order')->orderByDesc('id');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Devuelve las versiones activas de un software como array de strings,
     * ordenadas para presentación (más reciente primero).
     */
    public static function activeVersionsFor(string $identifier): array
    {
        return static::active()
            ->forSoftware($identifier)
            ->ordered()
            ->pluck('version')
            ->all();
    }

    /**
     * Retorna el sort_order máximo actual para un software dado.
     * Útil al insertar una nueva versión al tope de la lista.
     */
    public static function nextSortOrder(string $identifier): int
    {
        return (int) static::forSoftware($identifier)->max('sort_order') + 1;
    }
}
