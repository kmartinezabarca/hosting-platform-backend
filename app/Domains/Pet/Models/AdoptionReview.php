<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calificación de una adopción concreta. Bidireccional:
 *  - role=adopter  → el evaluado es el adoptante (lo califica el rescatista).
 *  - role=rescuer  → el evaluado es el rescatista (lo califica el adoptante).
 */
class AdoptionReview extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'adoption_reviews';
    protected $keyType = 'string';

    protected $fillable = [
        'listing_id', 'reviewer_owner_id', 'reviewee_owner_id', 'role',
        'rating', 'score_responsibility', 'score_communication', 'score_conditions',
        'comment', 'moderation_status',
    ];

    protected $casts = [
        'rating'               => 'integer',
        'score_responsibility' => 'integer',
        'score_communication'  => 'integer',
        'score_conditions'     => 'integer',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(AdoptionListing::class, 'listing_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'reviewer_owner_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(AdoptionReviewReport::class, 'review_id');
    }
}
