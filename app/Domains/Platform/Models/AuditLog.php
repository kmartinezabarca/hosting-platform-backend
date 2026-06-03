<?php

namespace App\Domains\Platform\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Immutable-ish record of a sensitive administrative action.
 *
 * Write entries with AuditLog::record(...) (or the AuditLogger service, which
 * wraps it and auto-captures the request IP / user-agent).
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id', 'actor_name', 'actor_email', 'actor_role',
        'action', 'target_type', 'target_id',
        'description', 'ip_address', 'user_agent', 'changes',
    ];

    protected $casts = [
        'changes'    => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Persist an audit entry. Actor defaults to the authenticated user, and its
     * name/email/role are snapshotted at write-time. IP/user-agent are pulled
     * from the current request when available.
     */
    public static function record(
        string $action,
        ?Model $target = null,
        ?string $description = null,
        ?array $changes = null,
        ?User $actor = null,
    ): self {
        $actor   = $actor ?: Auth::user();
        $request = request();

        return static::create([
            'actor_id'    => $actor?->id,
            'actor_name'  => $actor?->full_name,
            'actor_email' => $actor?->email,
            'actor_role'  => $actor?->role,
            'action'      => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id'   => $target ? (string) $target->getKey() : null,
            'description' => $description,
            'ip_address'  => $request instanceof Request ? $request->ip() : null,
            'user_agent'  => $request instanceof Request ? substr((string) $request->userAgent(), 0, 1000) : null,
            'changes'     => $changes,
        ]);
    }
}
