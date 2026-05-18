<?php

namespace App\Models\RokePet;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vaccine extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'vaccines';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'pet_id', 'name', 'name_en', 'date', 'next_due',
        'applied_by', 'batch_number', 'status',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class, 'pet_id');
    }
}
