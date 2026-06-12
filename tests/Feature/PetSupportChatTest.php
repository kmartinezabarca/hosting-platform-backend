<?php

namespace Tests\Feature;

use App\Domains\Pet\Jobs\GenerateAiReplyJob;
use App\Domains\Pet\Events\ChatAgentJoined;
use App\Domains\Pet\Events\ChatMessageRead;
use App\Domains\Pet\Events\ChatMessageSent;
use App\Domains\Pet\Events\ChatUserTyping;
use App\Domains\Pet\Models\AppAdmin;
use App\Domains\Pet\Models\ChatConversation;
use App\Domains\Pet\Models\ChatMessage;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Services\Support\ChatService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Chat de soporte de ROKE Pet: aislamiento por dueño, almacenamiento de mensajes
 * de IA, parada de la IA tras toma humana, escalamiento y separación por marca.
 *
 * Nota: el dominio Pet vive en la conexión roke_pet (en CI = misma BD). Como esa
 * conexión no participa del rollback de RefreshDatabase, limpiamos sus tablas en
 * setUp(). Sin ANTHROPIC_API_KEY en el entorno de test, la IA NUNCA inventa: cada
 * turno escala a un humano — eso es justo lo que validan varios casos.
 */
class PetSupportChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::connection('roke_pet')->hasTable('pet_chat_conversations')) {
            Artisan::call('migrate', [
                '--path'     => 'database/migrations/roke_pet',
                '--database' => 'roke_pet',
                '--force'    => true,
            ]);
        }

        ChatMessage::query()->delete();
        ChatConversation::query()->delete();
        AppAdmin::query()->delete();
    }

    private function owner(User $user): Owner
    {
        return Owner::firstOrCreate(
            ['id' => $user->uuid],
            ['display_name' => 'Dueño Test', 'email' => $user->email],
        );
    }

    private function conversationFor(string $ownerUuid, array $overrides = []): ChatConversation
    {
        return ChatConversation::create(array_merge([
            'brand'      => 'roke_pet',
            'channel'    => 'pet_app',
            'source'     => 'pet_app',
            'owner_id'   => $ownerUuid,
            'status'     => ChatConversation::STATUS_AI_ACTIVE,
            'ai_enabled' => true,
            'ai_status'  => 'enabled',
        ], $overrides));
    }

    /** El dueño puede crear una conversación y enviar un mensaje. */
    public function test_owner_can_start_a_conversation_and_send_a_message(): void
    {
        $user = User::factory()->create();
        $this->owner($user);

        $this->actingAs($user)
            ->postJson('/api/rp/chat/conversation', ['message' => 'Hola, una duda general'])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('pet_chat_conversations', ['owner_id' => $user->uuid], 'roke_pet');
        $this->assertTrue(
            ChatMessage::where('sender_type', ChatMessage::SENDER_OWNER)->where('body', 'Hola, una duda general')->exists(),
        );
    }

    /** Un dueño NO puede leer la conversación de otro dueño. */
    public function test_owner_cannot_read_another_owners_conversation(): void
    {
        $user = User::factory()->create();
        $this->owner($user);

        $other = $this->conversationFor((string) Str::uuid()); // de otro dueño

        $this->actingAs($user)
            ->getJson("/api/rp/chat/conversations/{$other->id}/messages")
            ->assertForbidden();
    }

    /** Pedir "hablar con una persona" cambia el estado a waiting_agent. */
    public function test_owner_escalation_changes_status_to_waiting_agent(): void
    {
        $user = User::factory()->create();
        $this->owner($user);
        $conv = $this->conversationFor($user->uuid);

        $this->actingAs($user)
            ->postJson("/api/rp/chat/conversations/{$conv->id}/escalate")
            ->assertOk();

        $this->assertSame(ChatConversation::STATUS_WAITING_AGENT, $conv->refresh()->status);
    }

    /** Mientras esté en modo IA, enviar un mensaje dispara el job de respuesta IA. */
    public function test_owner_message_in_ai_mode_dispatches_ai_job(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $this->owner($user);
        $conv = $this->conversationFor($user->uuid);

        $this->actingAs($user)
            ->postJson("/api/rp/chat/conversations/{$conv->id}/messages", ['message' => '¿Cómo registro a mi mascota?'])
            ->assertCreated();

        Bus::assertDispatchedSync(GenerateAiReplyJob::class);
    }

    /** Tras la toma humana, la IA deja de auto-responder (no se dispara el job). */
    public function test_ai_stops_after_human_takeover(): void
    {
        $user = User::factory()->create();
        $this->owner($user);
        $conv = $this->conversationFor($user->uuid);

        // Un agente toma la conversación.
        app(ChatService::class)->agentTakeover($conv, 'agent-123', 'Agente Soporte');
        $conv->refresh();

        $this->assertSame(ChatConversation::STATUS_HUMAN_ACTIVE, $conv->status);
        $this->assertFalse((bool) $conv->ai_enabled);
        $this->assertFalse($conv->aiShouldAutoReply());

        // Ahora el dueño escribe: la IA NO debe dispararse.
        Bus::fake();
        $this->actingAs($user)
            ->postJson("/api/rp/chat/conversations/{$conv->id}/messages", ['message' => 'Sigo aquí'])
            ->assertCreated();

        Bus::assertNotDispatchedSync(GenerateAiReplyJob::class);
    }

    /** Un mensaje de IA se almacena con sender_type = ai. */
    public function test_ai_message_is_stored_with_sender_type_ai(): void
    {
        $conv = $this->conversationFor((string) Str::uuid());

        app(ChatService::class)->postMessage($conv, [
            'sender_type'   => ChatMessage::SENDER_AI,
            'sender_name'   => 'Asistente ROKE Pet',
            'body'          => 'Con gusto te ayudo con eso.',
            'ai_confidence' => 0.9,
        ], broadcast: false);

        $this->assertDatabaseHas('pet_chat_messages', [
            'conversation_id' => $conv->id,
            'sender_type'     => ChatMessage::SENDER_AI,
        ], 'roke_pet');
    }

    /** Toma humana: el agente queda asignado y el estado pasa a human_active. */
    public function test_agent_takeover_assigns_agent_and_disables_ai(): void
    {
        $conv = $this->conversationFor((string) Str::uuid());

        app(ChatService::class)->agentTakeover($conv, 'agent-9', 'Ana');
        $conv->refresh();

        $this->assertSame('agent-9', $conv->assigned_agent_id);
        $this->assertSame('disabled', $conv->ai_status);
        // Quedó un mensaje de sistema "se unió a la conversación".
        $this->assertTrue(
            ChatMessage::where('conversation_id', $conv->id)
                ->where('sender_type', ChatMessage::SENDER_SYSTEM)
                ->where('body', 'like', '%se unió a la conversación%')
                ->exists(),
        );
    }

    /** Las consultas por marca aíslan ROKE Pet de otras marcas. */
    public function test_brand_scope_separates_conversations(): void
    {
        $this->conversationFor((string) Str::uuid(), ['brand' => 'roke_pet']);
        $this->conversationFor((string) Str::uuid(), ['brand' => 'roke_industries']);

        $this->assertSame(1, ChatConversation::forBrand('roke_pet')->count());
        $this->assertSame(1, ChatConversation::forBrand('roke_industries')->count());
    }

    /** Un admin de Pet puede listar las conversaciones del chat. */
    public function test_admin_can_list_pet_conversations(): void
    {
        $user = User::factory()->create();
        $this->owner($user);
        AppAdmin::firstOrCreate(['user_id' => $user->uuid]);

        $this->conversationFor((string) Str::uuid());

        $this->actingAs($user)
            ->getJson('/api/rp/admin/chat/conversations')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /** Un agente respondiendo desde admin emite eventos para el hilo del dueño. */
    public function test_admin_reply_dispatches_realtime_events_for_owner_thread(): void
    {
        Event::fake([ChatAgentJoined::class, ChatMessageSent::class]);

        $owner = User::factory()->create();
        $this->owner($owner);
        $admin = User::factory()->create();
        AppAdmin::firstOrCreate(['user_id' => $admin->uuid]);
        $conv = $this->conversationFor($owner->uuid, [
            'status'    => ChatConversation::STATUS_WAITING_AGENT,
            'ai_status' => 'escalated',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/rp/admin/chat/conversations/{$conv->id}/messages", ['message' => 'Hola, ya estoy contigo.'])
            ->assertCreated()
            ->assertJsonPath('data.sender_type', ChatMessage::SENDER_AGENT);

        Event::assertDispatched(ChatAgentJoined::class, fn (ChatAgentJoined $event) =>
            $event->conversation->id === $conv->id
        );
        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $event) =>
            $event->conversation->id === $conv->id
                && $event->message->sender_type === ChatMessage::SENDER_AGENT
                && $event->message->body === 'Hola, ya estoy contigo.'
        );
    }

    /** Las señales de escritura del admin viajan por Reverb al hilo compartido. */
    public function test_admin_typing_dispatches_realtime_event(): void
    {
        Event::fake([ChatUserTyping::class]);

        $owner = User::factory()->create();
        $this->owner($owner);
        $admin = User::factory()->create();
        AppAdmin::firstOrCreate(['user_id' => $admin->uuid]);
        $conv = $this->conversationFor($owner->uuid);

        $this->actingAs($admin)
            ->postJson("/api/rp/admin/chat/conversations/{$conv->id}/typing", ['is_typing' => true])
            ->assertOk();

        Event::assertDispatched(ChatUserTyping::class, fn (ChatUserTyping $event) =>
            $event->conversation->id === $conv->id
                && $event->senderType === ChatMessage::SENDER_AGENT
                && $event->isTyping === true
        );
    }

    /** Cuando el dueño lee la respuesta del agente, el recibo se emite en vivo. */
    public function test_owner_read_dispatches_realtime_receipt_for_agent_messages(): void
    {
        $owner = User::factory()->create();
        $this->owner($owner);
        $conv = $this->conversationFor($owner->uuid, [
            'status'           => ChatConversation::STATUS_HUMAN_ACTIVE,
            'ai_enabled'       => false,
            'unread_for_owner' => 1,
        ]);
        $message = ChatMessage::create([
            'conversation_id' => $conv->id,
            'sender_type'     => ChatMessage::SENDER_AGENT,
            'sender_name'     => 'Soporte',
            'body'            => 'Respuesta humana',
            'message_type'    => ChatMessage::TYPE_TEXT,
            'delivered_at'    => now(),
        ]);

        Event::fake([ChatMessageRead::class]);

        $this->actingAs($owner)
            ->postJson("/api/rp/chat/conversations/{$conv->id}/read")
            ->assertOk();

        Event::assertDispatched(ChatMessageRead::class, fn (ChatMessageRead $event) =>
            $event->conversation->id === $conv->id
                && $event->reader === 'owner'
                && in_array($message->id, $event->messageIds, true)
        );
    }
}
