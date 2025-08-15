<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'invoice_id',
        'payment_method_id',
        'transaction_id',
        'provider_transaction_id',
        'type',
        'status',
        'amount',
        'currency',
        'fee_amount',
        'provider',
        'provider_data',
        'description',
        'failure_reason',
        'processed_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'provider_data' => 'array',
        'processed_at' => 'datetime'
    ];

    /**
     * Get the user that owns the transaction
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the invoice associated with the transaction
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the payment method used for the transaction
     */
    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Get the net amount (amount - fees)
     */
    public function getNetAmountAttribute()
    {
        return $this->amount - $this->fee_amount;
    }

    /**
     * Get formatted amount with currency
     */
    public function getFormattedAmountAttribute()
    {
        $symbol = $this->getCurrencySymbol();
        return $symbol . number_format($this->amount, 2);
    }

    /**
     * Get formatted fee amount with currency
     */
    public function getFormattedFeeAmountAttribute()
    {
        $symbol = $this->getCurrencySymbol();
        return $symbol . number_format($this->fee_amount, 2);
    }

    /**
     * Get currency symbol
     */
    private function getCurrencySymbol()
    {
        $symbols = [
            'USD' => '$',
            'MXN' => '$',
            'EUR' => '€',
            'GBP' => '£'
        ];

        return $symbols[$this->currency] ?? $this->currency;
    }

    /**
     * Check if transaction is successful
     */
    public function getIsSuccessfulAttribute()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if transaction is pending
     */
    public function getIsPendingAttribute()
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Check if transaction failed
     */
    public function getIsFailedAttribute()
    {
        return in_array($this->status, ['failed', 'cancelled']);
    }

    /**
     * Scope for successful transactions
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for pending transactions
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    /**
     * Scope for failed transactions
     */
    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['failed', 'cancelled']);
    }

    /**
     * Scope for payments (not refunds)
     */
    public function scopePayments($query)
    {
        return $query->where('type', 'payment');
    }

    /**
     * Scope for refunds
     */
    public function scopeRefunds($query)
    {
        return $query->where('type', 'refund');
    }
}

