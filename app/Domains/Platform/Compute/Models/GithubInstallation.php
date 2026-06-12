<?php

namespace App\Domains\Platform\Compute\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Instalación de la GitHub App sobre una cuenta/organización, ligada a un
 * equipo. Los tokens de instalación se piden on-demand (JWT de la App) y se
 * cachean en Redis — nunca se persisten aquí.
 */
class GithubInstallation extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'installation_id',
        'account_login',
        'suspended_at',
    ];

    protected $casts = [
        'installation_id' => 'integer',
        'suspended_at'    => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
