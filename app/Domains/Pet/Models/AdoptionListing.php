<?php

namespace App\Domains\Pet\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdoptionListing extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';

    protected $table = 'adoption_listings';

    protected $keyType = 'string';

    protected $fillable = [
        'owner_id', 'adopted_by_owner_id', 'adopted_at', 'slug', 'name', 'species',
        'breed', 'gender', 'birth_date', 'age_label',
        'size', 'color', 'description', 'photos', 'photo_url', 'city', 'state',
        'lat', 'lng', 'sterilized', 'vaccinated', 'dewormed', 'good_with_kids',
        'good_with_pets', 'special_needs', 'requirements', 'status', 'is_published',
        'moderation_status', 'views_count', 'requests_count',
    ];

    protected $casts = [
        'photos' => 'array',
        'birth_date' => 'date',
        'lat' => 'float',
        'lng' => 'float',
        'adopted_at' => 'datetime',
        'sterilized' => 'boolean',
        'vaccinated' => 'boolean',
        'dewormed' => 'boolean',
        'good_with_kids' => 'boolean',
        'good_with_pets' => 'boolean',
        'special_needs' => 'boolean',
        'is_published' => 'boolean',
        'views_count' => 'integer',
        'requests_count' => 'integer',
    ];

    protected $attributes = [
        'status' => 'available',
        'moderation_status' => 'active',
        'is_published' => true,
        'views_count' => 0,
        'requests_count' => 0,
        'sterilized' => false,
        'vaccinated' => false,
        'dewormed' => false,
        'special_needs' => false,
    ];

    public function getDisplayAgeLabelAttribute(): ?string
    {
        if (! $this->birth_date) {
            return $this->age_label;
        }

        $birthDate = CarbonImmutable::parse($this->birth_date)->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();
        if ($birthDate->greaterThan($today)) {
            return $this->age_label;
        }

        $months = (int) floor($birthDate->diffInMonths($today));
        if ($months < 1) {
            return 'Menos de 1 mes';
        }
        if ($months < 12) {
            return $months.' '.($months === 1 ? 'mes' : 'meses');
        }

        $years = intdiv($months, 12);
        $rest = $months % 12;
        $label = $years.' '.($years === 1 ? 'año' : 'años');
        if ($rest > 0) {
            $label .= ' '.$rest.' '.($rest === 1 ? 'mes' : 'meses');
        }

        return $label;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function adopter(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'adopted_by_owner_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AdoptionRequest::class, 'listing_id')->orderBy('created_at', 'desc');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(AdoptionReport::class, 'listing_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AdoptionReview::class, 'listing_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(AdoptionFollowup::class, 'listing_id');
    }
}
