<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceInvoice extends Model
{
    use HasFactory;

    // ── Estados CFDI ────────────────────────────────────────────────────────
    /** El cliente nunca proporcionó datos — esperando 72 h para timbrar como PG */
    public const CFDI_SCHEDULED     = 'scheduled';
    /** Datos fiscales del cliente recibidos, pendiente de timbrado */
    public const CFDI_PENDING_STAMP = 'pending_stamp';
    /** CFDI timbrado exitosamente ante el SAT */
    public const CFDI_STAMPED       = 'stamped';
    /** Error al timbrar */
    public const CFDI_FAILED        = 'failed';
    /** CFDI cancelado ante el SAT */
    public const CFDI_CANCELLED     = 'cancelled';

    // ── Defaults para Público en General (CFDI 4.0) ──────────────────────────
    public const PUBLICO_GENERAL_RFC     = 'XAXX010101000';
    public const PUBLICO_GENERAL_NAME    = 'PUBLICO EN GENERAL';
    public const PUBLICO_GENERAL_ZIP     = '99999';   // CP genérico SAT
    public const PUBLICO_GENERAL_REGIMEN = '616';     // Sin obligaciones fiscales
    public const PUBLICO_GENERAL_USO     = 'S01';     // Sin efectos fiscales

    protected $fillable = [
        'service_id',
        'rfc',
        'name',
        'zip',
        'regimen',
        'uso_cfdi',
        'constancia',
        'cfdi_status',
        'stamp_scheduled_at',
        'is_publico_general',
        'cfdi_uuid',
        'cfdi_xml',
        'cfdi_pdf_path',
        'cfdi_error',
        'stamped_at',
    ];

    protected $casts = [
        'is_publico_general'  => 'boolean',
        'stamp_scheduled_at'  => 'datetime',
        'stamped_at'          => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /** Registros pendientes de timbrado automático cuyo plazo ya venció */
    public function scopeDueForStamping($query)
    {
        return $query->where('cfdi_status', self::CFDI_SCHEDULED)
                     ->where('stamp_scheduled_at', '<=', now());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function isStamped(): bool
    {
        return $this->cfdi_status === self::CFDI_STAMPED;
    }

    public function isScheduledForPublicoGeneral(): bool
    {
        return $this->cfdi_status === self::CFDI_SCHEDULED && $this->is_publico_general;
    }

    /**
     * Devuelve los atributos para crear un ServiceInvoice de Público en General.
     * stamp_scheduled_at = ahora + 72 horas (plazo para que el cliente proporcione RFC real).
     */
    public static function publicoGeneralDefaults(int $serviceId): array
    {
        return [
            'service_id'          => $serviceId,
            'rfc'                 => self::PUBLICO_GENERAL_RFC,
            'name'                => self::PUBLICO_GENERAL_NAME,
            'zip'                 => self::PUBLICO_GENERAL_ZIP,
            'regimen'             => self::PUBLICO_GENERAL_REGIMEN,
            'uso_cfdi'            => self::PUBLICO_GENERAL_USO,
            'cfdi_status'         => self::CFDI_SCHEDULED,
            'stamp_scheduled_at'  => now()->addHours(72),
            'is_publico_general'  => true,
        ];
    }
}
