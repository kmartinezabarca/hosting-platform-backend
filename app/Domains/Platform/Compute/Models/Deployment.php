<?php

namespace App\Domains\Platform\Compute\Models;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Models\User;
use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    use HasFactory, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'resource_id',
        'trigger',
        'status',
        'provider_ref',
        'commit_sha',
        'commit_message',
        'branch',
        'pr_number',
        'initiated_by_user_id',
        'initiated_by_ai',
        'build_seconds',
        'error_summary',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'trigger'         => DeploymentTrigger::class,
        'status'          => DeploymentStatus::class,
        'initiated_by_ai' => 'boolean',
        'started_at'      => 'datetime',
        'finished_at'     => 'datetime',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DeploymentLog::class)->orderBy('seq');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
