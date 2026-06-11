<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Endpoints públicos de escritura deben estar rate-limited (anti-spam).
 */
class PublicEndpointThrottlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_blog_subscribe_is_throttled_after_five_requests(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $status = $this->postJson('/api/blog/subscribe', ['email' => "spam{$i}@example.com"])
                ->getStatusCode();
            $this->assertNotSame(429, $status, "Petición {$i} no debería estar limitada todavía.");
        }

        $this->postJson('/api/blog/subscribe', ['email' => 'spam6@example.com'])
            ->assertStatus(429);
    }

    public function test_documentation_requests_is_throttled_after_five_requests(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $status = $this->postJson('/api/documentation-requests', [
                'email' => "doc{$i}@example.com",
                'topic' => 'API',
            ])->getStatusCode();
            $this->assertNotSame(429, $status, "Petición {$i} no debería estar limitada todavía.");
        }

        $this->postJson('/api/documentation-requests', [
            'email' => 'doc6@example.com',
            'topic' => 'API',
        ])->assertStatus(429);
    }
}
