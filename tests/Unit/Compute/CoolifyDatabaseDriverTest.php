<?php

namespace Tests\Unit\Compute;

use App\Domains\Platform\Compute\Providers\Coolify\CoolifyDatabaseDriver;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * connectionInfo() NO debe inventar host/credenciales: si Coolify devuelve un
 * shape inesperado, falla ruidoso (no produce una conexión plausible-pero-rota
 * en silencio). Se valida con Http::fake — sin Coolify vivo.
 */
class CoolifyDatabaseDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'coolify.base_url'    => 'https://coolify.test',
            'coolify.api_token'   => 'test-token',
            'coolify.server_uuid' => 'srv-1',
        ]);
    }

    public function test_parses_a_valid_mysql_response(): void
    {
        Http::fake(['*' => Http::response([
            'internal_db_host' => 'db-abc.internal',
            'mysql_database'   => 'app',
            'mysql_user'       => 'appuser',
            'mysql_password'   => 's3cr3t',
            'mysql_port'       => 3306,
        ])]);

        $conn = (new CoolifyDatabaseDriver())->connectionInfo('db-abc');

        $this->assertSame('db-abc.internal', $conn['host']);
        $this->assertSame(3306, $conn['port']);
        $this->assertSame('app', $conn['database']);
        $this->assertSame('appuser', $conn['username']);
        $this->assertSame('s3cr3t', $conn['password']);
    }

    public function test_redis_response_needs_no_database_or_username(): void
    {
        Http::fake(['*' => Http::response([
            'internal_db_host' => 'redis-abc.internal',
            'redis_password'   => 'rpw',
        ])]);

        $conn = (new CoolifyDatabaseDriver())->connectionInfo('redis-abc');

        $this->assertSame('redis-abc.internal', $conn['host']);
        $this->assertSame(6379, $conn['port']); // default por engine
        $this->assertSame('', $conn['database']);
        $this->assertSame('rpw', $conn['password']);
    }

    public function test_throws_with_received_keys_when_a_field_is_missing(): void
    {
        // Falta la contraseña → debe fallar, NO inventar una vacía.
        Http::fake(['*' => Http::response([
            'internal_db_host' => 'db-abc.internal',
            'mysql_database'   => 'app',
            'mysql_user'       => 'appuser',
        ])]);

        try {
            (new CoolifyDatabaseDriver())->connectionInfo('db-abc');
            $this->fail('Se esperaba una excepción por shape inesperado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('password', $e->getMessage());
            // El mensaje incluye las claves recibidas para diagnosticar el shape real.
            $this->assertStringContainsString('mysql_user', $e->getMessage());
        }
    }

    public function test_throws_when_host_is_absent(): void
    {
        Http::fake(['*' => Http::response([
            'mysql_database' => 'app',
            'mysql_user'     => 'appuser',
            'mysql_password' => 's3cr3t',
        ])]);

        $this->expectException(RuntimeException::class);
        (new CoolifyDatabaseDriver())->connectionInfo('db-abc');
    }
}
