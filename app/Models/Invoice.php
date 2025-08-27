<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'invoice_number',
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
        'notes'
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($invoice) {
            if (empty($invoice->uuid)) {
                $invoice->uuid = Str::uuid();
            }
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = 'INV-' . date('Y') . '-' . str_pad(static::count() + 1, 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'paid' => 'success',
            'sent' => 'info',
            'overdue' => 'error',
            'cancelled' => 'muted',
            'refunded' => 'warning',
            default => 'muted'
        };
    }

    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            'draft' => 'Borrador',
            'sent' => 'Enviada',
            'paid' => 'Pagada',
            'overdue' => 'Vencida',
            'cancelled' => 'Cancelada',
            'refunded' => 'Reembolsada',
            default => 'Desconocido'
        };
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'paid' && $this->due_date->isPast();
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function canBePaid(): bool
    {
        return in_array($this->status, ['sent', 'overdue']);
    }
}
