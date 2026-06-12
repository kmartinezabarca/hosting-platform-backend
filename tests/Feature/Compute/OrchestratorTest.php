<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Orchestrator\Flow;
use App\Domains\Platform\Compute\Orchestrator\FlowRegistry;
use App\Domains\Platform\Compute\Orchestrator\OrchestrationService;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingState::reset();
        FlowRegistry::register(TestFlow::key(), TestFlow::class);
    }

    protected function tearDown(): void
    {
        FlowRegistry::flush();
        parent::tearDown();
    }

    private function start(): Orchestration
    {
        // Con QUEUE_CONNECTION=sync la saga corre completa en línea.
        return app(OrchestrationService::class)->start(
            TestFlow::key(),
            Resource::factory()->create(),
            context: ['hello' => 'world'],
        );
    }

    public function test_runs_steps_in_order_and_completes(): void
    {
        $orchestration = $this->start()->fresh();

        $this->assertNotNull($orchestration->completed_at);
        $this->assertNull($orchestration->failed_at);
        $this->assertSame(['step_a', 'step_b', 'step_b', 'step_c'], RecordingState::$executions);
        $this->assertSame(['done', 'done', 'done'], array_column($orchestration->steps, 'status'));
    }

    public function test_pending_step_is_retried_not_skipped(): void
    {
        $this->start();

        // step_b devolvió pending la primera vez → debe ejecutarse 2 veces.
        $this->assertSame(2, collect(RecordingState::$executions)->filter(fn ($s) => $s === 'step_b')->count());
    }

    public function test_context_is_shared_between_steps(): void
    {
        $this->start();

        $this->assertSame('world', RecordingState::$seenContext);
    }

    public function test_failing_step_marks_orchestration_failed_and_skips_rest(): void
    {
        RecordingState::$failOnB = true;

        $orchestration = $this->start()->fresh();

        $this->assertNotNull($orchestration->failed_at);
        $this->assertStringContainsString('boom', $orchestration->last_error);
        $this->assertNotContains('step_c', RecordingState::$executions);
        $this->assertSame('failed', $orchestration->steps[1]['status']);
        $this->assertTrue(RecordingState::$onFailureCalled);
    }
}

// ── Soporte: flujo y pasos de prueba ─────────────────────────────────────────

class RecordingState
{
    public static array $executions = [];
    public static bool $failOnB = false;
    public static bool $onFailureCalled = false;
    public static mixed $seenContext = null;
    private static array $pendingServed = [];

    public static function reset(): void
    {
        self::$executions = [];
        self::$failOnB = false;
        self::$onFailureCalled = false;
        self::$seenContext = null;
        self::$pendingServed = [];
    }

    public static function servePendingOnce(string $key): bool
    {
        if (isset(self::$pendingServed[$key])) {
            return false;
        }
        self::$pendingServed[$key] = true;

        return true;
    }
}

class StepA implements Step
{
    public function execute(Orchestration $orchestration): StepResult
    {
        RecordingState::$executions[] = 'step_a';
        RecordingState::$seenContext  = $orchestration->getContext('hello');

        return StepResult::completed();
    }
}

class StepB implements Step
{
    public function execute(Orchestration $orchestration): StepResult
    {
        RecordingState::$executions[] = 'step_b';

        if (RecordingState::$failOnB) {
            throw new \RuntimeException('boom');
        }

        // Primera pasada: pendiente (simula polling); segunda: completa.
        if (RecordingState::servePendingOnce('step_b:' . $orchestration->id)) {
            return StepResult::pending(1);
        }

        return StepResult::completed();
    }
}

class StepC implements Step
{
    public function execute(Orchestration $orchestration): StepResult
    {
        RecordingState::$executions[] = 'step_c';

        return StepResult::completed();
    }
}

class TestFlow extends Flow
{
    public static function key(): string
    {
        return 'test_flow';
    }

    public function steps(): array
    {
        return [StepA::class, StepB::class, StepC::class];
    }

    public function onFailure(Orchestration $orchestration, \Throwable $e): void
    {
        RecordingState::$onFailureCalled = true;
    }
}
