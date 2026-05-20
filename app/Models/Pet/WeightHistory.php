<?php

namespace App\Models\Pet;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeightHistory extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'weight_history';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['pet_id', 'weight', 'recorded_at', 'notes'];

    protected $casts = [
        'weight'      => 'float',
        'recorded_at' => 'date',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class, 'pet_id');
    }
}
