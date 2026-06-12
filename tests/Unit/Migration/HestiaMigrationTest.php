<?php

namespace Tests\Unit\Migration;

use App\Domains\Platform\Migration\Hestia\HestiaBackupParser;
use App\Domains\Platform\Migration\Hestia\MigrationPlanner;
use Tests\TestCase;

class HestiaMigrationTest extends TestCase
{
    private function sampleExport(): array
    {
        return [
            'web_domains' => [
                'app.com' => [
                    'DOCUMENT_ROOT' => '/home/u/web/app.com/public_html',
                    'ALIAS'         => 'www.app.com,cdn.app.com',
                    'SSL'           => 'yes',
                    'BACKEND'       => 'PHP-8_1',
                    'SUSPENDED'     => 'no',
                ],
                'static.com' => [
                    'DOCUMENT_ROOT' => '/home/u/web/static.com/public_html',
                    'ALIAS'         => '',
                    'SSL'           => 'no',
                    'BACKEND'       => '',
                    'SUSPENDED'     => 'no',
                ],
            ],
            'databases' => [
                'u_blog' => ['DBUSER' => 'u_bloguser', 'TYPE' => 'mysql', 'SUSPENDED' => 'no'],
                'u_pg'   => ['DBUSER' => 'u_pguser',   'TYPE' => 'pgsql', 'SUSPENDED' => 'no'],
            ],
        ];
    }

    public function test_parser_normalizes_web_domains_and_databases(): void
    {
        $parsed = (new HestiaBackupParser())->parse($this->sampleExport());

        $this->assertCount(2, $parsed['web_domains']);

        $app = $parsed['web_domains'][0];
        $this->assertSame('app.com', $app->domain);
        $this->assertSame('8.1', $app->phpVersion);
        $this->assertTrue($app->isPhp());
        $this->assertSame(['www.app.com', 'cdn.app.com'], $app->aliases);
        $this->assertTrue($app->ssl);

        $static = $parsed['web_domains'][1];
        $this->assertNull($static->phpVersion);
        $this->assertFalse($static->isPhp());

        $this->assertSame('mysql', $parsed['databases'][0]->engine);
        $this->assertSame('postgres', $parsed['databases'][1]->engine);
    }

    public function test_parser_ignores_non_array_rows(): void
    {
        $parsed = (new HestiaBackupParser())->parse([
            'web_domains' => ['bad' => 'not-an-array'],
            'databases'   => 'nope',
        ]);

        $this->assertCount(0, $parsed['web_domains']);
        $this->assertCount(0, $parsed['databases']);
    }

    public function test_planner_maps_kinds_by_heuristic(): void
    {
        $parsed = (new HestiaBackupParser())->parse($this->sampleExport());
        $plan   = (new MigrationPlanner())->plan($parsed);

        $this->assertSame(2, $plan['summary']['web_domains']);
        $this->assertSame(4, $plan['summary']['resources_planned']); // 2 web + 2 db

        $kinds = array_column($plan['resources'], 'kind');
        $this->assertContains('app', $kinds);          // PHP
        $this->assertContains('static_site', $kinds);  // sin backend
        $this->assertContains('database', $kinds);
    }

    public function test_planner_skips_suspended_with_warning(): void
    {
        $parsed = (new HestiaBackupParser())->parse([
            'web_domains' => ['s.com' => ['BACKEND' => 'PHP-8_2', 'SUSPENDED' => 'yes']],
            'databases'   => [],
        ]);
        $plan = (new MigrationPlanner())->plan($parsed);

        $this->assertSame(0, $plan['summary']['resources_planned']);
        $this->assertNotEmpty($plan['warnings']);
    }
}
