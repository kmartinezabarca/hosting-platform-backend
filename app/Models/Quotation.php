<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'title',
        'client_name',
        'client_email',
        'client_company',
        'client_phone',
        'items',
        'subtotal',
        'discount_percent',
        'discount_amount',
        'tax_percent',
        'tax_amount',
        'total',
        'currency',
        'notes',
        'terms',
        'status',
        'public_token',
        'public_url',
        'expires_at',
        'sent_at',
    ];

    protected $casts = [
        'items'            => 'array',
        'subtotal'         => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'tax_percent'      => 'decimal:2',
        'tax_amount'       => 'decimal:2',
        'total'            => 'decimal:2',
        'expires_at'       => 'datetime',
        'sent_at'          => 'datetime',
    ];

    // ── Boot ─────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Route key ────────────────────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Helpers de cálculo ───────────────────────────────────────────────────

    /**
     * Recalcula todos los importes a partir del array de items y porcentajes.
     * Mutaciona los atributos del modelo pero NO llama a save().
     */
    public function recalculate(): void
    {
        $items = collect($this->items ?? []);

        // Calcular subtotal de cada item y el subtotal global
        $items = $items->map(function (array $item) {
            $qty       = (float) ($item['quantity']   ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $item['subtotal'] = round($qty * $unitPrice, 2);
            return $item;
        });

        $subtotal       = round($items->sum('subtotal'), 2);
        $discountPct    = (float) ($this->discount_percent ?? 0);
        $taxPct         = (float) ($this->tax_percent      ?? 16);

        $discountAmount = round($subtotal * $discountPct / 100, 2);
        $taxBase        = $subtotal - $discountAmount;
        $taxAmount      = round($taxBase * $taxPct / 100, 2);
        $total          = round($taxBase + $taxAmount, 2);

        $this->items           = $items->values()->all();
        $this->subtotal        = $subtotal;
        $this->discount_amount = $discountAmount;
        $this->tax_amount      = $taxAmount;
        $this->total           = $total;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeSearch($query, ?string $term)
    {
        if (!$term) return $query;

        return $query->where(function ($q) use ($term) {
            $q->where('title',        'like', "%{$term}%")
              ->orWhere('client_name',  'like', "%{$term}%")
              ->orWhere('client_email', 'like', "%{$term}%");
        });
    }

    // ── Helpers de estado ────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPubliclyAccessible(): bool
    {
        return $this->public_token !== null && !$this->isExpired();
    }
}
