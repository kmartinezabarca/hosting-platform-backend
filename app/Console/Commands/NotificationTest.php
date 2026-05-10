<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\AdminDirect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class NotificationTest extends Command
{
    protected $signature = 'notify:test
        {email : Email del usuario a notificar}
        {--message= : Mensaje personalizado}
        {--skip-queue : Omitir el test de notificación encolada}';

    protected $description = 'Diagnostica el sistema de notificaciones y envía una prueba a un usuario';

    public function handle(): int
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════════╗');
        $this->line('║       DIAGNÓSTICO DEL SISTEMA DE NOTIFICACIONES      ║');
        $this->line('╚══════════════════════════════════════════════════════╝');
        $this->newLine();

        // ── 1. Diagnóstico del sistema ──────────────────────────────────────

        $this->info('1. Configuración del sistema:');
        $this->runDiagnostics();

        // ── 2. Buscar usuario ───────────────────────────────────────────────

        $this->newLine();
        $this->info('2. Buscando usuario...');

        $user = User::where('email', $this->argument('email'))->first();

        if (!$user) {
            $this->error("  ✗ Usuario no encontrado: {$this->argument('email')}");
            $this->newLine();
            $this->line('  Usuarios disponibles:');
            User::orderBy('email')->limit(10)->get()->each(fn($u) =>
                $this->line("    [{$u->role}] {$u->name} — {$u->email}")
            );
            return self::FAILURE;
        }

        $this->line("  ✓ Nombre:  <fg=green>{$user->name}</>");
        $this->line("    Email:   {$user->email}");
        $this->line("    UUID:    {$user->uuid}");
        $this->line("    Rol:     {$user->role}");

        $message = $this->option('message') ?: '🧪 Notificación de prueba — ' . now()->format('H:i:s');

        // ── 3. Inserción directa en DB (sin queue, sin broadcast) ───────────

        $this->newLine();
        $this->info('3. Test 1/3 — Inserción directa en tabla notifications:');

        try {
            DB::table('notifications')->insert([
                'id'              => (string) Str::uuid(),
                'type'            => AdminDirect::class,
                'notifiable_type' => User::class,
                'notifiable_id'   => $user->id,
                'data'            => json_encode([
                    'type'              => 'test',
                    'title'             => '🧪 Test directo DB',
                    'message'           => $message . ' [directo]',
                    'notification_type' => 'info',
                    'icon'              => 'bell',
                    'color'             => 'info',
                    'sent_by'           => 'notify:test',
                ]),
                'read_at'    => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $total = $user->notifications()->count();
            $this->line("  ✓ Guardado en DB. Total notificaciones del usuario: <fg=green>{$total}</>");
            $this->line("    Si la app consulta <fg=cyan>GET /api/notifications</>, esta ya debe aparecer.");
        } catch (\Exception $e) {
            $this->error("  ✗ Error al insertar: {$e->getMessage()}");
        }

        // ── 4. sendNow() — síncrono con broadcast ───────────────────────────

        $this->newLine();
        $this->info('4. Test 2/3 — Notification::sendNow() (síncrono, incluye broadcast):');

        try {
            $notifData = [
                'type'              => 'test',
                'title'             => '🧪 Test sendNow',
                'message'           => $message . ' [sendNow]',
                'notification_type' => 'success',
                'action_url'        => null,
                'action_text'       => null,
                'icon'              => 'check-circle',
                'color'             => 'success',
                'sent_by'           => 'notify:test',
            ];

            Notification::sendNow($user, new AdminDirect($notifData));

            $this->line('  ✓ sendNow() completado sin errores.');
            $this->line("    Canal broadcast: <fg=yellow>private-App.Models.User.{$user->uuid}</>");
            $this->line('    Si Reverb está corriendo y el frontend está conectado, debe llegar en tiempo real.');
        } catch (\Exception $e) {
            $this->error("  ✗ Error en sendNow(): {$e->getMessage()}");
            $this->line('    Causa probable:');
            $this->line('    • Reverb no está corriendo → <fg=cyan>php artisan reverb:start</>');
            $this->line('    • REVERB_APP_KEY incorrecto en .env');
        }

        // ── 5. notify() encolado ─────────────────────────────────────────────

        if (!$this->option('skip-queue')) {
            $this->newLine();
            $this->info('5. Test 3/3 — notify() encolado (requiere queue worker):');

            try {
                $notifData = [
                    'type'              => 'test',
                    'title'             => '🧪 Test Queue',
                    'message'           => $message . ' [queue]',
                    'notification_type' => 'warning',
                    'action_url'        => null,
                    'action_text'       => null,
                    'icon'              => 'clock',
                    'color'             => 'warning',
                    'sent_by'           => 'notify:test',
                ];

                $user->notify(new AdminDirect($notifData));

                $pending = $this->getQueueSize();
                $this->line("  ✓ Notificación encolada.");

                if ($pending > 0) {
                    $this->warn("    ⚠ Hay {$pending} jobs pendientes en el queue.");
                    $this->line('    ↳ El queue worker NO está corriendo o está detenido.');
                    $this->line('    ↳ Ejecuta: <fg=cyan>php artisan queue:work --tries=3 --sleep=3</>');
                } else {
                    $this->line('  ✓ Queue parece estar procesando (0 jobs pendientes).');
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Error al encolar: {$e->getMessage()}");
            }
        }

        // ── 6. Resumen ───────────────────────────────────────────────────────

        $this->newLine();
        $this->line('─────────────────────────────────────────────────────');
        $this->info('RESUMEN');
        $this->line('─────────────────────────────────────────────────────');

        $this->table(
            ['Componente', 'Estado', 'Comando'],
            [
                ['Reverb WebSocket', $this->checkReverb(), 'php artisan reverb:start'],
                ['Queue Worker',     $this->checkQueue(),  'php artisan queue:work --tries=3'],
            ]
        );

        $this->newLine();
        $this->comment('Canales que el frontend debe escuchar para este usuario:');
        $this->line("  <fg=yellow>private-App.Models.User.{$user->uuid}</>  ← notificaciones Laravel estándar");
        $this->line("  <fg=yellow>private-user.{$user->uuid}</>              ← canal personalizado del proyecto");

        $this->newLine();
        $this->comment('Verifica la conexión del frontend:');
        $this->line('  El cliente Echo debe incluir el token en el header de autenticación:');
        $this->line("  <fg=cyan>window.Echo = new Echo({ auth: { headers: { Authorization: 'Bearer TOKEN' } } })</>");

        $this->newLine();
        $this->comment('Últimas 5 notificaciones del usuario en DB:');
        $user->notifications()->latest()->limit(5)->get()->each(function ($n) {
            $data  = is_array($n->data) ? $n->data : json_decode($n->data, true);
            $read  = $n->read_at ? '<fg=gray>✓ leída</>' : '<fg=green>○ sin leer</>';
            $title = $data['title'] ?? '(sin título)';
            $this->line("  [{$read}] {$title}  <fg=gray>{$n->created_at->diffForHumans()}</>");
        });

        $this->newLine();

        return self::SUCCESS;
    }

    // ── Diagnóstico ───────────────────────────────────────────────────────────

    private function runDiagnostics(): void
    {
        $driver = config('broadcasting.default');
        $driverOk = $driver === 'reverb';
        $this->line('  ' . ($driverOk ? '✓' : '⚠') . " BROADCAST_DRIVER  = <fg=" . ($driverOk ? 'green' : 'yellow') . ">{$driver}</>");

        $queue = config('queue.default');
        $queueColor = $queue === 'sync' ? 'yellow' : 'green';
        $this->line("  ℹ QUEUE_CONNECTION = <fg={$queueColor}>{$queue}</>");
        if ($queue === 'sync') {
            $this->line('    ↳ En sync las notificaciones ShouldQueue se ejecutan inmediatamente (no necesitas queue worker).');
        }

        $reverbKey = config('reverb.apps.apps.0.key') ?? '';
        if ($reverbKey && !str_contains($reverbKey, 'your-')) {
            $this->line('  ✓ REVERB_APP_KEY   = configurado');
        } else {
            $this->error('  ✗ REVERB_APP_KEY   = no configurado (revisar .env)');
        }

        try {
            $notifCount = DB::table('notifications')->count();
            $this->line("  ✓ Tabla notifications existe ({$notifCount} registros)");
        } catch (\Exception $e) {
            $this->error('  ✗ Tabla notifications NO existe → php artisan migrate');
        }

        try {
            $cols    = DB::getSchemaBuilder()->getColumnListing('users');
            $missing = array_diff(['email_notifications', 'push_notifications'], $cols);
            if (empty($missing)) {
                $this->line('  ✓ Columnas de preferencias existen en users');
            } else {
                $this->warn('  ⚠ Faltan columnas en users: ' . implode(', ', $missing) . ' → php artisan migrate');
            }
        } catch (\Exception $e) {
            $this->warn('  ⚠ No se pudo verificar columnas de users');
        }
    }

    private function checkReverb(): string
    {
        $port = config('reverb.servers.reverb.port', 8080);
        $sock = @fsockopen('127.0.0.1', (int) $port, $errno, $errstr, 1);
        if ($sock) {
            fclose($sock);
            return "✓ Escuchando en :{$port}";
        }
        return "✗ NO está corriendo en :{$port}";
    }

    private function checkQueue(): string
    {
        try {
            $size = $this->getQueueSize();
            return $size > 0 ? "⚠ {$size} jobs pendientes (worker parado)" : '? Verificar manualmente';
        } catch (\Exception $e) {
            return '? No se pudo verificar';
        }
    }

    private function getQueueSize(): int
    {
        try {
            if (config('queue.default') === 'redis') {
                return (int) Redis::llen('queues:default');
            }
            if (config('queue.default') === 'database') {
                return DB::table('jobs')->where('queue', 'default')->count();
            }
        } catch (\Exception $e) { }
        return 0;
    }
}
