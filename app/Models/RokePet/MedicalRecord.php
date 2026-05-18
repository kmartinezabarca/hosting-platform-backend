<?php

namespace App\Models\RokePet;

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
        'description', 'description_en', 'vet', 'clinic', 'notes',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class, 'pet_id');
    }
}
