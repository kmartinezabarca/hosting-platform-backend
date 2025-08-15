<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'provider',
        'provider_id',
        'name',
        'details',
        'is_default',
        'is_active',
        'expires_at'
    ];

    protected $casts = [
        'details' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'expires_at' => 'datetime'
    ];

    protected $hidden = [
        'details' // Hide sensitive payment details
    ];

    /**
     * Get the user that owns the payment method
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get transactions for this payment method
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get masked details for display
     */
    public function getMaskedDetailsAttribute()
    {
        $details = $this->details;
        
        if ($this->type === 'card' && isset($details['last_four'])) {
            return [
                'type' => 'card',
                'brand' => $details['brand'] ?? 'Unknown',
                'last_four' => $details['last_four'],
                'exp_month' => $details['exp_month'] ?? null,
                'exp_year' => $details['exp_year'] ?? null
            ];
        }
        
        if ($this->type === 'bank_account' && isset($details['last_four'])) {
            return [
                'type' => 'bank_account',
                'bank_name' => $details['bank_name'] ?? 'Unknown',
                'last_four' => $details['last_four'],
                'account_type' => $details['account_type'] ?? 'checking'
            ];
        }
        
        if ($this->type === 'paypal' && isset($details['email'])) {
            $email = $details['email'];
            $masked = substr($email, 0, 2) . str_repeat('*', strlen($email) - 6) . substr($email, -4);
            return [
                'type' => 'paypal',
                'email' => $masked
            ];
        }
        
        return ['type' => $this->type];
    }

    /**
     * Check if payment method is expired
     */
    public function getIsExpiredAttribute()
    {
        if (!$this->expires_at) {
            return false;
        }
        
        return $this->expires_at->isPast();
    }

    /**
     * Scope for active payment methods
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for default payment method
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}

