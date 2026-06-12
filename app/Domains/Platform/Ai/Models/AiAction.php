<?php

namespace App\Domains\Platform\Ai\Models;

use App\Models\User;
use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Efecto secundario que el agente solicita (deploy, env var, rollback…).
 *
 * Toda herramienta de escritura (WriteTool) crea una AiAction en estado
 * `proposed`; solo se ejecuta cuando el usuario la confirma. El ciclo
 * proposed → confirmed/rejected alimenta la métrica de confirm/reject ratio
 * que gatilla promociones del trust ladder (blueprint doc 07 §1).
 *
 * `arguments` se cifra en reposo: puede traer el valor de una variable secreta.
 */
class AiAction extends Model
{
    use HasUuidColumn;

    protected $fillable = [
        'uuid',
        'conversation_id',
        'message_id',
        'user_id',
        'tool',
        'arguments',
        'summary',
        'risk',
        'status',
        'confirmed_at',
        'executed_at',
        'result',
    ];

    protected $casts = [
        'arguments'    => 'encrypted:array',
        'result'       => 'array',
        'confirmed_at' => 'datetime',
        'executed_at'  => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'proposed';
    }
}
