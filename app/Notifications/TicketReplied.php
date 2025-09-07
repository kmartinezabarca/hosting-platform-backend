<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TicketReplied extends Notification implements ShouldQueue
{
    use Queueable;

    protected $ticket;
    protected $reply;

    /**
     * Create a new notification instance.
     */
    public function __construct(Ticket $ticket, TicketReply $reply)
    {
        $this->ticket = $ticket;
        $this->reply = $reply;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];
        
        // Agregar email si el usuario tiene habilitadas las notificaciones por email
        if ($notifiable->email_notifications ?? true) {
            $channels[] = 'mail';
        }
        
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $fromWho = $this->reply->is_from_admin ? 'el equipo de soporte' : 'el cliente';
        
        return (new MailMessage)
            ->subject('Nueva respuesta en tu ticket de soporte')
            ->greeting('¡Hola ' . $notifiable->name . '!')
            ->line("Hay una nueva respuesta de {$fromWho} en tu ticket: {$this->ticket->subject}")
            ->line("Respuesta: " . substr($this->reply->message, 0, 200) . (strlen($this->reply->message) > 200 ? '...' : ''))
            ->action('Ver Ticket', url('/dashboard/support/tickets/' . $this->ticket->uuid))
            ->line('Puedes responder desde tu panel de control.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        $fromWho = $this->reply->is_from_admin ? 'Soporte' : 'Cliente';
        
        return [
            'type' => 'ticket_replied',
            'ticket_id' => $this->ticket->uuid,
            'ticket_subject' => $this->ticket->subject,
            'reply_id' => $this->reply->id,
            'from_admin' => $this->reply->is_from_admin,
            'title' => 'Nueva Respuesta',
            'message' => "Nueva respuesta de {$fromWho} en: {$this->ticket->subject}",
            'action_url' => '/dashboard/support/tickets/' . $this->ticket->uuid,
            'action_text' => 'Ver Ticket',
            'icon' => 'chat-bubble-left-right',
            'color' => 'info',
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $fromWho = $this->reply->is_from_admin ? 'Soporte' : 'Cliente';
        
        return new BroadcastMessage([
            'type' => 'ticket_replied',
            'ticket_id' => $this->ticket->uuid,
            'ticket_subject' => $this->ticket->subject,
            'reply_id' => $this->reply->id,
            'from_admin' => $this->reply->is_from_admin,
            'title' => 'Nueva Respuesta',
            'message' => "Nueva respuesta de {$fromWho} en: {$this->ticket->subject}",
            'action_url' => '/dashboard/support/tickets/' . $this->ticket->uuid,
            'action_text' => 'Ver Ticket',
            'icon' => 'chat-bubble-left-right',
            'color' => 'info',
            'timestamp' => now()->toISOString(),
        ]);
    }
}

