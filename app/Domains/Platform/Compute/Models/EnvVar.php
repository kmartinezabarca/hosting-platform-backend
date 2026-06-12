<?php

namespace App\Domains\Platform\Compute\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variable de entorno de un ambiente. Contrato de solo escritura: el valor se
 * cifra en reposo y NUNCA se serializa — los endpoints devuelven solo el
 * nombre y una máscara. El agente de IA puede escribir valores pero jamás
 * leerlos (ver blueprint doc 03 §2).
 */
class EnvVar extends Model
{
    use HasFactory;

    protected $fillable = [
        'environment_id',
        'key',
        'value_encrypted',
        'is_secret',
        'source',
    ];

    protected $casts = [
        'value_encrypted' => 'encrypted',
        'is_secret'       => 'boolean',
    ];

    protected $hidden = [
        'value_encrypted',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
