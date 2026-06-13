<?php

namespace App\Domains\Pet\Http\Controllers\Chat;

use App\Domains\Pet\Jobs\GenerateAiReplyJob;
use App\Domains\Pet\Models\ChatConversation;
use App\Domains\Pet\Models\ChatMessage;
use App\Domains\Pet\Services\Support\ChatService;
use App\Http\Controllers\Controller;
use App\Rules\TurnstileToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chat de soporte — lado del INVITADO (landing pública, sin sesión).
 *
 * Espejo de OwnerChatController, pero la identidad no es Sanctum: el invitado
 * deja sus datos de contacto (nombre + correo/teléfono) y recibe un guest_token
 * opaco que autoriza a leer/escribir SU conversación vía header X-Guest-Token.
 *
 * El alta (start) está protegida por Cloudflare Turnstile. El invitado no tiene
 * tiempo real (canal privado requiere usuario); el frontend hace polling.
 */
class GuestChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    /** POST /chat/guest/conversation — inicia una conversación (modo IA). */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'required|string|max:120',
            'email'                 => 'required_without:phone|nullable|email|max:180',
            'phone'                 => 'required_without:email|nullable|string|max:40',
            'subject'               => 'nullable|string|max:180',
            'message'               => 'required|string|max:2000',
            'cf-turnstile-response' => ['bail', 'required', 'string', new TurnstileToken($request)],
        ]);

        $name = trim($validated['name']);

        $conversation = ChatConversation::create([
            'brand'       => 'roke_pet',
            'channel'     => 'web',
            'source'      => 'public_site',
            'owner_id'    => null,
            'guest_token' => Str::random(48),
            'guest_name'  => $name,
            'guest_email' => $validated['email'] ?? null,
            'guest_phone' => $validated['phone'] ?? null,
            'status'      => ChatConversation::STATUS_AI_ACTIVE,
            'priority'    => 'normal',
            'subject'     => $validated['subject'] ?? null,
            'ai_enabled'  => true,
            'ai_status'   => 'enabled',
        ]);

        $this->chat->postMessage($conversation, [
            'sender_type'  => ChatMessage::SENDER_OWNER,
            'sender_id'    => null,
            'sender_name'  => $name,
            'body'         => trim($validated['message']),
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        GenerateAiReplyJob::dispatchSync($conversation->id);

        return response()->json([
            'success' => true,
            'data'    => $this->summary($conversation->refresh(), withToken: true),
        ], 201);
    }

    /** GET /chat/guest/conversation — conversación activa del invitado (o null). */
    public function current(Request $request): JsonResponse
    {
        $conversation = $this->resolve($request);

        if ($conversation && $conversation->isExpired()) {
            $this->chat->autoExpire($conversation);
        }

        return response()->json([
            'success' => true,
            'data'    => $conversation ? $this->summary($conversation->refresh()) : null,
        ]);
    }

    /** GET /chat/guest/conversation/messages */
    public function messages(Request $request): JsonResponse
    {
        $conv = $this->resolveOrFail($request);
        $this->markIncomingRead($conv);

        $messages = $conv->messages()->paginate(50);
        $messages->getCollection()->transform(
            fn (ChatMessage $message) => $message->toBroadcastArray()
        );

        return response()->json(['success' => true, 'data' => $messages]);
    }

    /** POST /chat/guest/conversation/messages */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);
        $conv = $this->resolveOrFail($request);

        if ($conv->isClosed()) {
            return response()->json(['success' => false, 'message' => 'La conversación está cerrada.'], 422);
        }

        $message = $this->chat->postMessage($conv, [
            'sender_type'  => ChatMessage::SENDER_OWNER,
            'sender_id'    => null,
            'sender_name'  => $conv->guest_name ?? 'Invitado',
            'body'         => trim($validated['message']),
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        if ($conv->refresh()->aiShouldAutoReply()) {
            GenerateAiReplyJob::dispatchSync($conv->id);
        }

        return response()->json(['success' => true, 'data' => $message->toBroadcastArray()], 201);
    }

    /** POST /chat/guest/conversation/escalate — "Hablar con una persona". */
    public function escalate(Request $request): JsonResponse
    {
        $conv = $this->resolveOrFail($request);
        $this->chat->escalate($conv, 'customer_requested');

        return response()->json(['success' => true, 'data' => $this->summary($conv->refresh())]);
    }

    /** POST /chat/guest/conversation/read */
    public function read(Request $request): JsonResponse
    {
        $conv = $this->resolveOrFail($request);
        $this->markIncomingRead($conv);

        return response()->json(['success' => true]);
    }

    /* ===================== Helpers ===================== */

    private function guestToken(Request $request): string
    {
        return trim((string) $request->header('X-Guest-Token', ''));
    }

    private function resolve(Request $request): ?ChatConversation
    {
        $token = $this->guestToken($request);
        if ($token === '') {
            return null;
        }

        return ChatConversation::forBrand()
            ->forGuest($token)
            ->active()
            ->latest('last_message_at')
            ->latest('created_at')
            ->first()
            ?? ChatConversation::forBrand()->forGuest($token)->latest('created_at')->first();
    }

    private function resolveOrFail(Request $request): ChatConversation
    {
        $conv = $this->resolve($request);
        abort_if($conv === null, 404, 'Conversación no encontrada.');

        return $conv;
    }

    private function markIncomingRead(ChatConversation $conv): void
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
    }

    /** @return array<string, mixed> */
    private function summary(ChatConversation $conv, bool $withToken = false): array
    {
        $data = [
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

        // El token solo se entrega al crear la conversación; el cliente lo guarda.
        if ($withToken) {
            $data['guest_token'] = $conv->guest_token;
        }

        return $data;
    }
}
