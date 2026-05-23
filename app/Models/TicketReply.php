<?php

namespace App\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class TicketReply extends Model
{
    use HasFactory, SoftDeletes, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'ticket_id',
        'user_id',
        'message',
        'is_internal',
        'attachments',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'is_internal'  => 'boolean',
        'attachments'  => 'array',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
        'deleted_at'   => 'datetime',
    ];

    // Relaciones
    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

     /**
     * Atributo computado para añadir las URLs completas a los adjuntos.
     * Esto modifica la salida JSON para que el frontend no tenga que construir las URLs.
     */
    public function getAttachmentsAttribute($value)
    {
        // Soporta tanto el valor recién asignado en memoria (array) como el
        // JSON crudo almacenado en la base de datos (string).
        $attachments = is_array($value) ? $value : json_decode($value, true);

        if (is_array($attachments)) {
            return array_map(function ($attachment) {
                if (is_array($attachment) && !empty($attachment['path'])) {
                    // Añadir la URL completa a cada adjunto
                    $attachment['url'] = Storage::disk('public')->url($attachment['path']);
                }
                return $attachment;
            }, $attachments);
        }

        return []; // Devolver un array vacío si no hay adjuntos o el formato es incorrecto
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

    /** Respuestas públicas aún no leídas por su destinatario. */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at')->where('is_internal', false);
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
