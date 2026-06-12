<?php

namespace Tests\Unit\Ai;

use App\Domains\Platform\Ai\Troubleshooting\FailureClassifier;
use Tests\TestCase;

class FailureClassifierTest extends TestCase
{
    private FailureClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new FailureClassifier();
    }

    /** @dataProvider logSamples */
    public function test_classifies_known_failures(string $log, string $expectedTaxon): void
    {
        $this->assertSame($expectedTaxon, $this->classifier->classify($log)['taxon']);
    }

    public static function logSamples(): array
    {
        return [
            'app key'        => ['RuntimeException: No application encryption key has been specified.', 'missing_app_key'],
            'composer'       => ['Your requirements could not be resolved to an installable set of packages.', 'composer_dep_conflict'],
            'npm'            => ["npm ERR! code ERESOLVE\nnpm ERR! ERESOLVE unable to resolve dependency tree", 'npm_dep_error'],
            'node version'   => ['error something@1.0.0: The engine "node" is incompatible with this module.', 'node_version_mismatch'],
            'oom'            => ['FATAL ERROR: Reached heap limit Allocation failed - JavaScript heap out of memory', 'build_oom'],
            'oom exit 137'   => ['process exited with exit code: 137', 'build_oom'],
            'migración'      => ["SQLSTATE[HY000] [2002] Connection refused", 'migration_failed'],
            'puerto'         => ['Error: listen EADDRINUSE: address already in use :::3000', 'port_in_use'],
            'dockerfile'     => ['ERROR: failed to solve: process "/bin/sh -c composer install" did not complete', 'dockerfile_error'],
            'healthcheck'    => ['container my-app is unhealthy after 5 retries', 'healthcheck_timeout'],
            'disco'          => ['write /var/lib/docker: no space left on device', 'disk_full'],
            'git auth'       => ["remote: Repository not found\nfatal: could not read from remote repository", 'git_auth_failed'],
            'module'         => ["Error: Cannot find module 'express'", 'module_not_found'],
            'python'         => ['ERROR: Could not find a version that satisfies the requirement flask', 'python_dep_error'],
            'go'             => ['go: example.com/x: no required module provides package example.com/x', 'go_build_error'],
            'nixpacks'       => ['Nixpacks was unable to generate a build plan for this app.', 'nixpacks_no_plan'],
            'timeout'        => ['the build exceeded its limit: build timed out', 'build_timeout'],
            'permiso'        => ['sh: ./build.sh: EACCES: permission denied', 'permission_denied'],
            'desconocido'    => ['some random output without recognizable signature', 'unknown'],
        ];
    }

    public function test_classification_includes_cause_and_fixes(): void
    {
        $result = $this->classifier->classify('npm ERR! peer dep missing');

        $this->assertNotEmpty($result['cause']);
        $this->assertNotEmpty($result['fixes']);
    }

    public function test_error_window_returns_tail(): void
    {
        $logs = str_repeat('a', 5000) . 'THE_END';

        $window = $this->classifier->errorWindow($logs, 100);

        $this->assertSame(100, mb_strlen($window));
        $this->assertStringEndsWith('THE_END', $window);
    }
}
