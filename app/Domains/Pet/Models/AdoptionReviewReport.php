<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdoptionReviewReport extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'adoption_review_reports';
    protected $keyType = 'string';

    protected $fillable = [
        'review_id', 'reporter_owner_id', 'reason', 'details', 'ip_address', 'resolved',
    ];

    protected $casts = ['resolved' => 'boolean'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(AdoptionReview::class, 'review_id');
    }
}
