<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketReply extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'message',
        'is_internal',
        'attachments'
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'attachments' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    // Relaciones
    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopePublic($query)
    {
        return $query->where('is_internal', false);
    }

    public function scopeInternal($query)
    {
        return $query->where('is_internal', true);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Métodos
    public function isFromCustomer()
    {
        return $this->user_id === $this->ticket->user_id;
    }

    public function isFromStaff()
    {
        return !$this->isFromCustomer();
    }

    public function canBeViewedBy(User $user)
    {
        // Las respuestas internas solo pueden ser vistas por el staff
        if ($this->is_internal && !$user->isAdmin()) {
            return false;
        }

        // El propietario del ticket puede ver todas las respuestas públicas
        if ($this->ticket->user_id === $user->id && !$this->is_internal) {
            return true;
        }

        // Los administradores pueden ver todas las respuestas
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    public function canBeEditedBy(User $user)
    {
        // Solo el autor de la respuesta puede editarla dentro de los primeros 15 minutos
        if ($this->user_id === $user->id && $this->created_at->diffInMinutes(now()) <= 15) {
            return true;
        }

        // Los administradores pueden editar cualquier respuesta
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    public function canBeDeletedBy(User $user)
    {
        // Solo el autor de la respuesta puede eliminarla dentro de los primeros 5 minutos
        if ($this->user_id === $user->id && $this->created_at->diffInMinutes(now()) <= 5) {
            return true;
        }

        // Los administradores pueden eliminar cualquier respuesta
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    public function hasAttachments()
    {
        return !empty($this->attachments);
    }

    public function getAttachmentCount()
    {
        return $this->hasAttachments() ? count($this->attachments) : 0;
    }

    // Boot method para actualizar el ticket cuando se crea una respuesta
    protected static function boot()
    {
        parent::boot();

        static::created(function ($reply) {
            $reply->ticket->updateLastReply($reply->user);
            
            // Si es una respuesta del staff, cambiar el estado a "in_progress"
            if ($reply->isFromStaff() && $reply->ticket->status === 'open') {
                $reply->ticket->update(['status' => 'in_progress']);
            }
            
            // Si es una respuesta del cliente y el ticket estaba esperando al cliente
            if ($reply->isFromCustomer() && $reply->ticket->status === 'waiting_customer') {
                $reply->ticket->update(['status' => 'in_progress']);
            }
        });
    }
}
