<?php

namespace Tests\Unit;

use App\Domains\Platform\Services\Hestia\HestiaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HestiaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hestia.base_url', 'https://hestia.test:8083');
        config()->set('hestia.admin_user', 'admin');
        config()->set('hestia.admin_password', 'secret');
        config()->set('hestia.hash', null);
        config()->set('hestia.access_key', null);
        config()->set('hestia.secret_key', null);
    }

    public function test_execute_commands_use_hestia_form_payload(): void
    {
        Http::fake([
            'https://hestia.test:8083/api/' => Http::response('0', 200),
        ]);

        app(HestiaService::class)->deleteUser('kmartinez');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hestia.test:8083/api/'
                && $request['user'] === 'admin'
                && $request['password'] === 'secret'
                && $request['returncode'] === 'yes'
                && $request['cmd'] === 'v-delete-user'
                && $request['arg1'] === 'kmartinez';
        });
    }

    public function test_list_commands_request_json_output(): void
    {
        Http::fake([
            'https://hestia.test:8083/api/' => Http::response('{"example.com":{"IP":"100.94.93.51"}}', 200),
        ]);

        $domains = app(HestiaService::class)->listDomains('kmartinez');

        $this->assertSame('100.94.93.51', $domains['example.com']['IP']);

        Http::assertSent(function ($request) {
            return $request['returncode'] === 'no'
                && $request['cmd'] === 'v-list-web-domains'
                && $request['arg1'] === 'kmartinez'
                && $request['arg2'] === 'json';
        });
    }

    public function test_hash_auth_is_used_when_configured(): void
    {
        config()->set('hestia.hash', 'access:secret');

        Http::fake([
            'https://hestia.test:8083/api/' => Http::response('0', 200),
        ]);

        app(HestiaService::class)->suspendUser('kmartinez');

        Http::assertSent(function ($request) {
            return $request['hash'] === 'access:secret'
                && !$request->hasHeader('Authorization')
                && !isset($request['user'])
                && !isset($request['password']);
        });
    }
}
