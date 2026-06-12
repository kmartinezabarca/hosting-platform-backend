<?php

namespace App\Domains\Platform\Compute\Models;

use App\Models\User;
use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'team_id',
        'name',
        'slug',
        'github_installation_id',
        'repo_full_name',
        'default_branch',
        'detected_stack',
        'provider_meta',
        'archived_at',
    ];

    protected $casts = [
        'detected_stack' => 'array',
        // Refs de proveedor a nivel proyecto (uuid del proyecto Coolify).
        // Interno: NUNCA incluir en transformers de API.
        'provider_meta'  => 'array',
        'archived_at'    => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    public function githubInstallation(): BelongsTo
    {
        return $this->belongsTo(GithubInstallation::class);
    }

    /** Proyectos visibles para el usuario (vía membresía de equipo). */
    public function scopeForUser($query, User $user)
    {
        return $query->whereHas(
            'team',
            fn ($q) => $q->where('owner_user_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id))
        );
    }
}
