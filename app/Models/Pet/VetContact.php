<?php

namespace App\Models\Pet;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VetContact extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'vet_contacts';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'owner_id', 'name', 'clinic', 'phone', 'vet_license', 'specialty',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }
}
