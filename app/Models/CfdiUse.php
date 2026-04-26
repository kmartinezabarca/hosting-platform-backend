<?php

namespace App\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Model;

class CfdiUse extends Model
{
    use HasUuidColumn;

    protected $table = 'cfdi_uses';

    protected $fillable = [
        'uuid',
        'code',
        'description',
        'applies_to_fisica',
        'applies_to_moral',
        'is_active',
    ];

    protected $casts = [
        'applies_to_fisica' => 'boolean',
        'applies_to_moral'  => 'boolean',
        'is_active'         => 'boolean',
    ];

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForFisica($query)
    {
        return $query->where('applies_to_fisica', true);
    }

    public function scopeForMoral($query)
    {
        return $query->where('applies_to_moral', true);
    }
}
