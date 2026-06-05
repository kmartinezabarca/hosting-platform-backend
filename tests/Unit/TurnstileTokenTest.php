<?php

namespace Tests\Unit;

use App\Rules\TurnstileToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TurnstileTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.turnstile.secret', 'test-secret');
        Http::preventStrayRequests();
    }

    public function test_turnstile_token_passes_when_cloudflare_returns_success(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
            ]),
        ]);

        $request = Request::create('/api/contact', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);

        $validator = Validator::make([
            'cf-turnstile-response' => 'valid-token',
        ], [
            'cf-turnstile-response' => [new TurnstileToken($request)],
        ]);

        $this->assertTrue($validator->passes());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $request['secret'] === 'test-secret'
                && $request['response'] === 'valid-token'
                && $request['remoteip'] === '203.0.113.10';
        });
    }

    public function test_turnstile_token_fails_closed_on_network_errors(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Cloudflare unavailable');
        });

        $validator = Validator::make([
            'cf-turnstile-response' => 'valid-token',
        ], [
            'cf-turnstile-response' => [new TurnstileToken(Request::create('/api/contact'))],
        ]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('cf-turnstile-response', $validator->errors()->toArray());
    }
}
