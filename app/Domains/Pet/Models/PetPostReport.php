<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetPostReport extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'pet_post_reports';
    protected $keyType = 'string';

    protected $fillable = ['post_id', 'reason', 'details', 'ip_address', 'resolved'];

    protected $casts = ['resolved' => 'boolean'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(PetPost::class, 'post_id');
    }
}
