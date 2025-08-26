<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanPricing extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'plan_pricing';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'service_plan_id',
        'billing_cycle_id',
        'price',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
    ];

    /**
     * Get the service plan that owns the pricing.
     */
    public function servicePlan()
    {
        return $this->belongsTo(ServicePlan::class);
    }

    /**
     * Get the billing cycle that owns the pricing.
     */
    public function billingCycle()
    {
        return $this->belongsTo(BillingCycle::class);
    }

    /**
     * Calculate total price for the billing cycle period.
     */
    public function getTotalPriceForPeriod()
    {
        return $this->price * $this->billingCycle->months;
    }

    /**
     * Calculate savings compared to monthly billing.
     */
    public function getSavingsVsMonthly($monthlyPrice)
    {
        $totalMonthlyPrice = $monthlyPrice * $this->billingCycle->months;
        $totalPrice = $this->getTotalPriceForPeriod();
        
        return $totalMonthlyPrice - $totalPrice;
    }

    /**
     * Calculate savings percentage compared to monthly billing.
     */
    public function getSavingsPercentageVsMonthly($monthlyPrice)
    {
        $totalMonthlyPrice = $monthlyPrice * $this->billingCycle->months;
        $savings = $this->getSavingsVsMonthly($monthlyPrice);
        
        return $totalMonthlyPrice > 0 ? ($savings / $totalMonthlyPrice) * 100 : 0;
    }
}

