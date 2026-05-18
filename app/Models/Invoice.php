<?php

namespace App\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Factura electrónica CFDI 4.0 ante el SAT.
 * Tabla: invoices (antes service_invoices).
 */
class Invoice extends Model
{
    use HasFactory, HasUuidColumn;

    protected $table = 'invoices';

    // ── Estados CFDI ────────────────────────────────────────────────────────
    public const CFDI_SCHEDULED     = 'scheduled';
    public const CFDI_PENDING_STAMP = 'pending_stamp';
    public const CFDI_STAMPED       = 'stamped';
    public const CFDI_FAILED        = 'failed';
    public const CFDI_CANCELLED     = 'cancelled';

    // ── Defaults Público en General (CFDI 4.0) ───────────────────────────────
    public const PUBLICO_GENERAL_RFC     = 'XAXX010101000';
    public const PUBLICO_GENERAL_NAME    = 'PUBLICO EN GENERAL';
    public const PUBLICO_GENERAL_ZIP     = '99999';
    public const PUBLICO_GENERAL_REGIMEN = '616';
    public const PUBLICO_GENERAL_USO     = 'S01';

    protected $fillable = [
        'uuid',
        'facturama_id',
        'folio',
        'invoice_id',
        'service_id',
        'rfc',
        'name',
        'zip',
        'regimen',
        'cfdi_use_code',
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
        'is_publico_general' => 'boolean',
        'stamp_scheduled_at' => 'datetime',
        'stamped_at'         => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    /** El comprobante de pago interno al que está vinculada esta factura CFDI. */
    public function receipt()
    {
        return $this->belongsTo(Receipt::class, 'invoice_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

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

    public static function publicoGeneralDefaults(int $serviceId): array
    {
        return [
            'service_id'         => $serviceId,
            'rfc'                => self::PUBLICO_GENERAL_RFC,
            'name'               => self::PUBLICO_GENERAL_NAME,
            'zip'                => self::PUBLICO_GENERAL_ZIP,
            'regimen'            => self::PUBLICO_GENERAL_REGIMEN,
            'uso_cfdi'           => self::PUBLICO_GENERAL_USO,
            'cfdi_status'        => self::CFDI_SCHEDULED,
            'stamp_scheduled_at' => now()->addHours(72),
            'is_publico_general' => true,
        ];
    }
}
