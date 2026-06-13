<?php

namespace Tests\Unit\SiteBuilder;

use App\Domains\Platform\SiteBuilder\Data\PageSpec;
use App\Domains\Platform\SiteBuilder\Providers\ClaudePageGenerator;
use App\Domains\Platform\SiteBuilder\Providers\OllamaPageGenerator;
use App\Support\Anthropic\AnthropicClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Ambos providers cumplen el MISMO contrato (PageGeneratorProvider) y fallan
 * ruidoso ante respuestas inválidas (sin inventar HTML). Determinista: Ollama
 * con Http::fake, Claude con un doble del cliente Anthropic — sin red real.
 */
class PageGeneratorTest extends TestCase
{
    private const HTML = "<!DOCTYPE html><html><head><title>Café Luna</title></head><body><h1>Hola</h1></body></html>";

    private function spec(): PageSpec
    {
        return new PageSpec(prompt: 'una landing para mi cafetería', siteName: 'Café Luna');
    }

    // ── Ollama ──────────────────────────────────────────────────────────────

    private function ollamaConfigured(): void
    {
        config([
            'page_generator.ollama.base_url' => 'http://ryzen.test:11434',
            'page_generator.ollama.model'    => 'llama3.1',
            'page_generator.timeout'         => 30,
        ]);
    }

    public function test_ollama_generates_a_page(): void
    {
        $this->ollamaConfigured();
        Http::fake(['*' => Http::response(['message' => ['content' => self::HTML]])]);

        $page = (new OllamaPageGenerator())->generate($this->spec());

        $this->assertSame('ollama', $page->provider);
        $this->assertSame('llama3.1', $page->model);
        $this->assertSame('Café Luna', $page->title);
        $this->assertStringContainsString('<!DOCTYPE html>', $page->html);
        $this->assertSame([], $page->warnings);
    }

    public function test_ollama_strips_markdown_fences(): void
    {
        $this->ollamaConfigured();
        Http::fake(['*' => Http::response(['message' => ['content' => "```html\n" . self::HTML . "\n```"]])]);

        $page = (new OllamaPageGenerator())->generate($this->spec());

        $this->assertStringStartsWith('<!DOCTYPE html>', $page->html);
        $this->assertStringNotContainsString('```', $page->html);
    }

    public function test_ollama_not_configured_throws(): void
    {
        config(['page_generator.ollama.base_url' => '']);
        $gen = new OllamaPageGenerator();

        $this->assertFalse($gen->isConfigured());
        $this->expectException(RuntimeException::class);
        $gen->generate($this->spec());
    }

    public function test_ollama_fails_loud_on_http_error(): void
    {
        $this->ollamaConfigured();
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->expectException(RuntimeException::class);
        (new OllamaPageGenerator())->generate($this->spec());
    }

    public function test_ollama_fails_loud_when_not_html(): void
    {
        $this->ollamaConfigured();
        Http::fake(['*' => Http::response(['message' => ['content' => 'Claro, aquí tienes tu página.']])]);

        $this->expectException(RuntimeException::class);
        (new OllamaPageGenerator())->generate($this->spec());
    }

    // ── Claude (doble del AnthropicClient compartido) ─────────────────────────

    private function fakeAnthropic(string $reply, bool $configured = true): AnthropicClient
    {
        return new class($reply, $configured) extends AnthropicClient {
            public function __construct(private string $reply, private bool $configured)
            {
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function messages(array $payload): array
            {
                return ['content' => [['type' => 'text', 'text' => $this->reply]]];
            }
        };
    }

    public function test_claude_generates_a_page_reusing_shared_client(): void
    {
        config(['page_generator.claude.model' => 'claude-test', 'page_generator.claude.max_tokens' => 1000]);

        $page = (new ClaudePageGenerator($this->fakeAnthropic(self::HTML)))->generate($this->spec());

        $this->assertSame('claude', $page->provider);
        $this->assertSame('claude-test', $page->model);
        $this->assertSame('Café Luna', $page->title);
        $this->assertStringContainsString('<!DOCTYPE html>', $page->html);
    }

    public function test_claude_not_configured_throws(): void
    {
        $gen = new ClaudePageGenerator($this->fakeAnthropic('', configured: false));

        $this->assertFalse($gen->isConfigured());
        $this->expectException(RuntimeException::class);
        $gen->generate($this->spec());
    }

    public function test_claude_fails_loud_when_not_html(): void
    {
        $gen = new ClaudePageGenerator($this->fakeAnthropic('Aquí está tu sitio, listo.'));

        $this->expectException(RuntimeException::class);
        $gen->generate($this->spec());
    }
}
