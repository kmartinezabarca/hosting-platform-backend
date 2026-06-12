<?php

namespace Tests\Unit\Compute;

use App\Domains\Platform\Compute\Detection\ArrayRepoFiles;
use App\Domains\Platform\Compute\Detection\DetectionEngine;
use Tests\TestCase;

class DetectionEngineTest extends TestCase
{
    private DetectionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DetectionEngine();
    }

    public function test_detects_laravel_with_artisan(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'composer.json' => json_encode([
                'require' => ['php' => '^8.2', 'laravel/framework' => '^11.0'],
            ]),
            'artisan'       => '#!/usr/bin/env php',
            '.env.example'  => 'APP_KEY=',
            'package.json'  => json_encode(['scripts' => ['build' => 'vite build']]),
        ]));

        $this->assertSame('laravel', $result['framework']);
        $this->assertSame(0.98, $result['confidence']);
        $this->assertSame('8.2', $result['runtime_version']);
        $this->assertSame('mysql', $result['needs']['database']);
        $this->assertTrue($result['needs']['queue_worker']);
        $this->assertContains('npm ci && npm run build', $result['build']['commands']);
        $this->assertSame('laravel_key', $result['env_template'][0]['generate']);
    }

    public function test_laravel_without_env_example_warns(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^11.0']]),
            'artisan'       => '',
        ]));

        $this->assertNotEmpty($result['warnings']);
    }

    public function test_detects_nextjs_server_mode(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'package.json' => json_encode([
                'dependencies' => ['next' => '^15.0.0', 'react' => '^19'],
                'scripts'      => ['start' => 'next start', 'build' => 'next build'],
                'engines'      => ['node' => '>=20'],
            ]),
        ]));

        $this->assertSame('nextjs', $result['framework']);
        $this->assertSame('app', $result['kind']);
        $this->assertSame('20', $result['runtime_version']);
        $this->assertSame(3000, $result['run']['port']);
    }

    public function test_nextjs_static_export_is_static_site(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'package.json'   => json_encode(['dependencies' => ['next' => '^15.0.0']]),
            'next.config.js' => "module.exports = { output: 'export' }",
        ]));

        $this->assertSame('nextjs', $result['framework']);
        $this->assertSame('static_site', $result['kind']);
    }

    public function test_nextjs_beats_generic_node(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'package.json' => json_encode([
                'dependencies' => ['next' => '^15.0.0'],
                'scripts'      => ['start' => 'next start'],
            ]),
        ]));

        $this->assertSame('nextjs', $result['framework']);
    }

    public function test_detects_generic_node(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'package.json' => json_encode([
                'dependencies' => ['express' => '^4'],
                'scripts'      => ['start' => 'node index.js'],
            ]),
        ]));

        $this->assertSame('node', $result['framework']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_detects_static_site(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'index.html' => '<html></html>',
            'style.css'  => '',
        ]));

        $this->assertSame('static', $result['framework']);
        $this->assertSame('static_site', $result['kind']);
    }

    public function test_compose_beats_everything(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'docker-compose.yml' => 'services: {}',
            'composer.json'      => json_encode(['require' => ['laravel/framework' => '^11.0']]),
            'artisan'            => '',
        ]));

        $this->assertSame('compose', $result['framework']);
        $this->assertSame('compose', $result['kind']);
    }

    public function test_dockerfile_overrides_build_method_keeping_framework(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^11.0']]),
            'artisan'       => '',
            'Dockerfile'    => 'FROM php:8.3-fpm',
        ]));

        $this->assertSame('laravel', $result['framework']);
        $this->assertSame('dockerfile', $result['build']['method']);
        $this->assertSame('mysql', $result['needs']['database']); // needs se conservan
    }

    public function test_dockerfile_alone_is_docker_app(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'Dockerfile' => 'FROM alpine',
        ]));

        $this->assertSame('docker', $result['framework']);
        $this->assertSame('dockerfile', $result['build']['method']);
    }

    public function test_unrecognizable_repo_returns_null(): void
    {
        $this->assertNull($this->engine->detect(new ArrayRepoFiles([
            'README.md' => '# hola',
        ])));
    }

    public function test_multiple_lockfiles_warn(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'package.json'      => json_encode(['scripts' => ['start' => 'node i.js']]),
            'package-lock.json' => '{}',
            'pnpm-lock.yaml'    => '',
        ]));

        $this->assertStringContainsString('lockfiles', implode(' ', $result['warnings']));
    }

    // ── WordPress (mes 3) ──────────────────────────────────────────────────

    public function test_detects_classic_wordpress(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'wp-config.php' => "<?php define('DB_NAME', 'wp');",
            'wp-load.php'   => '<?php',
            'index.php'     => '<?php',
        ]));

        $this->assertSame('wordpress', $result['framework']);
        $this->assertSame('app', $result['kind']);
        $this->assertSame('php', $result['language']);
        $this->assertSame('mysql', $result['needs']['database']);
        // Clásico: sin binds engañosos, pero avisa que wp-config fija credenciales.
        $this->assertSame([], $result['env_template']);
        $this->assertStringContainsString('wp-config', implode(' ', $result['warnings']));
    }

    public function test_detects_wordpress_from_wp_content_dir(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'wp-content/themes/mi-tema/style.css' => '/* Theme */',
            'index.php'                           => '<?php',
        ]));

        $this->assertSame('wordpress', $result['framework']);
    }

    public function test_detects_bedrock_wordpress_with_env_bindings(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'composer.json' => json_encode([
                'require' => ['php' => '^8.2', 'roots/wordpress' => '^6.5'],
            ]),
            'web/index.php'         => '<?php',
            'config/application.php' => '<?php',
        ]));

        $this->assertSame('wordpress', $result['framework']);
        $this->assertSame('8.2', $result['runtime_version']);
        $this->assertSame('web', $result['run']['root']); // docroot Bedrock

        $binds = collect($result['env_template'])->keyBy('key');
        $this->assertSame('database.password', $binds['DB_PASSWORD']['bind']);
        $this->assertSame('production', $binds['WP_ENV']['value']);
        // Los 8 salts de WordPress se generan.
        $this->assertSame('wp_salt', $binds['AUTH_KEY']['generate']);
        $this->assertCount(8, collect($result['env_template'])->where('generate', 'wp_salt'));
    }

    public function test_wordpress_beats_generic_node_for_theme_repo(): void
    {
        // Un WP clásico con package.json para compilar el tema → gana WordPress.
        $result = $this->engine->detect(new ArrayRepoFiles([
            'wp-settings.php' => '<?php',
            'package.json'    => json_encode(['scripts' => ['start' => 'node x.js', 'build' => 'webpack']]),
        ]));

        $this->assertSame('wordpress', $result['framework']);
    }

    public function test_compose_beats_wordpress(): void
    {
        $result = $this->engine->detect(new ArrayRepoFiles([
            'docker-compose.yml' => 'services: {}',
            'wp-config.php'      => '<?php',
        ]));

        $this->assertSame('compose', $result['framework']);
    }
}
