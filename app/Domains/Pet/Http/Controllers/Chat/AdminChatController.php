<?php

namespace App\Domains\Pet\Http\Controllers\Chat;

use App\Domains\Pet\Events\ChatUserTyping;
use App\Domains\Pet\Models\ChatConversation;
use App\Domains\Pet\Models\ChatMessage;
use App\Domains\Pet\Services\Support\ChatService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat de soporte — panel de ADMIN/agente. Guard: pet.admin (además del check de
 * isAdmin del Owner autenticado). Permite ver, tomar y resolver conversaciones de
 * ROKE Pet (diseñado para filtrar también por otras marcas en el futuro).
 */
class AdminChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    /** GET /admin/chat/conversations — lista filtrable. */
    public function index(Request $request): JsonResponse
    {
        $query = ChatConversation::query()
            ->with(['owner:id,display_name,email'])
            ->when($request->filled('brand') && $request->get('brand') !== 'all',
                fn ($q) => $q->where('brand', $request->get('brand')),
                fn ($q) => $q->where('brand', 'roke_pet'),
            )
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = $request->get('status');
                $status === 'active'
                    ? $q->active()
                    : $q->where('status', $status);
            })
            ->when($request->get('assigned') === '1', fn ($q) => $q->whereNotNull('assigned_agent_id'))
            ->when($request->get('assigned') === '0', fn ($q) => $q->whereNull('assigned_agent_id'))
            ->when($request->boolean('escalated'), fn ($q) => $q->whereIn('status', [
                ChatConversation::STATUS_WAITING_AGENT,
            ]))
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at');

        $conversations = $query->paginate(min((int) $request->get('per_page', 20), 100));

        // Adjuntar el último mensaje a cada conversación (sin N+1 grande).
        $ids = collect($conversations->items())->pluck('id');
        $lastByConv = ChatMessage::whereIn('conversation_id', $ids)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('conversation_id')
            ->map(fn ($group) => $group->first()?->toBroadcastArray());

        $conversations->getCollection()->transform(function (ChatConversation $c) use ($lastByConv) {
            $arr = $this->summary($c);
            $arr['owner'] = $c->owner ? [
                'uuid'         => $c->owner->id,
                'display_name' => $c->owner->display_name,
                'email'        => $c->owner->email,
            ] : null;
            $arr['guest'] = $this->guestLead($c);
            $arr['last_message'] = $lastByConv->get($c->id);
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $conversations]);
    }

    /** GET /admin/chat/conversations/{conversation} — detalle + contexto del dueño. */
    public function show(string $conversation): JsonResponse
    {
        $conv = ChatConversation::with([
            'owner:id,display_name,email,phone',
            'owner.pets',
            'owner.subscription',
        ])->findOrFail($conversation);

        $data = $this->summary($conv);
        $data['escalation_reason'] = $conv->escalation_reason;
        $data['owner'] = $conv->owner ? [
            'uuid'         => $conv->owner->id,
            'display_name' => $conv->owner->display_name,
            'email'        => $conv->owner->email,
            'phone'        => $conv->owner->phone,
            'pets'         => $conv->owner->pets,
            'subscription' => $conv->owner->subscription,
        ] : null;
        $data['guest'] = $this->guestLead($conv);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /admin/chat/conversations/{conversation}/messages */
    public function messages(string $conversation): JsonResponse
    {
        $conv = ChatConversation::findOrFail($conversation);
        $this->markOwnerRead($conv);

        $messages = $conv->messages()->paginate(50);
        $messages->getCollection()->transform(
            fn (ChatMessage $message) => $message->toBroadcastArray()
        );

        return response()->json(['success' => true, 'data' => $messages]);
    }

    /** POST /admin/chat/conversations/{conversation}/messages — responder (toma la conversación). */
    public function send(Request $request, string $conversation): JsonResponse
    {
        $validated = $request->validate([
            'message'       => 'required_without:attachments|nullable|string|max:2000',
            'attachments'   => 'nullable|array|max:5',
            'attachments.*' => 'file|max:20480|mimes:jpg,jpeg,png,webp,gif,pdf,txt,zip',
        ]);
        $conv  = ChatConversation::findOrFail($conversation);
        $agent = $request->user();

        // Responder = tomar la conversación: la IA se desactiva.
        $this->chat->agentTakeover($conv, $agent->uuid, $agent->full_name);

        $attachments = $this->chat->storeAttachments($conv, $request->file('attachments', []));
        $body = trim((string) ($validated['message'] ?? ''));

        $message = $this->chat->postMessage($conv->refresh(), [
            'sender_type' => ChatMessage::SENDER_AGENT,
            'sender_id'   => $agent->uuid,
            'sender_name' => $agent->full_name,
            'body'        => $body,
            'message_type' => ! empty($attachments) ? ChatMessage::TYPE_ATTACHMENT : ChatMessage::TYPE_TEXT,
            'metadata'    => ! empty($attachments) ? ['attachments' => $attachments] : null,
        ]);

        return response()->json(['success' => true, 'data' => $message->toBroadcastArray()], 201);
    }

    /** POST /admin/chat/conversations/{conversation}/takeover */
    public function takeover(Request $request, string $conversation): JsonResponse
    {
        $conv  = ChatConversation::findOrFail($conversation);
        $agent = $request->user();
        $this->chat->agentTakeover($conv, $agent->uuid, $agent->full_name);

        return response()->json(['success' => true, 'data' => $this->summary($conv->refresh())]);
    }

    /** POST /admin/chat/conversations/{conversation}/assign */
    public function assign(Request $request, string $conversation): JsonResponse
    {
        $validated = $request->validate(['agent_id' => 'required|string|max:64', 'agent_name' => 'nullable|string|max:120']);
        $conv = ChatConversation::findOrFail($conversation);

        $conv->forceFill([
            'assigned_agent_id' => $validated['agent_id'],
            'status'            => $conv->status === ChatConversation::STATUS_AI_ACTIVE
                ? ChatConversation::STATUS_WAITING_AGENT
                : $conv->status,
        ])->save();

        $this->chat->systemMessage($conv, 'La conversación fue asignada a un agente de soporte.', [
            'kind' => 'assigned', 'agent_id' => $validated['agent_id'],
        ]);

        return response()->json(['success' => true, 'data' => $this->summary($conv->refresh())]);
    }

    /** POST /admin/chat/conversations/{conversation}/typing */
    public function typing(Request $request, string $conversation): JsonResponse
    {
        $conv  = ChatConversation::findOrFail($conversation);
        $agent = $request->user();

        broadcast(new ChatUserTyping(
            $conv,
            ChatMessage::SENDER_AGENT,
            $agent->full_name,
            $request->boolean('is_typing', true),
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    /** POST /admin/chat/conversations/{conversation}/read */
    public function read(string $conversation): JsonResponse
    {
        $conv = ChatConversation::findOrFail($conversation);
        $this->markOwnerRead($conv);

        return response()->json(['success' => true]);
    }

    /** POST /admin/chat/conversations/{conversation}/resolve */
    public function resolve(Request $request, string $conversation): JsonResponse
    {
        $conv = ChatConversation::findOrFail($conversation);
        $this->chat->resolve($conv, $request->user()->full_name, close: false);

        return response()->json(['success' => true, 'data' => $this->summary($conv->refresh())]);
    }

    /** POST /admin/chat/conversations/{conversation}/close */
    public function close(Request $request, string $conversation): JsonResponse
    {
        $conv = ChatConversation::findOrFail($conversation);
        $this->chat->resolve($conv, $request->user()->full_name, close: true);

        return response()->json(['success' => true, 'data' => $this->summary($conv->refresh())]);
    }

    /** GET /admin/chat/stats */
    public function stats(): JsonResponse
    {
        $base = ChatConversation::forBrand();

        return response()->json([
            'success' => true,
            'data'    => [
                'active'        => (clone $base)->active()->count(),
                'waiting_agent' => (clone $base)->where('status', ChatConversation::STATUS_WAITING_AGENT)->count(),
                'human_active'  => (clone $base)->where('status', ChatConversation::STATUS_HUMAN_ACTIVE)->count(),
                'ai_active'     => (clone $base)->where('status', ChatConversation::STATUS_AI_ACTIVE)->count(),
                'unassigned'    => (clone $base)->active()->whereNull('assigned_agent_id')->count(),
                'today'         => (clone $base)->whereDate('created_at', today())->count(),
            ],
        ]);
    }

    /* ===================== Helpers ===================== */

    private function markOwnerRead(ChatConversation $conv): void
    {
        $ids = $conv->messages()
            ->where('sender_type', ChatMessage::SENDER_OWNER)
            ->whereNull('read_at')
            ->pluck('id')
            ->all();

        if (empty($ids) && $conv->unread_for_agent === 0) {
            return;
        }

        ChatMessage::whereIn('id', $ids)->update(['read_at' => now()]);
        $conv->forceFill(['unread_for_agent' => 0])->save();

        if (! empty($ids)) {
            broadcast(new \App\Domains\Pet\Events\ChatMessageRead($conv, $ids, 'agent'))->toOthers();
        }
    }

    /** @return array<string, mixed> */
    private function summary(ChatConversation $conv): array
    {
        return [
            'id'                => $conv->id,
            'brand'             => $conv->brand,
            'channel'           => $conv->broadcastChannelName(),
            'status'            => $conv->status,
            'priority'          => $conv->priority,
            'ai_enabled'        => (bool) $conv->ai_enabled,
            'ai_status'         => $conv->ai_status,
            'assigned_agent_id' => $conv->assigned_agent_id,
            'subject'           => $conv->subject,
            'unread_for_agent'  => $conv->unread_for_agent,
            'escalated_at'      => $conv->escalated_at?->toISOString(),
            'last_message_at'   => $conv->last_message_at?->toISOString(),
            'created_at'        => $conv->created_at?->toISOString(),
        ];
    }

    /**
     * Datos de contacto del lead cuando la conversación la inició un invitado de
     * la landing (sin sesión). null para conversaciones de dueños registrados.
     *
     * @return array<string, mixed>|null
     */
    private function guestLead(ChatConversation $conv): ?array
    {
        if (! $conv->isGuest()) {
            return null;
        }

        return [
            'name'  => $conv->guest_name,
            'email' => $conv->guest_email,
            'phone' => $conv->guest_phone,
        ];
    }
}
