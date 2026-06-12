<?php

namespace App\Domains\Platform\Compute\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chunk de log de un deployment (build/deploy/runtime), ordenado por seq.
 * Se escribe desde el driver (polling o webhook) y se re-emite por Reverb
 * en private-deployment.{uuid}.
 */
class DeploymentLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'deployment_id',
        'seq',
        'stream',
        'chunk',
    ];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
