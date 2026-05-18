<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

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
        $fromStaff = $this->reply->isFromStaff();
        $fromWho = $fromStaff ? 'el equipo de soporte' : 'el cliente';
        $preview = Str::limit(trim(strip_tags($this->reply->message)), 200);
        
        return (new MailMessage)
            ->subject('Nueva respuesta en tu ticket - Roke Industries')
            ->view('emails.notification', [
                'notifiable' => $notifiable,
                'title' => 'Nueva respuesta en tu ticket',
                'subtitle' => 'Actualización de soporte',
                'intro' => "Hay una nueva respuesta de {$fromWho} en tu ticket.",
                'detailsTitle' => 'Detalles del ticket',
                'details' => [
                    'Ticket' => $this->ticket->ticket_number ?? $this->ticket->uuid,
                    'Asunto' => $this->ticket->subject,
                    'Respuesta' => $preview,
                    'Estado' => $this->ticket->status_label ?? $this->ticket->status,
                    'Prioridad' => $this->ticket->priority_label ?? $this->ticket->priority,
                ],
                'actionUrl' => '/client/tickets/' . $this->ticket->uuid,
                'actionText' => 'Ver ticket',
                'footerNote' => 'Puedes responder este ticket desde tu panel de control.',
            ]);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        $fromStaff = $this->reply->isFromStaff();
        $fromWho = $fromStaff ? 'Soporte' : 'Cliente';
        
        return [
            'type' => 'ticket_replied',
            'ticket_id' => $this->ticket->uuid,
            'ticket_subject' => $this->ticket->subject,
            'reply_id' => $this->reply->id,
            'from_admin' => $fromStaff,
            'title' => 'Nueva respuesta',
            'message' => "Nueva respuesta de {$fromWho} en: {$this->ticket->subject}",
            'action_url' => '/dashboard/support/tickets/' . $this->ticket->uuid,
            'action_text' => 'Ver ticket',
            'icon' => 'chat-bubble-left-right',
            'color' => 'info',
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $fromStaff = $this->reply->isFromStaff();
        $fromWho = $fromStaff ? 'Soporte' : 'Cliente';
        
        return new BroadcastMessage([
            'type' => 'ticket_replied',
            'ticket_id' => $this->ticket->uuid,
            'ticket_subject' => $this->ticket->subject,
            'reply_id' => $this->reply->id,
            'from_admin' => $fromStaff,
            'title' => 'Nueva respuesta',
            'message' => "Nueva respuesta de {$fromWho} en: {$this->ticket->subject}",
            'action_url' => '/dashboard/support/tickets/' . $this->ticket->uuid,
            'action_text' => 'Ver ticket',
            'icon' => 'chat-bubble-left-right',
            'color' => 'info',
            'timestamp' => now()->toISOString(),
        ]);
    }
}
