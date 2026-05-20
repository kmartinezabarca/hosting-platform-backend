<?php

namespace App\Models\Pet;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table      = 'notification_logs';

    protected $fillable = [
        'project_id', 'platform_user_id', 'pet_id', 'owner_id',
        'channel', 'provider', 'notification_type',
        'title', 'body', 'payload', 'status',
        'attempts', 'max_attempts', 'provider_message_id',
        'error_code', 'error_message',
        'sent_at', 'delivered_at', 'failed_at', 'last_attempt_at', 'next_retry_at',
    ];

    protected $casts = [
        'payload'          => 'array',
        'attempts'         => 'integer',
        'max_attempts'     => 'integer',
        'sent_at'          => 'datetime',
        'delivered_at'     => 'datetime',
        'failed_at'        => 'datetime',
        'last_attempt_at'  => 'datetime',
        'next_retry_at'    => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class, 'pet_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRetryable($query)
    {
        return $query->whereIn('status', ['failed', 'pending'])
            ->whereColumn('attempts', '<', 'max_attempts');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isRetryable(): bool
    {
        return in_array($this->status, ['failed', 'pending'])
            && $this->attempts < $this->max_attempts;
    }

    public function markSent(string $providerMessageId = null): void
    {
        $this->update([
            'status'              => 'sent',
            'attempts'            => $this->attempts + 1,
            'provider_message_id' => $providerMessageId,
            'sent_at'             => now(),
            'last_attempt_at'     => now(),
            'error_code'          => null,
            'error_message'       => null,
            'next_retry_at'       => null,
        ]);
    }

    public function markFailed(string $errorCode = null, string $errorMessage = null): void
    {
        $nextAttempts = $this->attempts + 1;
        $exhausted    = $nextAttempts >= $this->max_attempts;

        $this->update([
            'status'          => $exhausted ? 'failed' : 'pending',
            'attempts'        => $nextAttempts,
            'error_code'      => $errorCode,
            'error_message'   => $errorMessage,
            'failed_at'       => $exhausted ? now() : $this->failed_at,
            'last_attempt_at' => now(),
            'next_retry_at'   => $exhausted ? null : now()->addMinutes((int) pow(2, $nextAttempts) * 5),
        ]);
    }
}
