<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'plan_id',
        'server_node_id',
        'name',
        'status',
        'external_id',
        'connection_details',
        'configuration',
        'next_due_date',
        'billing_cycle',
        'price',
        'setup_fee',
        'notes',
        'terminated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'connection_details' => 'array',
        'configuration' => 'array',
        'next_due_date' => 'date',
        'price' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'terminated_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * Get the user that owns the service.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the product that this service is based on.
     */
    public function plan()
    {
        return $this->belongsTo(ServicePlan::class);
    }

    /**
     * Get the server node where this service is hosted.
     */
    public function serverNode()
    {
        return $this->belongsTo(ServerNode::class);
    }

    /**
     * Get the invoice items for this service.
     */
    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function invoice()
    {
        return $this->hasOne(ServiceInvoice::class);
    }

    /**
     * Get the tickets related to this service.
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Scope a query to only include active services.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include suspended services.
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    /**
     * Scope a query to only include pending services.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to filter by service type.
     */
    public function scopeServiceType($query, $type)
    {
        return $query->whereHas('product', function ($q) use ($type) {
            $q->where('service_type', $type);
        });
    }

    /**
     * Scope a query to include overdue services.
     */
    public function scopeOverdue($query)
    {
        return $query->where('next_due_date', '<', now())
            ->whereIn('status', ['active', 'suspended']);
    }

    /**
     * Check if the service is active.
     */
    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Check if the service is suspended.
     */
    public function isSuspended()
    {
        return $this->status === 'suspended';
    }

    /**
     * Check if the service is pending.
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the service is terminated.
     */
    public function isTerminated()
    {
        return $this->status === 'terminated';
    }

    /**
     * Check if the service is overdue.
     */
    public function isOverdue()
    {
        return $this->next_due_date < now() && in_array($this->status, ['active', 'suspended']);
    }

    /**
     * Get the service type from the product.
     */
    public function getServiceType()
    {
        return $this->product->service_type;
    }

    /**
     * Check if this is a game server service.
     */
    public function isGameServer()
    {
        return $this->product->isGameServer();
    }

    /**
     * Check if this is a hosting service.
     */
    public function isHosting()
    {
        return $this->product->isHosting();
    }

    /**
     * Check if this is a domain service.
     */
    public function isDomain()
    {
        return $this->product->isDomain();
    }

    /**
     * Get the next billing date based on the billing cycle.
     */
    public function getNextBillingDate()
    {
        $currentDate = $this->next_due_date;

        return match ($this->billing_cycle) {
            'monthly' => $currentDate->addMonth(),
            'quarterly' => $currentDate->addMonths(3),
            'semi_annually' => $currentDate->addMonths(6),
            'annually' => $currentDate->addYear(),
            default => $currentDate->addMonth(),
        };
    }

    /**
     * Calculate the total cost including setup fee.
     */
    public function getTotalCost()
    {
        return $this->price + $this->setup_fee;
    }

    /**
     * Get formatted connection details for display.
     */
    public function getFormattedConnectionDetails()
    {
        $details = $this->connection_details ?? [];
        $formatted = [];

        if (isset($details['ip_address'])) {
            $formatted['IP Address'] = $details['ip_address'];
        }

        if (isset($details['server_ip'])) {
            $formatted['Server IP'] = $details['server_ip'];
        }

        if (isset($details['server_port'])) {
            $formatted['Server Port'] = $details['server_port'];
        }

        if (isset($details['ssh_port'])) {
            $formatted['SSH Port'] = $details['ssh_port'];
        }

        if (isset($details['panel_url'])) {
            $formatted['Panel URL'] = $details['panel_url'];
        }

        if (isset($details['domain_name'])) {
            $formatted['Domain'] = $details['domain_name'];
        }

        return $formatted;
    }

    /**
     * Get status badge color for UI.
     */
    public function getStatusColor()
    {
        return match ($this->status) {
            'active' => 'green',
            'pending' => 'yellow',
            'suspended' => 'orange',
            'terminated' => 'red',
            'failed' => 'red',
            default => 'gray',
        };
    }

    /**
     * Get human-readable status.
     */
    public function getStatusLabel()
    {
        return match ($this->status) {
            'active' => 'Active',
            'pending' => 'Pending',
            'suspended' => 'Suspended',
            'terminated' => 'Terminated',
            'failed' => 'Failed',
            default => 'Unknown',
        };
    }
}
