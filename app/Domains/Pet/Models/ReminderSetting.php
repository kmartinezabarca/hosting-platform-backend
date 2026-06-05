<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderSetting extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'reminder_settings';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'owner_id', 'enabled', 'email_notifications', 'reminder_days',
        'vaccine_reminders', 'deworming_reminders', 'checkup_reminders',
    ];

    protected $casts = [
        'enabled'             => 'boolean',
        'email_notifications' => 'boolean',
        'vaccine_reminders'   => 'boolean',
        'deworming_reminders' => 'boolean',
        'checkup_reminders'   => 'boolean',
        'reminder_days'       => 'array',
    ];

    protected $attributes = [
        'enabled'             => true,
        'email_notifications' => true,
        'vaccine_reminders'   => true,
        'deworming_reminders' => true,
        'checkup_reminders'   => true,
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }
}
