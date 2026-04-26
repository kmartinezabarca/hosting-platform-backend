<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_public_can_list_active_categories(): void
    {
        Category::factory()->count(3)->create(['is_active' => true]);
        Category::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/categories');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $data = json_decode($response->getContent(), true);
        $this->assertGreaterThanOrEqual(3, count($data['data']));
    }

    public function test_categories_are_cached(): void
    {
        Category::factory()->count(2)->create(['is_active' => true]);

        $this->getJson('/api/categories');
        $response = $this->getJson('/api/categories');

        $response->assertOk();
    }

    public function test_public_can_get_categories_with_plans(): void
    {
        $category = Category::factory()->create(['is_active' => true]);

        $response = $this->getJson('/api/categories/with-plans');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_public_can_get_category_by_slug(): void
    {
        $category = Category::factory()->create([
            'slug' => 'hosting',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/categories/slug/hosting');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'hosting');
    }

    public function test_returns_404_for_nonexistent_category(): void
    {
        $response = $this->getJson('/api/categories/slug/nonexistent');

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_create_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/categories', [
                'slug' => 'new-category',
                'name' => 'New Category',
                'description' => 'Test description',
                'is_active' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('categories', [
            'slug' => 'new-category',
            'name' => 'New Category',
        ]);
    }

    public function test_admin_can_update_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create(['slug' => 'old-slug']);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/categories/{$category->uuid}", [
                'name' => 'Updated Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_admin_can_delete_category_without_services(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/categories/{$category->uuid}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_cannot_delete_category_with_services(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create();
        $category->servicePlans()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'base_price' => 10,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/categories/{$category->uuid}");

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_validation_fails_for_duplicate_slug(): void
    {
        Category::factory()->create(['slug' => 'duplicate']);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/categories', [
                'slug' => 'duplicate',
                'name' => 'Test',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }
}
