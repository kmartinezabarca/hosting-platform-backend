<?php

namespace App\Domains\Pet\Jobs;

use App\Domains\Pet\Events\ChatUserTyping;
use App\Domains\Pet\Models\ChatConversation;
use App\Domains\Pet\Models\ChatMessage;
use App\Domains\Pet\Services\Support\ChatService;
use App\Domains\Pet\Services\Support\SupportAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Genera la respuesta de la IA a un mensaje del cliente y, si procede, escala.
 *
 * Hoy se invoca con dispatchSync() desde el controlador (respuesta confiable sin
 * depender de un worker; Haiku es rápido). Está encapsulado como Job para poder
 * pasarlo a una cola asíncrona (->dispatch()) sin tocar el controlador.
 */
class GenerateAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $conversationId) {}

    public function handle(SupportAiService $ai, ChatService $chat): void
    {
        $conversation = ChatConversation::find($this->conversationId);
        if (! $conversation) {
            return;
        }

        // Si un humano tomó la conversación (o se escaló) entre que el cliente
        // envió y este job corre, la IA NO responde.
        if (! $conversation->aiShouldAutoReply()) {
            return;
        }

        // "El asistente está escribiendo…"
        event(new ChatUserTyping($conversation, ChatMessage::SENDER_AI, 'Asistente ROKE Pet', true));

        $result = $ai->respondTo($conversation);

        event(new ChatUserTyping($conversation, ChatMessage::SENDER_AI, 'Asistente ROKE Pet', false));

        if ($result->hasReply()) {
            $chat->postMessage($conversation, [
                'sender_type'   => ChatMessage::SENDER_AI,
                'sender_id'     => null,
                'sender_name'   => 'Asistente ROKE Pet',
                'body'          => $result->reply,
                'ai_confidence' => $result->confidence,
                'ai_sources'    => $result->sources ?: null,
            ]);
        }

        if ($result->shouldEscalate) {
            // refrescar estado por si postMessage cambió contadores
            $chat->escalate($conversation->refresh(), $result->escalationReason ?? 'ai_low_confidence');
        }
    }
}
