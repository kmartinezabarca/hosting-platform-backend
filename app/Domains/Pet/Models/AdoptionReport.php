<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdoptionReport extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'adoption_reports';
    protected $keyType = 'string';

    protected $fillable = [
        'listing_id', 'reason', 'details', 'ip_address', 'resolved',
    ];

    protected $casts = [
        'resolved' => 'boolean',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(AdoptionListing::class, 'listing_id');
    }
}
