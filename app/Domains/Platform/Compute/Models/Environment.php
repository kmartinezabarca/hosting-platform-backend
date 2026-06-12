<?php

namespace App\Domains\Platform\Compute\Models;

use App\Domains\Platform\Compute\Enums\EnvironmentType;
use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Environment extends Model
{
    use HasFactory, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'project_id',
        'name',
        'slug',
        'type',
        'branch',
        'auto_deploy',
        'ephemeral',
        'expires_at',
    ];

    protected $casts = [
        'type'        => EnvironmentType::class,
        'auto_deploy' => 'boolean',
        'ephemeral'   => 'boolean',
        'expires_at'  => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function envVars(): HasMany
    {
        return $this->hasMany(EnvVar::class);
    }
}
