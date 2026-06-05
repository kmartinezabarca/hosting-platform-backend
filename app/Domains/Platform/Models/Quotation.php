<?php

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Quotation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'title', 'client_name', 'client_email', 'client_company', 'client_phone',
        'items', 'subtotal', 'discount_percent', 'discount_amount',
        'tax_percent', 'tax_amount', 'total', 'currency',
        'notes', 'terms', 'status',
        'public_token', 'public_url', 'expires_at', 'sent_at',
        'accepted_at', 'rejected_at', 'reopened_at', 'reopened_reason',
        'revision_number', 'parent_uuid',
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
        'accepted_at'      => 'datetime',
        'rejected_at'      => 'datetime',
        'reopened_at'      => 'datetime',
        'status'           => QuotationStatus::class,
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn(self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function activities(): HasMany
    {
        return $this->hasMany(QuotationActivity::class)->orderByDesc('created_at');
    }

    // ── Business rule methods ─────────────────────────────────────────────────

    public function canBeModified(): bool
    {
        return $this->status->isModifiable();
    }

    public function canBeDeleted(): bool
    {
        return $this->status !== QuotationStatus::Accepted;
    }

    public function canBeAccepted(): bool
    {
        return $this->status->canTransitionTo(QuotationStatus::Accepted);
    }

    public function canBeRejected(): bool
    {
        return $this->status->canTransitionTo(QuotationStatus::Rejected);
    }

    public function canBeReopened(): bool
    {
        return $this->status->canTransitionTo(QuotationStatus::PendingRevision);
    }

    public function canBeSent(): bool
    {
        return $this->status->canTransitionTo(QuotationStatus::Sent);
    }

    // ── Calculation ───────────────────────────────────────────────────────────

    public function recalculate(): void
    {
        $items = collect($this->items ?? []);

        $items = $items->map(function (array $item) {
            $item['subtotal'] = round(
                (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0),
                2
            );
            return $item;
        });

        $subtotal       = round($items->sum('subtotal'), 2);
        $discountPct    = (float) ($this->discount_percent ?? 0);
        $taxPct         = (float) ($this->tax_percent ?? 16);
        $discountAmount = round($subtotal * $discountPct / 100, 2);
        $taxBase        = $subtotal - $discountAmount;
        $taxAmount      = round($taxBase * $taxPct / 100, 2);

        $this->items           = $items->values()->all();
        $this->subtotal        = $subtotal;
        $this->discount_amount = $discountAmount;
        $this->tax_amount      = $taxAmount;
        $this->total           = round($taxBase + $taxAmount, 2);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeSearch($query, ?string $term)
    {
        if (!$term) return $query;

        return $query->where(fn($q) => $q
            ->where('title',        'like', "%{$term}%")
            ->orWhere('client_name',  'like', "%{$term}%")
            ->orWhere('client_email', 'like', "%{$term}%"));
    }

    // ── State helpers ─────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPubliclyAccessible(): bool
    {
        return $this->public_token !== null && !$this->isExpired();
    }
}
