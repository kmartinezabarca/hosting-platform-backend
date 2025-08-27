<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Invoice extends Model
{
    use HasFactory;

    /** Estados válidos (útil para reglas de validación y enums) */
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SENT      = 'sent';
    public const STATUS_PROCESS   = 'processing';
    public const STATUS_PAID      = 'paid';
    public const STATUS_OVERDUE   = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED  = 'refunded';

    protected $fillable = [
        'uuid',
        'user_id',
        'service_id',
        'invoice_number',
        'provider_invoice_id',
        'status',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
        'currency',
        'due_date',
        'paid_at',
        'payment_method',
        'payment_reference',
        'pdf_path',
        'xml_path',
        'notes',
    ];

    protected $casts = [
        'subtotal'  => 'decimal:2',
        'tax_rate'  => 'decimal:2',
        'tax_amount'=> 'decimal:2',
        'total'     => 'decimal:2',
        'due_date'  => 'date',
        'paid_at'   => 'datetime',
    ];

    /*----------------------------------------
    | Boot - set UUID & sequential folio
    |---------------------------------------*/
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $invoice) {
            $invoice->uuid ??= (string) Str::uuid();

            // Si no viene folio, generamos uno secuencial por mes (INV-202508-0001)
            if (empty($invoice->invoice_number)) {
                $prefix = 'INV-'.now()->format('Ym');
                $last = self::where('invoice_number', 'like', "{$prefix}-%")
                    ->orderByDesc('invoice_number')->value('invoice_number');

                $seq = 1;
                if ($last && preg_match('/-(\d+)$/', $last, $m)) {
                    $seq = (int) $m[1] + 1;
                }
                $invoice->invoice_number = sprintf('%s-%04d', $prefix, $seq);
            }
        });
    }

    /*----------------------------------------
    | Relationships
    |---------------------------------------*/
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /*----------------------------------------
    | Accessors / Helpers
    |---------------------------------------*/
    /** Color útil para Badge/Tailwind */
    public function statusColor(): Attribute
    {
        return Attribute::get(fn () => match ($this->status) {
            self::STATUS_PAID      => 'success',
            self::STATUS_SENT,
            self::STATUS_PROCESS   => 'info',
            self::STATUS_OVERDUE   => 'error',
            self::STATUS_CANCELLED => 'muted',
            self::STATUS_REFUNDED  => 'warning',
            default                => 'muted',
        });
    }

    /** Texto legible (es-MX) */
    public function statusText(): Attribute
    {
        return Attribute::get(fn () => match ($this->status) {
            self::STATUS_DRAFT     => 'Borrador',
            self::STATUS_SENT      => 'Enviada',
            self::STATUS_PROCESS   => 'Procesando',
            self::STATUS_PAID      => 'Pagada',
            self::STATUS_OVERDUE   => 'Vencida',
            self::STATUS_CANCELLED => 'Cancelada',
            self::STATUS_REFUNDED  => 'Reembolsada',
            default                => 'Desconocido',
        });
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date instanceof Carbon
            && $this->due_date->isPast();
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function canBePaid(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_OVERDUE, self::STATUS_PROCESS], true);
    }
}
