<?php

namespace App\Domains\Pet\Services\Support;

use App\Domains\Pet\Events\ChatAgentJoined;
use App\Domains\Pet\Events\ChatConversationEscalated;
use App\Domains\Pet\Events\ChatConversationResolved;
use App\Domains\Pet\Events\ChatMessageSent;
use App\Domains\Pet\Models\ChatConversation;
use App\Domains\Pet\Models\ChatMessage;

/**
 * Operaciones de dominio del chat que comparten controladores y job de IA, para
 * que el broadcasting y las transiciones de estado vivan en UN solo lugar.
 */
class ChatService
{
    /**
     * Crea un mensaje, actualiza contadores/last_message_at y lo transmite.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function postMessage(ChatConversation $conversation, array $attrs, bool $broadcast = true): ChatMessage
    {
        $message = ChatMessage::create(array_merge([
            'conversation_id' => $conversation->id,
            'message_type'    => ChatMessage::TYPE_TEXT,
            'delivered_at'    => now(),
        ], $attrs));

        $patch = ['last_message_at' => now()];
        if ($message->sender_type === ChatMessage::SENDER_OWNER) {
            $patch['unread_for_agent'] = $conversation->unread_for_agent + 1;
        } else {
            // ai | agent | system => entrante para el dueño.
            $patch['unread_for_owner'] = $conversation->unread_for_owner + 1;
        }
        $conversation->forceFill($patch)->save();

        if ($broadcast) {
            event(new ChatMessageSent($conversation, $message));
        }

        return $message;
    }

    /** Mensaje de sistema (avisos visibles en la conversación). */
    public function systemMessage(ChatConversation $conversation, string $body, array $metadata = []): ChatMessage
    {
        return $this->postMessage($conversation, [
            'sender_type'  => ChatMessage::SENDER_SYSTEM,
            'sender_name'  => 'ROKE Pet',
            'body'         => $body,
            'message_type' => ChatMessage::TYPE_SYSTEM,
            'metadata'     => $metadata ?: null,
        ]);
    }

    /**
     * Escala la conversación a soporte humano. La IA deja de auto-responder
     * (status pasa a waiting_agent). No desactiva ai_enabled todavía: eso ocurre
     * cuando un humano realmente toma la conversación.
     */
    public function escalate(ChatConversation $conversation, string $reason, bool $announce = true): void
    {
        // Idempotente: no re-escalar si ya está con un humano o ya escalada.
        if (in_array($conversation->status, [
            ChatConversation::STATUS_WAITING_AGENT,
            ChatConversation::STATUS_HUMAN_ACTIVE,
        ], true)) {
            return;
        }

        $conversation->forceFill([
            'status'            => ChatConversation::STATUS_WAITING_AGENT,
            'ai_status'         => 'escalated',
            'escalated_at'      => now(),
            'escalation_reason' => $reason,
        ])->save();

        if ($announce) {
            $this->systemMessage(
                $conversation,
                'Estamos pasando tu caso a una persona del equipo de ROKE Pet. En cuanto se conecte, te responderá por aquí. 💛',
                ['kind' => 'escalation', 'reason' => $reason],
            );
        }

        event(new ChatConversationEscalated($conversation, $reason));
    }

    /**
     * Un agente humano toma la conversación: la IA se desactiva por completo y el
     * cliente ve el aviso "un agente se unió".
     */
    public function agentTakeover(ChatConversation $conversation, string $agentId, string $agentName): void
    {
        $alreadyHuman = $conversation->status === ChatConversation::STATUS_HUMAN_ACTIVE
            && $conversation->assigned_agent_id === $agentId;

        $conversation->forceFill([
            'assigned_agent_id' => $agentId,
            'status'            => ChatConversation::STATUS_HUMAN_ACTIVE,
            'ai_enabled'        => false,
            'ai_status'         => 'disabled',
        ])->save();

        if (! $alreadyHuman) {
            $this->systemMessage(
                $conversation,
                "{$agentName}, del equipo de soporte, se unió a la conversación.",
                ['kind' => 'agent_joined', 'agent_name' => $agentName],
            );
            event(new ChatAgentJoined($conversation, $agentName));
        }
    }

    public function resolve(ChatConversation $conversation, string $byName, bool $close = false): void
    {
        $conversation->forceFill([
            'status'      => $close ? ChatConversation::STATUS_CLOSED : ChatConversation::STATUS_RESOLVED,
            'resolved_at' => $conversation->resolved_at ?? now(),
            'closed_at'   => $close ? now() : $conversation->closed_at,
            'ai_enabled'  => false,
        ])->save();

        $this->systemMessage(
            $conversation,
            $close
                ? "La conversación fue cerrada por {$byName}."
                : "La conversación fue marcada como resuelta por {$byName}.",
            ['kind' => 'resolved'],
        );

        event(new ChatConversationResolved($conversation));
    }
}
