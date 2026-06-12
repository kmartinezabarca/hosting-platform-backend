<?php

namespace App\Domains\Platform\Compute\Models;

use App\Domains\Platform\Compute\Enums\PlanTier;
use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Models\User;
use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant del plano de cómputo. Todo Project/Resource pertenece a un Team;
 * la autorización siempre se resuelve por membresía (ver roleFor()).
 */
class Team extends Model
{
    use HasFactory, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'owner_user_id',
        'plan_tier',
        'is_personal',
    ];

    protected $casts = [
        'plan_tier'   => PlanTier::class,
        'is_personal' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** Recursos activos del equipo (vía environment → project). Excluye soft-deleted. */
    public function activeResourceCount(): int
    {
        return Resource::whereHas(
            'environment.project',
            fn ($q) => $q->where('team_id', $this->id),
        )->count();
    }

    public function githubInstallations(): HasMany
    {
        return $this->hasMany(GithubInstallation::class);
    }

    /**
     * Rol del usuario en este equipo, o null si no es miembro.
     * El owner siempre resuelve a Owner aunque falte la fila pivot (defensa
     * contra backfills parciales).
     */
    public function roleFor(User $user): ?TeamRole
    {
        if ((int) $this->owner_user_id === (int) $user->id) {
            return TeamRole::Owner;
        }

        $role = $this->members()
            ->where('users.id', $user->id)
            ->first()
            ?->pivot
            ->role;

        return $role !== null ? TeamRole::from($role) : null;
    }

    public function hasMember(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    /**
     * Equipos donde el usuario es miembro (para listados). Agrupado en un
     * closure para que el orWhere no se escape si el caller encadena más
     * condiciones después del scope.
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->whereHas('members', fn ($m) => $m->where('users.id', $user->id))
                ->orWhere('owner_user_id', $user->id);
        });
    }
}
