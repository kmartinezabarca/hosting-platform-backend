<?php

namespace App\Models\Pet;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetScanEvent extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'pet_scan_events';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'pet_id', 'scanned_at', 'source',
        'ip_address', 'user_agent',
        'share_location_allowed', 'latitude', 'longitude', 'accuracy',
        'country', 'city', 'device_type', 'metadata',
    ];

    protected $casts = [
        'scanned_at'             => 'datetime',
        'share_location_allowed' => 'boolean',
        'latitude'               => 'float',
        'longitude'              => 'float',
        'accuracy'               => 'float',
        'metadata'               => 'array',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class, 'pet_id');
    }

    /** Ubicación pública: coordenadas reducidas a 2 decimales (~1 km de precisión). */
    public function approximateLocation(): ?array
    {
        if (!$this->share_location_allowed || !$this->latitude || !$this->longitude) {
            return null;
        }

        return [
            'lat'  => round($this->latitude, 2),
            'lng'  => round($this->longitude, 2),
            'city' => $this->city,
        ];
    }
}
