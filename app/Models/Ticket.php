<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'subject',
        'description',
        'priority',
        'status',
        'category',
        'assigned_to',
        'resolved_at',
        'last_reply_at',
        'last_reply_by'
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'last_reply_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    // Relaciones
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies()
    {
        return $this->hasMany(TicketReply::class)->orderBy('created_at', 'asc');
    }

    public function lastReplyBy()
    {
        return $this->belongsTo(User::class, 'last_reply_by');
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    // Accessors
    public function getPriorityLabelAttribute()
    {
        $labels = [
            'low' => 'Baja',
            'medium' => 'Media',
            'high' => 'Alta',
            'urgent' => 'Urgente'
        ];

        return $labels[$this->priority] ?? 'Media';
    }

    public function getStatusLabelAttribute()
    {
        $labels = [
            'open' => 'Abierto',
            'in_progress' => 'En Progreso',
            'waiting_customer' => 'Esperando Cliente',
            'closed' => 'Cerrado'
        ];

        return $labels[$this->status] ?? 'Abierto';
    }

    public function getCategoryLabelAttribute()
    {
        $labels = [
            'technical' => 'Técnico',
            'billing' => 'Facturación',
            'general' => 'General',
            'feature_request' => 'Solicitud de Función',
            'bug_report' => 'Reporte de Error'
        ];

        return $labels[$this->category] ?? 'General';
    }

    // Métodos
    public function isOpen()
    {
        return in_array($this->status, ['open', 'in_progress', 'waiting_customer']);
    }

    public function isClosed()
    {
        return $this->status === 'closed';
    }

    public function canBeRepliedBy(User $user)
    {
        // El usuario propietario del ticket puede responder si está abierto
        if ($this->user_id === $user->id && $this->isOpen()) {
            return true;
        }

        // Los administradores pueden responder a cualquier ticket abierto
        if ($user->isAdmin() && $this->isOpen()) {
            return true;
        }

        return false;
    }

    public function close(User $user = null)
    {
        $this->update([
            'status' => 'closed',
            'resolved_at' => now(),
            'last_reply_at' => now(),
            'last_reply_by' => $user ? $user->id : null
        ]);
    }

    public function reopen(User $user = null)
    {
        $this->update([
            'status' => 'open',
            'resolved_at' => null,
            'last_reply_at' => now(),
            'last_reply_by' => $user ? $user->id : null
        ]);
    }

    public function updateLastReply(User $user)
    {
        $this->update([
            'last_reply_at' => now(),
            'last_reply_by' => $user->id
        ]);
    }

    public function getResponseTimeAttribute()
    {
        if (!$this->last_reply_at) {
            return null;
        }

        return $this->created_at->diffInHours($this->last_reply_at);
    }

    public function getIsOverdueAttribute()
    {
        if ($this->isClosed()) {
            return false;
        }

        $hours = now()->diffInHours($this->created_at);
        
        switch ($this->priority) {
            case 'urgent':
                return $hours > 2;
            case 'high':
                return $hours > 8;
            case 'medium':
                return $hours > 24;
            case 'low':
                return $hours > 72;
            default:
                return $hours > 24;
        }
    }
}
