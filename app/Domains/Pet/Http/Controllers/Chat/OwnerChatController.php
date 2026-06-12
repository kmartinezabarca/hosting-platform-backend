<?php

namespace App\Domains\Pet\Http\Controllers\Chat;

use App\Domains\Pet\Events\ChatMessageRead;
use App\Domains\Pet\Events\ChatUserTyping;
use App\Domains\Pet\Jobs\GenerateAiReplyJob;
use App\Domains\Pet\Models\ChatConversation;
use App\Domains\Pet\Models\ChatMessage;
use App\Domains\Pet\Services\Support\ChatService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat de soporte — lado del DUEÑO de mascota (ROKE Pet). Auth: sanctum (Owner).
 */
class OwnerChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    /** GET /chat/conversation — conversación activa del dueño (o null). */
    public function current(Request $request): JsonResponse
    {
        $ownerId = $request->user()->uuid;

        // El chat no es eterno: cierra (reinicia) cualquier conversación inactiva >24h
        // antes de resolver la actual, para que el dueño empiece una nueva.
        ChatConversation::forBrand()->forOwner($ownerId)->stale()->get()
            ->each(fn (ChatConversation $c) => $this->chat->autoExpire($c));

        $conversation = ChatConversation::forBrand()
            ->forOwner($ownerId)
            ->active()
            ->latest('last_message_at')
            ->latest('created_at')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $conversation ? $this->summary($conversation) : null,
        ]);
    }

    /** POST /chat/conversation — inicia una conversación nueva (modo IA). */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'nullable|string|max:2000',
            'subject' => 'nullable|string|max:180',
        ]);

        $owner = $request->user();

        $conversation = ChatConversation::create([
            'brand'      => 'roke_pet',
            'channel'    => 'pet_app',
            'source'     => 'pet_app',
            'owner_id'   => $owner->uuid,
            'status'     => ChatConversation::STATUS_AI_ACTIVE,
            'priority'   => 'normal',
            'subject'    => $validated['subject'] ?? null,
            'ai_enabled' => true,
            'ai_status'  => 'enabled',
        ]);

        if (! empty($validated['message'])) {
            $this->chat->postMessage($conversation, [
                'sender_type' => ChatMessage::SENDER_OWNER,
                'sender_id'   => $owner->uuid,
                'sender_name' => $owner->full_name,
                'body'        => $validated['message'],
            ]);

            GenerateAiReplyJob::dispatchSync($conversation->id);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->summary($conversation->refresh()),
        ], 201);
    }

    /** GET /chat/conversations/{conversation}/messages */
    public function messages(Request $request, string $conversation): JsonResponse
    {
        $conv = $this->ownedOrFail($request, $conversation);

        // Al abrir, el dueño "lee" los mensajes entrantes (IA/agente/sistema).
        $this->markIncomingRead($conv, $request);

        $messages = $conv->messages()->paginate(50);

        return response()->json(['success' => true, 'data' => $messages]);
    }

    /** POST /chat/conversations/{conversation}/messages */
    public function send(Request $request, string $conversation): JsonResponse
    {
        $validated = $request->validate(['message' => 'required|string|max:2000']);
        $conv  = $this->ownedOrFail($request, $conversation);
        $owner = $request->user();

        if ($conv->isClosed()) {
            return response()->json(['success' => false, 'message' => 'La conversación está cerrada.'], 422);
        }

        $message = $this->chat->postMessage($conv, [
            'sender_type' => ChatMessage::SENDER_OWNER,
            'sender_id'   => $owner->uuid,
            'sender_name' => $owner->full_name,
            'body'        => $validated['message'],
        ]);

        // La IA sólo auto-responde si la conversación sigue en modo IA.
        if ($conv->refresh()->aiShouldAutoReply()) {
            GenerateAiReplyJob::dispatchSync($conv->id);
        }

        return response()->json(['success' => true, 'data' => $message->toBroadcastArray()], 201);
    }

    /** POST /chat/conversations/{conversation}/escalate — "Hablar con una persona". */
    public function escalate(Request $request, string $conversation): JsonResponse
    {
        $conv = $this->ownedOrFail($request, $conversation);
        $this->chat->escalate($conv, 'customer_requested');

        return response()->json(['success' => true, 'data' => $this->summary($conv->refresh())]);
    }

    /** POST /chat/conversations/{conversation}/typing */
    public function typing(Request $request, string $conversation): JsonResponse
    {
        $conv = $this->ownedOrFail($request, $conversation);
        $owner = $request->user();

        broadcast(new ChatUserTyping(
            $conv,
            ChatMessage::SENDER_OWNER,
            $owner->full_name,
            $request->boolean('is_typing', true),
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    /** POST /chat/conversations/{conversation}/read */
    public function read(Request $request, string $conversation): JsonResponse
    {
        $conv = $this->ownedOrFail($request, $conversation);
        $this->markIncomingRead($conv, $request);

        return response()->json(['success' => true]);
    }

    /** POST /chat/conversations/{conversation}/close */
    public function close(Request $request, string $conversation): JsonResponse
    {
        $conv = $this->ownedOrFail($request, $conversation);
        $this->chat->resolve($conv, $request->user()->full_name, close: true);

        return response()->json(['success' => true, 'data' => $this->summary($conv->refresh())]);
    }

    /* ===================== Helpers ===================== */

    private function ownedOrFail(Request $request, string $conversationId): ChatConversation
    {
        $conv = ChatConversation::forBrand()->findOrFail($conversationId);

        abort_if($conv->owner_id !== $request->user()->uuid, 403, 'No tienes acceso a esta conversación.');

        return $conv;
    }

    private function markIncomingRead(ChatConversation $conv, Request $request): void
    {
        $ids = $conv->messages()
            ->whereIn('sender_type', [
                ChatMessage::SENDER_AI,
                ChatMessage::SENDER_AGENT,
                ChatMessage::SENDER_SYSTEM,
            ])
            ->whereNull('read_at')
            ->pluck('id')
            ->all();

        if (empty($ids) && $conv->unread_for_owner === 0) {
            return;
        }

        ChatMessage::whereIn('id', $ids)->update(['read_at' => now()]);
        $conv->forceFill(['unread_for_owner' => 0])->save();

        if (! empty($ids)) {
            broadcast(new ChatMessageRead($conv, $ids, 'owner'))->toOthers();
        }
    }

    /** @return array<string, mixed> */
    private function summary(ChatConversation $conv): array
    {
        return [
            'id'                => $conv->id,
            'channel'           => $conv->broadcastChannelName(),
            'status'            => $conv->status,
            'ai_enabled'        => (bool) $conv->ai_enabled,
            'ai_status'         => $conv->ai_status,
            'assigned_agent_id' => $conv->assigned_agent_id,
            'subject'           => $conv->subject,
            'unread_for_owner'  => $conv->unread_for_owner,
            'last_message_at'   => $conv->last_message_at?->toISOString(),
            'created_at'        => $conv->created_at?->toISOString(),
        ];
    }
}
