<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecord extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'medical_records';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'pet_id', 'date', 'follow_up_date', 'type',
        'description', 'description_en', 'vet', 'vet_license', 'clinic', 'notes', 'photo_url',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class, 'pet_id');
    }
}
