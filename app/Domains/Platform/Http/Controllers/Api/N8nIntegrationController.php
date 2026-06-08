<?php

namespace App\Domains\Platform\Http\Controllers\Api;

use App\Domains\Platform\Models\ActivityLog;
use App\Domains\Platform\Models\Documentation;
use App\Domains\Platform\Models\Ticket;
use App\Domains\Platform\Models\WhatsappConversation;
use App\Domains\Platform\Models\WhatsappMessage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Capa de integración para n8n (automatización de soporte por WhatsApp).
 *
 * Todas las rutas están protegidas por el middleware `verify.n8n` (token
 * compartido). n8n recibe el webhook de WhatsApp (Meta), llama a `inbound`
 * para registrar el mensaje y obtener contexto, consulta `knowledge` para el
 * RAG del LLM, registra su respuesta con `reply`, y escala con `handoff`.
 */
class N8nIntegrationController extends Controller
{
    /** GET /integrations/n8n/health — ping para validar el token desde n8n. */
    public function health(): JsonResponse
    {
        return response()->json(['success' => true, 'service' => 'roke-n8n', 'time' => now()->toIso8601String()]);
    }

    /**
     * POST /integrations/n8n/whatsapp/inbound
     * Registra un mensaje entrante de WhatsApp y devuelve el contexto del hilo.
     */
    public function inbound(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'         => ['required', 'string', 'max:32'],
            'name'          => ['nullable', 'string', 'max:255'],
            'message'       => ['required', 'string'],
            'wa_message_id' => ['nullable', 'string', 'max:255'],
            'meta'          => ['nullable', 'array'],
        ]);

        $phone = $this->normalizePhone($data['phone']);

        $conversation = WhatsappConversation::firstOrCreate(
            ['wa_phone' => $phone],
            [
                'contact_name' => $data['name'] ?? null,
                'user_id'      => $this->matchUserByPhone($phone)?->id,
                'status'       => WhatsappConversation::STATUS_BOT,
            ],
        );

        // Idempotencia: si Meta reintenta el webhook, no duplicar el mensaje.
        $alreadyStored = ! empty($data['wa_message_id'])
            && $conversation->messages()
                ->where('wa_message_id', $data['wa_message_id'])
                ->exists();

        if (! $alreadyStored) {
            $conversation->messages()->create([
                'direction'     => WhatsappMessage::DIRECTION_INBOUND,
                'sender'        => WhatsappMessage::SENDER_CONTACT,
                'body'          => $data['message'],
                'wa_message_id' => $data['wa_message_id'] ?? null,
                'meta'          => $data['meta'] ?? null,
            ]);
            $conversation->update(['last_message_at' => now()]);
        }

        $conversation->loadMissing('user');

        return response()->json([
            'success'           => true,
            'conversation_uuid' => $conversation->uuid,
            'status'            => $conversation->status,
            // n8n solo debe responder automáticamente si el hilo sigue en modo bot.
            'should_autorespond' => $conversation->status === WhatsappConversation::STATUS_BOT,
            'is_known_customer' => (bool) $conversation->user,
            'customer'          => $conversation->user ? [
                'name'  => trim(($conversation->user->first_name ?? '') . ' ' . ($conversation->user->last_name ?? '')),
                'email' => $conversation->user->email,
            ] : null,
            'history' => $conversation->messages()
                ->latest('id')->limit(15)->get()
                ->sortBy('id')->values()
                ->map(fn (WhatsappMessage $m) => [
                    'sender' => $m->sender,
                    'body'   => $m->body,
                    'at'     => $m->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * GET /integrations/n8n/knowledge
     * Base de conocimiento (documentación publicada) para el RAG del LLM.
     */
    public function knowledge(): JsonResponse
    {
        $docs = Documentation::query()
            ->where('is_published', true)
            ->orderBy('category')
            ->orderBy('title')
            ->get(['title', 'category', 'content'])
            ->map(fn (Documentation $d) => [
                'title'    => $d->title,
                'category' => $d->category,
                'content'  => Str::limit(strip_tags((string) $d->content), 4000, ''),
            ]);

        return response()->json(['success' => true, 'data' => $docs]);
    }

    /**
     * POST /integrations/n8n/whatsapp/reply
     * Registra en el hilo la respuesta que n8n envió por WhatsApp (bot o agente),
     * para que el panel de soporte tenga el historial completo.
     */
    public function reply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_uuid' => ['required', 'uuid'],
            'body'              => ['required', 'string'],
            'sender'            => ['nullable', 'in:bot,agent'],
            'wa_message_id'     => ['nullable', 'string', 'max:255'],
            'meta'              => ['nullable', 'array'],
        ]);

        $conversation = WhatsappConversation::where('uuid', $data['conversation_uuid'])->firstOrFail();

        $conversation->messages()->create([
            'direction'     => WhatsappMessage::DIRECTION_OUTBOUND,
            'sender'        => $data['sender'] ?? WhatsappMessage::SENDER_BOT,
            'body'          => $data['body'],
            'wa_message_id' => $data['wa_message_id'] ?? null,
            'meta'          => $data['meta'] ?? null,
        ]);
        $conversation->update(['last_message_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * POST /integrations/n8n/whatsapp/handoff
     * Escala la conversación a un humano: marca el hilo como 'human', crea un
     * ticket (si el contacto es un cliente conocido) y deja registro para soporte.
     */
    public function handoff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_uuid' => ['required', 'uuid'],
            'reason'            => ['nullable', 'string', 'max:1000'],
            'subject'           => ['nullable', 'string', 'max:255'],
        ]);

        $conversation = WhatsappConversation::where('uuid', $data['conversation_uuid'])->firstOrFail();
        $conversation->loadMissing('user');

        $ticket = null;

        // Solo creamos ticket si el contacto está vinculado a un usuario (los
        // tickets requieren user_id). Si no, queda como conversación 'human'
        // pendiente que el agente puede atender desde el panel de WhatsApp.
        if ($conversation->user && ! $conversation->ticket_id) {
            $ticket = Ticket::create([
                'uuid'          => (string) Str::uuid(),
                'user_id'       => $conversation->user->id,
                'ticket_number' => 'WA-' . strtoupper(Str::random(8)),
                'subject'       => $data['subject'] ?? 'Soporte por WhatsApp',
                'description'   => $data['reason'] ?? 'Conversación de WhatsApp escalada a un agente.',
                'priority'      => 'medium',
                'status'        => 'open',
                'category'      => 'general',
                'department'    => 'technical',
                'last_reply_at' => now(),
            ]);
        }

        $conversation->update([
            'status'    => WhatsappConversation::STATUS_HUMAN,
            'ticket_id' => $ticket?->id ?? $conversation->ticket_id,
        ]);

        $logContext = [
            'conversation_uuid' => $conversation->uuid,
            'wa_phone'          => $conversation->wa_phone,
            'ticket_id'         => $ticket?->id,
            'reason'            => $data['reason'] ?? null,
        ];

        // ActivityLog requiere user_id; solo lo registramos para clientes conocidos.
        if ($conversation->user) {
            ActivityLog::record(
                'WhatsApp escalado a agente',
                $data['reason'] ?? 'El bot no pudo resolver la consulta.',
                'support',
                $logContext,
                $conversation->user->id,
            );
        } else {
            \Illuminate\Support\Facades\Log::info('WhatsApp escalado a agente (contacto anónimo)', $logContext);
        }

        return response()->json([
            'success'        => true,
            'status'         => $conversation->status,
            'ticket_number'  => $ticket?->ticket_number,
            'ticket_created' => (bool) $ticket,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Deja solo dígitos (Meta manda E.164 sin '+'). */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: $phone;
    }

    /**
     * Intenta vincular el teléfono de WhatsApp con un usuario. Compara por los
     * últimos 10 dígitos para tolerar prefijos de país / formato distinto.
     */
    private function matchUserByPhone(string $phone): ?User
    {
        $last10 = substr($phone, -10);
        if (strlen($last10) < 10) {
            return null;
        }

        return User::whereNotNull('phone')
            ->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ["%{$last10}"])
            ->first();
    }
}
