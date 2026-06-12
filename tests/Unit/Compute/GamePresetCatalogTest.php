<?php

namespace Tests\Unit\Compute;

use App\Domains\Platform\Compute\Games\GamePresetCatalog;
use Tests\TestCase;

class GamePresetCatalogTest extends TestCase
{
    private GamePresetCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new GamePresetCatalog();
    }

    public function test_lists_presets_with_real_specs(): void
    {
        $slugs = array_column($this->catalog->all(), 'slug');

        $this->assertContains('fivem', $slugs);
        $this->assertContains('rust', $slugs);
        $this->assertContains('palworld', $slugs);

        $this->assertSame(30120, $this->catalog->forSlug('fivem')['default_port']);
        $this->assertSame(8211, $this->catalog->forSlug('palworld')['default_port']);
    }

    public function test_available_reflects_egg_and_nest_config(): void
    {
        config()->set('compute.game_presets.valheim.egg_id', null);
        config()->set('compute.game_presets.valheim.nest_id', null);
        $this->assertFalse($this->catalog->forSlug('valheim')['available']);

        config()->set('compute.game_presets.valheim.egg_id', '15');
        config()->set('compute.game_presets.valheim.nest_id', '3');
        $this->assertTrue($this->catalog->forSlug('valheim')['available']);
    }

    public function test_provider_ids_are_not_exposed(): void
    {
        config()->set('compute.game_presets.rust.egg_id', 'secret-egg');
        config()->set('compute.game_presets.rust.nest_id', 'secret-nest');

        $rust = $this->catalog->forSlug('rust');

        $this->assertArrayNotHasKey('egg_id', $rust);
        $this->assertArrayNotHasKey('nest_id', $rust);
        $this->assertTrue($rust['available']);
    }

    public function test_unknown_slug_returns_null(): void
    {
        $this->assertNull($this->catalog->forSlug('doesnotexist'));
        $this->assertFalse($this->catalog->exists('doesnotexist'));
    }
}
