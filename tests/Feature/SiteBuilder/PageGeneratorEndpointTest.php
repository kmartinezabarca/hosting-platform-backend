<?php

namespace Tests\Feature\SiteBuilder;

use App\Domains\Platform\SiteBuilder\Contracts\PageGeneratorProvider;
use App\Domains\Platform\SiteBuilder\Data\GeneratedPage;
use App\Domains\Platform\SiteBuilder\Data\PageSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint de generación de páginas (síncrono). Usa un provider fake en el
 * contenedor: el endpoint no conoce el proveedor concreto (agnóstico por env).
 */
class PageGeneratorEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['status' => 'active']);
    }

    /** Sustituye el provider por un doble controlado. */
    private function fakeProvider(?GeneratedPage $page, bool $configured = true, ?\Throwable $throws = null): void
    {
        $this->app->instance(PageGeneratorProvider::class, new class($page, $configured, $throws) implements PageGeneratorProvider {
            public function __construct(
                private ?GeneratedPage $page,
                private bool $configured,
                private ?\Throwable $throws,
            ) {
            }

            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function generate(PageSpec $spec): GeneratedPage
            {
                if ($this->throws) {
                    throw $this->throws;
                }

                return $this->page;
            }
        });
    }

    public function test_generates_a_page(): void
    {
        $this->fakeProvider(new GeneratedPage(
            html: '<!DOCTYPE html><html><head><title>Mi Café</title></head><body>hola</body></html>',
            title: 'Mi Café',
            provider: 'fake',
            model: 'fake-1',
            warnings: [],
        ));

        $this->actingAs($this->user)->postJson('/api/v2/site-builder/generate', [
            'prompt'    => 'una landing para mi cafetería',
            'site_name' => 'Mi Café',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Mi Café')
            ->assertJsonPath('data.provider', 'fake');
    }

    public function test_requires_prompt(): void
    {
        $this->fakeProvider(null);

        $this->actingAs($this->user)->postJson('/api/v2/site-builder/generate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prompt');
    }

    public function test_503_when_provider_not_configured(): void
    {
        $this->fakeProvider(null, configured: false);

        $this->actingAs($this->user)->postJson('/api/v2/site-builder/generate', [
            'prompt' => 'algo',
        ])->assertStatus(503);
    }

    public function test_provider_failure_is_reported_not_faked(): void
    {
        $this->fakeProvider(null, throws: new \RuntimeException('Ollama no respondió'));

        $this->actingAs($this->user)->postJson('/api/v2/site-builder/generate', [
            'prompt' => 'algo',
        ])
            ->assertStatus(502)
            ->assertJsonFragment(['message' => 'Ollama no respondió']);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v2/site-builder/generate', ['prompt' => 'x'])
            ->assertUnauthorized();
    }
}
