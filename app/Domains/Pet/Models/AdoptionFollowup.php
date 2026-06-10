<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seguimiento de una adopción: el adoptante sube fotos de cómo está la
 * mascota. Es la prueba pública de cuidado responsable y la señal central
 * del ranking de adoptantes.
 */
class AdoptionFollowup extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'adoption_followups';
    protected $keyType = 'string';

    protected $fillable = [
        'listing_id', 'adopter_owner_id', 'requested_by_owner_id', 'status',
        'photos', 'note', 'reaction', 'reaction_note', 'reacted_at',
        'due_at', 'requested_at', 'submitted_at',
    ];

    protected $casts = [
        'photos'       => 'array',
        'due_at'       => 'datetime',
        'requested_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reacted_at'   => 'datetime',
    ];

    protected $attributes = [
        'status' => 'requested',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(AdoptionListing::class, 'listing_id');
    }
}
