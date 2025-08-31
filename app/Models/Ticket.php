<?php

namespace App\Models;

use App\Models\User;
use App\Models\Service;
use App\Models\TicketReply;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'service_id',
        'assigned_to',
        'ticket_number',
        'subject',
        'description',
        'priority',      // low | medium | high | urgent
        'status',        // open | in_progress | waiting_customer | resolved | closed
        'category',      // technical | billing | general | feature_request | bug_report (nullable)
        'department',    // technical | billing | sales | abuse
        'closed_at',
        'resolved_at',
        'last_reply_at',
        'last_reply_by',
    ];

    protected $casts = [
        'closed_at'     => 'datetime',
        'resolved_at'   => 'datetime',
        'last_reply_at' => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'deleted_at'    => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function (Ticket $ticket) {
            if (empty($ticket->uuid)) {
                $ticket->uuid = (string) Str::uuid();
            }
            // La BD ya tiene default 'open', esto es por si llega vacío
            if (empty($ticket->status)) {
                $ticket->status = 'open';
            }
            if (empty($ticket->department)) {
                $ticket->department = 'technical';
            }
        });
    }

    /* ===================== Relaciones ===================== */

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Agente asignado (nullable)
    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // Último que respondió (nullable)
    public function lastReplyBy()
    {
        return $this->belongsTo(User::class, 'last_reply_by');
    }

    // Servicio asociado (nullable)
    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function replies()
    {
        return $this->hasMany(TicketReply::class, 'ticket_id')
                    ->orderBy('created_at', 'asc');
    }

    public function lastReply()
    {
        // Requiere Laravel 8.54+ / 9+ / 10+
        return $this->hasOne(TicketReply::class)->latestOfMany('created_at');
    }

    /* ===================== Scopes ===================== */

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress', 'waiting_customer']);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByCategory($query, ?string $category)
    {
        return is_null($category)
            ? $query->whereNull('category')
            : $query->where('category', $category);
    }

    public function scopeByDepartment($query, string $department)
    {
        return $query->where('department', $department);
    }

    /* ===================== Accessors (etiquetas) ===================== */

    public function getPriorityLabelAttribute(): string
    {
        return [
            'low'    => 'Baja',
            'medium' => 'Media',
            'high'   => 'Alta',
            'urgent' => 'Urgente',
        ][$this->priority] ?? 'Media';
    }

    public function getStatusLabelAttribute(): string
    {
        return [
            'open'              => 'Abierto',
            'in_progress'       => 'En Progreso',
            'waiting_customer'  => 'Esperando Cliente',
            'resolved'          => 'Resuelto',
            'closed'            => 'Cerrado',
        ][$this->status] ?? 'Abierto';
    }

    public function getCategoryLabelAttribute(): string
    {
        return [
            'technical'       => 'Técnico',
            'billing'         => 'Facturación',
            'general'         => 'General',
            'feature_request' => 'Solicitud de Función',
            'bug_report'      => 'Reporte de Error',
        ][$this->category] ?? 'General';
    }

    /* ===================== Helpers de estado ===================== */

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'in_progress', 'waiting_customer'], true);
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function canBeRepliedBy(User $user): bool
    {
        if ($this->user_id === $user->id && $this->isOpen()) {
            return true;
        }
        // Si tu User tiene isAdmin()
        if (method_exists($user, 'isAdmin') && $user->isAdmin() && $this->isOpen()) {
            return true;
        }
        return false;
    }

    public function close(?User $user = null): void
    {
        $this->update([
            'status'        => 'closed',
            'closed_at'     => now(),
            'resolved_at'   => $this->resolved_at ?? now(),
            'last_reply_at' => now(),
            'last_reply_by' => $user?->id,
        ]);
    }

    public function reopen(?User $user = null): void
    {
        $this->update([
            'status'        => 'open',
            'closed_at'     => null,
            'resolved_at'   => null,
            'last_reply_at' => now(),
            'last_reply_by' => $user?->id,
        ]);
    }

    public function updateLastReply(User $user): void
    {
        $this->update([
            'last_reply_at' => now(),
            'last_reply_by' => $user->id,
        ]);
    }

    public function getResponseTimeAttribute(): ?int
    {
        return $this->last_reply_at
            ? $this->created_at?->diffInHours($this->last_reply_at)
            : null;
    }

    public function getIsOverdueAttribute(): bool
    {
        if ($this->isClosed()) return false;

        $hours = now()->diffInHours($this->created_at ?? now());

        return match ($this->priority) {
            'urgent' => $hours > 2,
            'high'   => $hours > 8,
            'medium' => $hours > 24,
            'low'    => $hours > 72,
            default  => $hours > 24,
        };
    }
}
