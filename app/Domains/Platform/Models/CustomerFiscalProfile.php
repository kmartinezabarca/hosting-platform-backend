<?php

namespace App\Domains\Platform\Models;
use App\Models\User;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerFiscalProfile extends Model
{
    use HasFactory, SoftDeletes, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'user_id',
        'alias',
        'rfc',
        'business_name',
        'postal_code',
        'fiscal_regime_code',
        'cfdi_use_code',
        'constancia_path',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fiscalRegimeInfo()
    {
        return $this->belongsTo(FiscalRegime::class, 'fiscal_regime_code', 'code');
    }

    public function cfdiUseInfo()
    {
        return $this->belongsTo(CfdiUse::class, 'cfdi_use_code', 'code');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Marca este perfil como predeterminado y quita el flag a los demás del mismo usuario.
     */
    public function setAsDefault(): void
    {
        // Quitar default a todos los perfiles del usuario
        static::where('user_id', $this->user_id)
              ->where('id', '!=', $this->id)
              ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    /**
     * Determina si el RFC corresponde a persona moral
     * (12 caracteres = moral, 13 = física).
     */
    public function isPersonaMoral(): bool
    {
        return strlen(str_replace(' ', '', $this->rfc)) === 12;
    }

    public function isPersonaFisica(): bool
    {
        return !$this->isPersonaMoral();
    }

    /**
     * Retorna los datos en formato listo para poblar un Invoice (CFDI).
     */
    public function toInvoiceData(): array
    {
        return [
            'rfc'          => $this->rfc,
            'name'         => $this->business_name,
            'zip'          => $this->postal_code,
            'regimen'      => $this->fiscal_regime_code,
            'cfdi_use_code' => $this->cfdi_use_code,
        ];
    }
}
