<?php

namespace App\Domains\Pet\Services\Support;

use App\Domains\Pet\Models\ChatConversation;
use App\Domains\Pet\Models\ChatMessage;
use App\Domains\Pet\Models\KnowledgeArticle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquesta una respuesta del asistente de soporte de ROKE Pet.
 *
 * Reglas no negociables (ver especificación de soporte):
 *  - La IA sólo se llama desde el backend; la llave nunca sale del servidor.
 *  - Se enmascaran datos sensibles antes de enviarlos al modelo.
 *  - La IA responde sólo con base en la KB / conocimiento permitido.
 *  - No realiza acciones (cobros, cancelaciones, reembolsos): sólo informa y, si
 *    hace falta, escala a un humano.
 *  - Nunca se inventan respuestas falsas: si no hay IA disponible, se escala.
 */
class SupportAiService
{
    public function __construct(
        private readonly AnthropicClient $client,
        private readonly KnowledgeBaseRetriever $kb,
        private readonly SensitiveDataMasker $masker,
    ) {}

    /**
     * Genera (y persiste) la respuesta de la IA para el último mensaje del cliente.
     * No transmite eventos ni cambia el estado de la conversación: de eso se
     * encarga quien la invoca (GenerateAiReplyJob), para tener un único punto de
     * broadcasting y escalamiento.
     */
    public function respondTo(ChatConversation $conversation): AiTurnResult
    {
        $lastCustomer = $conversation->messages()
            ->where('sender_type', ChatMessage::SENDER_OWNER)
            ->latest('created_at')
            ->first();

        $customerText = $lastCustomer?->body ?? '';

        // 1) Pre-chequeo de temas sensibles: escalan antes de tocar la IA.
        if ($reason = $this->matchesForcedEscalation($customerText)) {
            return AiTurnResult::escalate($reason);
        }

        // 2) IA apagada o sin configurar -> no inventamos, escalamos.
        if (! config('anthropic.support.enabled', true) || ! $this->client->isConfigured()) {
            return AiTurnResult::escalate('ai_unavailable');
        }

        // 3) Recuperar contexto de la KB.
        $articles = $this->kb->search(
            $customerText,
            (int) config('anthropic.support.kb_top_k', 3),
            $conversation->brand,
        );

        try {
            $payload = $this->buildPayload($conversation, $articles);
            $response = $this->client->messages($payload);
            $parsed   = $this->parseStructured($response);
        } catch (Throwable $e) {
            // Log SIN secretos (sólo el mensaje de error y el id de conversación).
            Log::warning('SupportAiService: fallo al generar respuesta IA', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
            $conversation->forceFill(['ai_status' => 'failed'])->save();

            return AiTurnResult::escalate('ai_failed');
        }

        $threshold      = (float) config('anthropic.support.confidence_threshold', 0.55);
        $confidence     = (float) ($parsed['confidence'] ?? 0.0);
        $shouldEscalate = (bool) ($parsed['should_escalate'] ?? false) || $confidence < $threshold;
        $reason         = $shouldEscalate
            ? (filled($parsed['escalation_reason'] ?? null) ? (string) $parsed['escalation_reason'] : 'ai_low_confidence')
            : null;

        $sources = $articles->map(fn (KnowledgeArticle $a) => [
            'slug'  => $a->slug,
            'title' => $a->title,
        ])->all();

        return new AiTurnResult(
            reply: trim((string) ($parsed['reply'] ?? '')),
            confidence: $confidence,
            shouldEscalate: $shouldEscalate,
            escalationReason: $reason,
            sources: $sources,
        );
    }

    /** Devuelve un código de motivo si el texto dispara escalamiento forzado. */
    private function matchesForcedEscalation(string $text): ?string
    {
        $normalized = Str::lower(Str::ascii($text));
        foreach ((array) config('anthropic.support.force_escalation_keywords', []) as $kw) {
            $needle = Str::lower(Str::ascii($kw));
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return 'sensitive_topic';
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function buildPayload(ChatConversation $conversation, $articles): array
    {
        return [
            'model'      => config('anthropic.model'),
            'max_tokens' => (int) config('anthropic.max_tokens', 700),
            'system'     => $this->systemPrompt($articles),
            'messages'   => $this->history($conversation),
            'output_config' => [
                'format' => [
                    'type'   => 'json_schema',
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'reply'             => ['type' => 'string'],
                            'confidence'        => ['type' => 'number'],
                            'should_escalate'   => ['type' => 'boolean'],
                            'escalation_reason' => ['type' => 'string'],
                        ],
                        'required'             => ['reply', 'confidence', 'should_escalate', 'escalation_reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function parseStructured(array $response): array
    {
        $text = $this->client->firstText($response);
        $data = $text ? json_decode($text, true) : null;

        if (! is_array($data)) {
            // El modelo debería devolver JSON válido por el schema; si no, escalamos.
            throw new \RuntimeException('Respuesta de IA no parseable.');
        }

        return $data;
    }

    /**
     * Historial enmascarado para el modelo. Mapea emisores a roles del API:
     *  - pet_owner -> user, agent/ai -> assistant. Se omiten los de sistema.
     *
     * @return array<int, array<string, string>>
     */
    private function history(ChatConversation $conversation): array
    {
        $limit = (int) config('anthropic.support.history_limit', 12);

        $messages = $conversation->messages()
            ->whereIn('sender_type', [
                ChatMessage::SENDER_OWNER,
                ChatMessage::SENDER_AI,
                ChatMessage::SENDER_AGENT,
            ])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $out = [];
        foreach ($messages as $m) {
            $role = $m->sender_type === ChatMessage::SENDER_OWNER ? 'user' : 'assistant';
            $body = $this->masker->mask($m->body);
            if ($body === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $body];
        }

        // El API exige que el primer mensaje sea de 'user'.
        while (! empty($out) && $out[0]['role'] !== 'user') {
            array_shift($out);
        }

        if (empty($out)) {
            $out[] = ['role' => 'user', 'content' => 'Hola'];
        }

        return $out;
    }

    private function systemPrompt($articles): string
    {
        $kb = $articles->isEmpty()
            ? "No se encontraron artículos relevantes para esta consulta."
            : $articles->map(function (KnowledgeArticle $a) {
                $body = Str::limit(strip_tags((string) $a->content), 1200, '…');
                return "### [{$a->slug}] {$a->title}\n{$body}";
            })->implode("\n\n");

        return <<<PROMPT
Eres el asistente virtual de soporte de ROKE Pet, un servicio de identificación
digital para mascotas (perfiles con QR/NFC, modo extraviado, cartilla de salud y
suscripciones). Hablas español, con un tono cálido, cercano y empático — eres
amable y tranquilizador, sin ser técnico de más.

REGLAS:
- Preséntate como asistente virtual; NUNCA finjas ser una persona. Si te preguntan,
  aclara: "Soy el asistente de ROKE Pet. Puedo ayudarte con dudas generales y, si
  hace falta, te paso con una persona del equipo."
- Responde ÚNICAMENTE con base en la información de la BASE DE CONOCIMIENTO de abajo
  y en conocimiento general seguro del producto. Si la respuesta no está ahí o no
  estás seguro, NO inventes: marca should_escalate=true.
- Da respuestas claras y breves. Haz una sola pregunta a la vez si necesitas datos.
- NO inventes precios; no des diagnósticos médicos, ni consejo legal o fiscal.
- NO realices acciones (no canceles cuentas, no modifiques suscripciones, no
  proceses reembolsos) ni afirmes que hiciste algo que no hiciste.
- Escala a un humano (should_escalate=true) cuando: el usuario lo pida, haya un
  problema de pago/suscripción/cobro, problema de acceso a la cuenta, mascota
  perdida con urgencia, tema médico/de salud, tema legal/fiscal, reembolso o
  cancelación, reporte de error, enojo o frustración, o cualquier acción que
  requiera permisos de administrador.
- Ofrece escalar cuando sea apropiado, con amabilidad.

FORMATO DE SALIDA (obligatorio): devuelve un único objeto JSON con:
- reply: tu respuesta para el cliente, en español, lista para mostrar.
- confidence: número entre 0 y 1 con tu confianza en que la respuesta es correcta
  y suficiente con la información disponible.
- should_escalate: true/false según las reglas de arriba.
- escalation_reason: motivo breve si should_escalate=true, o "" si es false.

BASE DE CONOCIMIENTO:
{$kb}
PROMPT;
    }
}
