<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Owner extends Model
{
    protected $connection = 'roke_pet';
    protected $table = 'owners';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'display_name', 'phone', 'email', 'address',
        'emergency_contact', 'emergency_phone',
        'public_email_visible', 'public_address_visible',
        'adopter_rating_avg', 'adopter_rating_count', 'adopter_adoptions_count',
        'adopter_followups_ratio', 'rescuer_rating_avg', 'rescuer_rating_count',
    ];

    protected $casts = [
        'public_email_visible'    => 'boolean',
        'public_address_visible'  => 'boolean',
        'adopter_rating_avg'      => 'float',
        'adopter_rating_count'    => 'integer',
        'adopter_adoptions_count' => 'integer',
        'adopter_followups_ratio' => 'float',
        'rescuer_rating_avg'      => 'float',
        'rescuer_rating_count'    => 'integer',
    ];

    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class, 'owner_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(OwnerSubscription::class, 'owner_id');
    }

    public function reminderSettings(): HasOne
    {
        return $this->hasOne(ReminderSetting::class, 'owner_id');
    }

    public function isAdmin(): bool
    {
        return AppAdmin::where('user_id', $this->id)->exists();
    }
}
