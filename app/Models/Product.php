<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'description',
        'service_type',
        'game_type',
        'specifications',
        'pricing',
        'setup_fee',
        'is_active',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'specifications' => 'array',
        'pricing' => 'array',
        'setup_fee' => 'decimal:2',
        'is_active' => 'boolean',
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
     * Scope a query to only include active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by service type.
     */
    public function scopeServiceType($query, $type)
    {
        return $query->where('service_type', $type);
    }

    /**
     * Get the services for this product.
     */
    public function services()
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the price for a specific billing cycle.
     */
    public function getPriceForCycle($cycle)
    {
        return $this->pricing[$cycle] ?? null;
    }

    /**
     * Get all available billing cycles for this product.
     */
    public function getAvailableCycles()
    {
        return array_keys($this->pricing);
    }

    /**
     * Check if this is a game server product.
     */
    public function isGameServer()
    {
        return $this->service_type === 'game_server';
    }

    /**
     * Check if this is a hosting product.
     */
    public function isHosting()
    {
        return in_array($this->service_type, ['web_hosting', 'vps']);
    }

    /**
     * Check if this is a domain product.
     */
    public function isDomain()
    {
        return $this->service_type === 'domain';
    }

    /**
     * Get formatted specifications for display.
     */
    public function getFormattedSpecifications()
    {
        $specs = $this->specifications;
        $formatted = [];

        if (isset($specs['ram'])) {
            $formatted['RAM'] = $specs['ram'];
        }

        if (isset($specs['cpu_cores'])) {
            $formatted['CPU Cores'] = $specs['cpu_cores'];
        }

        if (isset($specs['disk_space'])) {
            $formatted['Disk Space'] = $specs['disk_space'];
        }

        if (isset($specs['bandwidth'])) {
            $formatted['Bandwidth'] = $specs['bandwidth'];
        }

        if (isset($specs['max_players'])) {
            $formatted['Max Players'] = $specs['max_players'];
        }

        if (isset($specs['databases'])) {
            $formatted['Databases'] = $specs['databases'];
        }

        if (isset($specs['email_accounts'])) {
            $formatted['Email Accounts'] = $specs['email_accounts'];
        }

        return $formatted;
    }
}
