<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RotateAppKeyCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $oldKey;
    private string $newKey;

    protected function setUp(): void
    {
        parent::setUp();

        // La clave "vieja" es la activa del proceso de test (config app.key);
        // así los mutators del modelo escriben con ella, igual que en prod.
        $this->oldKey = config('app.key');
        $this->newKey = 'base64:' . base64_encode(random_bytes(32));

        putenv("OLD_APP_KEY={$this->oldKey}");
        putenv("NEW_APP_KEY={$this->newKey}");
        $_ENV['OLD_APP_KEY'] = $this->oldKey;
        $_ENV['NEW_APP_KEY'] = $this->newKey;
    }

    protected function tearDown(): void
    {
        putenv('OLD_APP_KEY');
        putenv('NEW_APP_KEY');
        unset($_ENV['OLD_APP_KEY'], $_ENV['NEW_APP_KEY']);

        parent::tearDown();
    }

    private function newEncrypter(): Encrypter
    {
        $key = str_starts_with($this->newKey, 'base64:')
            ? base64_decode(substr($this->newKey, 7))
            : $this->newKey;

        return new Encrypter($key, config('app.cipher', 'AES-256-CBC'));
    }

    private function makeServiceWithSecrets(array $secrets): Service
    {
        $user = User::factory()->create();
        $plan = ServicePlan::factory()->create();

        return Service::factory()->create([
            'user_id'            => $user->id,
            'plan_id'            => $plan->id,
            'connection_secrets' => $secrets,
        ]);
    }

    public function test_refuses_to_run_without_keys(): void
    {
        putenv('OLD_APP_KEY');
        putenv('NEW_APP_KEY');
        unset($_ENV['OLD_APP_KEY'], $_ENV['NEW_APP_KEY']);

        $this->artisan('security:rotate-app-key')->assertFailed();
    }

    public function test_dry_run_does_not_mutate(): void
    {
        $service = $this->makeServiceWithSecrets(['db_password' => 'super-secret-1']);
        $before  = DB::table('services')->where('id', $service->id)->value('connection_secrets');

        $this->artisan('security:rotate-app-key --dry-run')->assertSuccessful();

        $after = DB::table('services')->where('id', $service->id)->value('connection_secrets');
        $this->assertSame($before, $after);

        // Sigue legible con la clave actual (vieja).
        $this->assertSame(['db_password' => 'super-secret-1'], $service->fresh()->connection_secrets);
    }

    public function test_rotates_connection_secrets_to_new_key(): void
    {
        $service = $this->makeServiceWithSecrets(['db_password' => 'super-secret-2', 'db_user' => 'roke']);

        $this->artisan('security:rotate-app-key')->assertSuccessful();

        $raw = DB::table('services')->where('id', $service->id)->value('connection_secrets');

        // Legible con la clave NUEVA…
        $decrypted = json_decode($this->newEncrypter()->decryptString($raw), true);
        $this->assertSame('super-secret-2', $decrypted['db_password']);
        $this->assertSame('roke', $decrypted['db_user']);

        // …e ilegible con la clave vieja (el proceso actual).
        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        \Illuminate\Support\Facades\Crypt::decryptString($raw);
    }

    public function test_rotates_two_factor_secret_to_new_key(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        ]);

        $this->artisan('security:rotate-app-key')->assertSuccessful();

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertSame('JBSWY3DPEHPK3PXP', $this->newEncrypter()->decrypt($raw));
    }

    public function test_rotation_normalizes_legacy_plain_json_rows(): void
    {
        $service = $this->makeServiceWithSecrets(['db_password' => 'x']);

        // Simular fila legada guardada como JSON plano (pre-encriptación).
        DB::table('services')->where('id', $service->id)->update([
            'connection_secrets' => json_encode(['db_password' => 'legacy-plain']),
        ]);

        $this->artisan('security:rotate-app-key')->assertSuccessful();

        $raw       = DB::table('services')->where('id', $service->id)->value('connection_secrets');
        $decrypted = json_decode($this->newEncrypter()->decryptString($raw), true);

        $this->assertSame('legacy-plain', $decrypted['db_password']);
    }

    public function test_backup_contains_original_ciphertext_not_plaintext(): void
    {
        $service = $this->makeServiceWithSecrets(['db_password' => 'backup-secret']);
        $before  = DB::table('services')->where('id', $service->id)->value('connection_secrets');

        $backupPath = storage_path('app/test-key-rotation-backup.json');
        @unlink($backupPath);

        $this->artisan('security:rotate-app-key', ['--backup' => $backupPath])->assertSuccessful();

        $this->assertFileExists($backupPath);
        $backup = json_decode(file_get_contents($backupPath), true);

        $this->assertSame($before, $backup['services'][$service->id]);
        $this->assertStringNotContainsString('backup-secret', file_get_contents($backupPath));

        @unlink($backupPath);
    }
}
