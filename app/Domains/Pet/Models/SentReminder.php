<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SentReminder extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'sent_reminders';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'pet_id', 'reminder_type', 'vaccine_id', 'record_id',
        'days_before', 'sent_at', 'email_sent', 'push_sent', 'email_error',
    ];

    protected $casts = [
        'email_sent' => 'boolean',
        'push_sent'  => 'boolean',
        'sent_at'    => 'datetime',
    ];
}
