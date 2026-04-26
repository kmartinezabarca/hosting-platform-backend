<?php

namespace App\Models;

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
        'razon_social',
        'codigo_postal',
        'regimen_fiscal',
        'uso_cfdi',
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

    // Relación con catálogo de regímenes (por código)
    public function regimenFiscalInfo()
    {
        return $this->belongsTo(FiscalRegime::class, 'regimen_fiscal', 'code');
    }

    // Relación con catálogo de usos CFDI (por código)
    public function usoCfdiInfo()
    {
        return $this->belongsTo(CfdiUse::class, 'uso_cfdi', 'code');
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
     * Retorna los datos en formato listo para poblar un ServiceInvoice.
     */
    public function toServiceInvoiceData(): array
    {
        return [
            'rfc'       => $this->rfc,
            'name'      => $this->razon_social,
            'zip'       => $this->codigo_postal,
            'regimen'   => $this->regimen_fiscal,
            'uso_cfdi'  => $this->uso_cfdi,
        ];
    }
}
