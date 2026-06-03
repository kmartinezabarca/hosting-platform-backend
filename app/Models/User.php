<?php

namespace App\Models;
use App\Domains\Platform\Models\TicketReply;
use App\Domains\Platform\Models\Ticket;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Domain;
use App\Domains\Platform\Models\DatabaseNotification;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    protected $appends = ['avatar_full_url', 'is_google_account'];
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'google_id',
        'uuid',
        'email',
        'password',
        'first_name',
        'last_name',
        'username',
        'phone',
        'company',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'avatar_url',
        'role',
        'status',
        'stripe_customer_id',
        'two_factor_enabled',
        'two_factor_secret',
        'last_login_at',
        'kind',
        // Notification preferences
        'email_notifications',
        'push_notifications',
        'service_notifications',
        'payment_notifications',
        'ticket_notifications',
        'invoice_notifications',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'google_id',
        'password',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at'     => 'datetime',
        'password'              => 'hashed',
        'two_factor_enabled'    => 'boolean',
        'last_login_at'         => 'datetime',
        // Notification preferences
        'email_notifications'   => 'boolean',
        'push_notifications'    => 'boolean',
        'service_notifications' => 'boolean',
        'payment_notifications' => 'boolean',
        'ticket_notifications'  => 'boolean',
        'invoice_notifications' => 'boolean',
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

    public function getAvatarFullUrlAttribute(): string
    {
        if ($this->avatar_url) {
            // External URLs (e.g. Google profile pictures) are returned as-is
            if (filter_var($this->avatar_url, FILTER_VALIDATE_URL)) {
                return $this->avatar_url;
            }
            return asset('storage/' . $this->avatar_url);
        }

        return '';
    }

    /**
     * Whether this account was created / linked via Google OAuth.
     * Safe to expose in API responses (google_id itself remains hidden).
     */
    public function getIsGoogleAccountAttribute(): bool
    {
        return !empty($this->google_id);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * Get the user's full name.
     */
    public function getFullNameAttribute()
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole($role)
    {
        return $this->role === $role;
    }

    /**
     * Check if user is admin or super admin.
     */
    public function isAdmin()
    {
        return in_array($this->role, ['admin', 'super_admin']);
    }

    /**
     * Check if user is super admin.
     */
    public function isSuperAdmin()
    {
        return $this->role === 'super_admin';
    }

    /**
     * Check if user belongs to the support staff.
     */
    public function isSupport()
    {
        return $this->role === 'support';
    }

    /**
     * Check if user is any kind of staff member (admin, super_admin or support).
     */
    public function isStaff()
    {
        return in_array($this->role, ['super_admin', 'admin', 'support'], true);
    }

    /**
     * Check if the user's role is one of the given roles.
     */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Get the services for the user.
     */
    public function services()
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the receipts (comprobantes de pago) for the user.
     * Se usa "invoices" como alias para compatibilidad con withCount en el admin.
     */
    public function invoices()
    {
        return $this->hasMany(Receipt::class);
    }

    /**
     * Get the domains for the user.
     */
    public function domains()
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Get the tickets created by the user.
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get the tickets assigned to the user.
     */
    public function assignedTickets()
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }

    /**
     * Get the ticket replies by the user.
     */
    public function ticketReplies()
    {
        return $this->hasMany(TicketReply::class);
    }

    // ── Fiscal profiles ──────────────────────────────────────────────────────

    public function fiscalProfiles()
    {
        return $this->hasMany(\App\Domains\Platform\Models\CustomerFiscalProfile::class);
    }

    public function defaultFiscalProfile()
    {
        return $this->hasOne(\App\Domains\Platform\Models\CustomerFiscalProfile::class)->where('is_default', true);
    }

    public function receivesBroadcastNotificationsOn(): string
    {
        return 'user.' . $this->uuid; // => private-user.{uuid}
    }

    /**
     * Override to use our custom DatabaseNotification model which has an
     * integer primary key + separate uuid column.
     */
    public function notifications()
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')
                    ->orderBy('created_at', 'desc');
    }

    public function readNotifications()
    {
        return $this->notifications()->whereNotNull('read_at');
    }

    public function unreadNotifications()
    {
        return $this->notifications()->whereNull('read_at');
    }
}
