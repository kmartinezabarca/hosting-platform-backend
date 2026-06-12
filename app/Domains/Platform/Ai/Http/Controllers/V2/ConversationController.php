<?php

namespace App\Domains\Platform\Ai\Http\Controllers\V2;

use App\Domains\Platform\Ai\AgentRunner;
use App\Domains\Platform\Ai\Models\AiAction;
use App\Domains\Platform\Ai\Models\AiConversation;
use App\Domains\Platform\Ai\ToolRegistry;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Http\Controllers\Controller;
use App\Support\Anthropic\AnthropicClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * POST /api/v2/ai/conversations — crea conversación con contexto opcional.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'project'  => ['nullable', 'uuid'],
            'resource' => ['nullable', 'uuid'],
        ]);

        $context = [];

        // El contexto solo se acepta si el usuario puede ver ese objeto —
        // misma policy que la API normal.
        if ($uuid = $request->input('resource')) {
            $resource = Resource::where('uuid', $uuid)->first();
            abort_unless($resource && $request->user()->can('view', $resource), 403);
            $context['resource'] = $uuid;
        }

        if ($uuid = $request->input('project')) {
            $project = Project::where('uuid', $uuid)->first();
            abort_unless($project && $request->user()->can('view', $project), 403);
            $context['project'] = $uuid;
        }

        $conversation = AiConversation::create([
            'user_id' => $request->user()->id,
            'context' => $context ?: null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['uuid' => $conversation->uuid],
        ], 201);
    }

    /**
     * POST /api/v2/ai/conversations/{conversation}/messages
     */
    public function message(Request $request, AiConversation $conversation, AgentRunner $runner, AnthropicClient $client): JsonResponse
    {
        abort_unless((int) $conversation->user_id === (int) $request->user()->id, 403);

        if (! config('anthropic.agent.enabled') || ! $client->isConfigured()) {
            abort(503, 'El asistente de IA no está disponible en este momento.');
        }

        $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $reply = $runner->run($conversation, $request->input('message'));

        return response()->json([
            'success' => true,
            'data'    => [
                'reply'      => $reply->content,
                'tool_calls' => collect($reply->tool_calls ?? [])
                    ->map(fn ($t) => ['tool' => $t['tool'], 'ok' => $t['ok']])
                    ->values(),
                // Acciones que el agente propuso en este turno: el panel las
                // renderiza con botones confirmar/rechazar.
                'actions'    => AiAction::where('message_id', $reply->id)
                    ->where('status', 'proposed')
                    ->get()
                    ->map(fn (AiAction $a) => $this->transformAction($a))
                    ->values(),
            ],
        ]);
    }

    /**
     * POST /api/v2/ai/conversations/{conversation}/actions/{action}/confirm
     *
     * Ejecuta una acción propuesta. La autorización se re-verifica dentro de la
     * herramienta (policy operate) — defensa en profundidad sobre la propuesta.
     */
    public function confirmAction(Request $request, AiConversation $conversation, AiAction $action, ToolRegistry $tools): JsonResponse
    {
        $this->guardAction($request, $conversation, $action);

        $result = $tools->execute($request->user(), $action->tool, $action->arguments);

        $failed = isset($result['error']);

        $action->update([
            'status'       => $failed ? 'failed' : 'executed',
            'confirmed_at' => now(),
            'executed_at'  => now(),
            'result'       => $result,
        ]);

        return response()->json([
            'success' => ! $failed,
            'data'    => $this->transformAction($action->fresh()),
            'result'  => $result,
        ], $failed ? 422 : 200);
    }

    /**
     * POST /api/v2/ai/conversations/{conversation}/actions/{action}/reject
     */
    public function rejectAction(Request $request, AiConversation $conversation, AiAction $action): JsonResponse
    {
        $this->guardAction($request, $conversation, $action);

        $action->update(['status' => 'rejected']);

        return response()->json([
            'success' => true,
            'data'    => $this->transformAction($action->fresh()),
        ]);
    }

    /** Dueño de la conversación + acción pendiente que le pertenece. */
    private function guardAction(Request $request, AiConversation $conversation, AiAction $action): void
    {
        abort_unless((int) $conversation->user_id === (int) $request->user()->id, 403);
        abort_if((int) $action->conversation_id !== (int) $conversation->id, 404);
        abort_unless($action->isPending(), 409, 'Esta acción ya fue resuelta.');
    }

    private function transformAction(AiAction $action): array
    {
        return [
            'uuid'    => $action->uuid,
            'tool'    => $action->tool,
            'risk'    => $action->risk,
            'summary' => $action->summary,
            'status'  => $action->status,
        ];
    }

    /**
     * GET /api/v2/ai/conversations/{conversation}
     */
    public function show(Request $request, AiConversation $conversation): JsonResponse
    {
        abort_unless((int) $conversation->user_id === (int) $request->user()->id, 403);

        return response()->json([
            'success' => true,
            'data'    => [
                'uuid'     => $conversation->uuid,
                'context'  => $conversation->context,
                'messages' => $conversation->messages()->orderBy('id')->get()->map(fn ($m) => [
                    'role'       => $m->role,
                    'content'    => $m->content,
                    'tool_calls' => collect($m->tool_calls ?? [])->map(fn ($t) => $t['tool'])->values(),
                    'created_at' => $m->created_at,
                ]),
                // Acciones aún pendientes de confirmar en toda la conversación.
                'pending_actions' => AiAction::where('conversation_id', $conversation->id)
                    ->where('status', 'proposed')
                    ->get()
                    ->map(fn (AiAction $a) => $this->transformAction($a))
                    ->values(),
            ],
        ]);
    }
}
