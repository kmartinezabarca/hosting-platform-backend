<?php

namespace Tests\Unit;

use App\Domains\Platform\Services\Coolify\HostingProvisioningService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Coolify valida los nombres de proyecto/app: solo letras (unicode), números,
 * espacios y - _ . / @ & ( ) # , : +  → cualquier otro carácter da HTTP 422 y
 * rompe el aprovisionamiento. Como el nombre lo elige el cliente, se sanitiza.
 */
class CoolifyNameSanitizerTest extends TestCase
{
    private function sanitize(string $name): string
    {
        $service = app(HostingProvisioningService::class);
        $method = new ReflectionMethod($service, 'sanitizeCoolifyName');
        $method->setAccessible(true);

        return $method->invoke($service, $name);
    }

    public function test_removes_characters_coolify_rejects(): void
    {
        // Guión largo, comillas tipográficas y emoji → fuera. Acentos, # y números se conservan.
        $this->assertSame('Mi Sitió Café 2024 #1', $this->sanitize('Mi Sitió — Café 2024 "#1" 🐶'));
    }

    public function test_keeps_allowed_punctuation(): void
    {
        $this->assertSame('web_app-1.0 (prod) @roke', $this->sanitize('web_app-1.0 (prod) @roke'));
    }

    public function test_collapses_whitespace_and_falls_back_when_empty(): void
    {
        $this->assertSame('Mi Sitio', $this->sanitize('Mi   Sitio'));
        $this->assertSame('Hosting', $this->sanitize('🐶🐱'));
        $this->assertSame('Hosting', $this->sanitize('   '));
    }

    public function test_truncates_long_names(): void
    {
        $this->assertSame(100, mb_strlen($this->sanitize(str_repeat('a', 200))));
    }
}
