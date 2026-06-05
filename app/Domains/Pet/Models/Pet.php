<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pet extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'pets';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'owner_id', 'slug', 'name', 'species', 'breed', 'breed_en',
        'gender', 'birth_date', 'color', 'color_en', 'eye_color', 'eye_color_en',
        'weight', 'sterilized', 'microchip_id', 'nfc_id', 'photo_url',
        'story', 'story_en', 'traits', 'traits_en', 'allergies', 'allergies_en',
        'allergy_profiles', 'conditions', 'conditions_en', 'active_treatments',
        'active_treatments_en', 'current_medications', 'current_medications_en',
        'special_care', 'special_care_en', 'primary_vet_name', 'primary_vet_phone',
        'primary_vet_clinic', 'scanned_count', 'last_scan_location', 'public_profile_enabled',
        'is_lost', 'lost_since', 'lost_description', 'last_seen_location',
        'emergency_contact_override', 'lost_banner_enabled',
        'avatar_emoji', 'ring_color', 'cover_url',
    ];

    protected $casts = [
        'sterilized'             => 'boolean',
        'public_profile_enabled' => 'boolean',
        'weight'                 => 'float',
        'scanned_count'          => 'integer',
        'traits'                 => 'array',
        'traits_en'              => 'array',
        'allergies'              => 'array',
        'allergies_en'           => 'array',
        'allergy_profiles'       => 'array',
        'conditions'             => 'array',
        'conditions_en'          => 'array',
        'active_treatments'      => 'array',
        'active_treatments_en'   => 'array',
        'current_medications'    => 'array',
        'current_medications_en' => 'array',
        'last_scan_location'     => 'array',
        'is_lost'                => 'boolean',
        'lost_since'             => 'datetime',
        'last_seen_location'     => 'array',
        'lost_banner_enabled'    => 'boolean',
    ];

    protected $attributes = [
        'scanned_count'          => 0,
        'sterilized'             => false,
        'public_profile_enabled' => true,
        'is_lost'                => false,
        'lost_banner_enabled'    => true,
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function vaccines(): HasMany
    {
        return $this->hasMany(Vaccine::class, 'pet_id')->orderBy('date', 'desc');
    }

    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'pet_id')->orderBy('date', 'desc');
    }

    public function vetLinks(): HasMany
    {
        return $this->hasMany(VetLink::class, 'pet_id');
    }

    public function weightHistory(): HasMany
    {
        return $this->hasMany(WeightHistory::class, 'pet_id')->orderBy('recorded_at', 'desc');
    }

    public function scanEvents(): HasMany
    {
        return $this->hasMany(PetScanEvent::class, 'pet_id')->orderBy('scanned_at', 'desc');
    }
}
