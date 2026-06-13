<?php

namespace Tests\Feature\SiteBuilder;

use App\Domains\Platform\SiteBuilder\Contracts\PageGeneratorProvider;
use App\Domains\Platform\SiteBuilder\Data\GeneratedPage as GeneratedPageResult;
use App\Domains\Platform\SiteBuilder\Data\PageSpec;
use App\Domains\Platform\SiteBuilder\Models\GeneratedPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint de generación de páginas (síncrono): genera, PERSISTE y devuelve.
 * Usa un provider fake en el contenedor (agnóstico por env) + cubre el
 * historial (listar/ver/borrar) con scoping por dueño.
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
    private function fakeProvider(?GeneratedPageResult $page, bool $configured = true, ?\Throwable $throws = null): void
    {
        $this->app->instance(PageGeneratorProvider::class, new class($page, $configured, $throws) implements PageGeneratorProvider {
            public function __construct(
                private ?GeneratedPageResult $page,
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

            public function generate(PageSpec $spec): GeneratedPageResult
            {
                if ($this->throws) {
                    throw $this->throws;
                }

                return $this->page;
            }
        });
    }

    private function sampleResult(): GeneratedPageResult
    {
        return new GeneratedPageResult(
            html: '<!DOCTYPE html><html><head><title>Mi Café</title></head><body>hola</body></html>',
            title: 'Mi Café',
            provider: 'fake',
            model: 'fake-1',
            warnings: [],
        );
    }

    private function makePage(User $user, string $title = 'Página'): GeneratedPage
    {
        return GeneratedPage::create([
            'user_id'  => $user->id,
            'prompt'   => 'algo',
            'locale'   => 'es',
            'status'   => 'ready',
            'title'    => $title,
            'html'     => '<html><body>x</body></html>',
            'provider' => 'fake',
            'model'    => 'fake-1',
        ]);
    }

    public function test_generates_and_persists_a_page(): void
    {
        $this->fakeProvider($this->sampleResult());

        $this->actingAs($this->user)->postJson('/api/v2/site-builder/generate', [
            'prompt'    => 'una landing para mi cafetería',
            'site_name' => 'Mi Café',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Mi Café')
            ->assertJsonPath('data.provider', 'fake')
            ->assertJsonPath('data.status', 'ready');

        $this->assertDatabaseHas('generated_pages', [
            'user_id'  => $this->user->id,
            'title'    => 'Mi Café',
            'provider' => 'fake',
        ]);
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

        $this->actingAs($this->user)->postJson('/api/v2/site-builder/generate', ['prompt' => 'algo'])
            ->assertStatus(503);

        $this->assertDatabaseCount('generated_pages', 0); // no persiste si no hay proveedor
    }

    public function test_provider_failure_is_reported_and_not_persisted(): void
    {
        $this->fakeProvider(null, throws: new \RuntimeException('Ollama no respondió'));

        $this->actingAs($this->user)->postJson('/api/v2/site-builder/generate', ['prompt' => 'algo'])
            ->assertStatus(502)
            ->assertJsonFragment(['message' => 'Ollama no respondió']);

        $this->assertDatabaseCount('generated_pages', 0); // falla ruidoso, nada guardado
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v2/site-builder/generate', ['prompt' => 'x'])
            ->assertUnauthorized();
    }

    public function test_lists_only_own_pages_without_html(): void
    {
        $this->makePage($this->user, 'Mía 1');
        $this->makePage($this->user, 'Mía 2');
        $this->makePage(User::factory()->create(['status' => 'active']), 'Ajena');

        $data = $this->actingAs($this->user)->getJson('/api/v2/site-builder/pages')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
        $this->assertArrayNotHasKey('html', $data[0]); // el listado no trae el HTML
    }

    public function test_show_returns_html_for_owner_and_403_for_others(): void
    {
        $page = $this->makePage($this->user, 'Mi página');

        $this->actingAs($this->user)->getJson("/api/v2/site-builder/pages/{$page->uuid}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Mi página')
            ->assertJsonPath('data.html', '<html><body>x</body></html>');

        $stranger = User::factory()->create(['status' => 'active']);
        $this->actingAs($stranger)->getJson("/api/v2/site-builder/pages/{$page->uuid}")
            ->assertForbidden();
    }

    public function test_destroy_removes_own_page(): void
    {
        $page = $this->makePage($this->user);

        $this->actingAs($this->user)->deleteJson("/api/v2/site-builder/pages/{$page->uuid}")
            ->assertOk();

        $this->assertDatabaseMissing('generated_pages', ['id' => $page->id]);
    }
}
