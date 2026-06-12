<?php

namespace App\Domains\Platform\Ai\Http\Controllers\V2;

use App\Domains\Platform\Ai\AgentRunner;
use App\Domains\Platform\Ai\Models\AiConversation;
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
            ],
        ]);
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
            ],
        ]);
    }
}
