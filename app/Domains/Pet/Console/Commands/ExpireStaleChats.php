<?php

namespace App\Domains\Pet\Console\Commands;

use App\Domains\Pet\Models\ChatConversation;
use App\Domains\Pet\Services\Support\ChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cierra (reinicia) las conversaciones de soporte de ROKE Pet que llevan más de
 * 24h sin actividad. El chat no es eterno: tras la ventana se auto-cierra y el
 * dueño puede iniciar una nueva conversación.
 *
 * Programado en app/Console/Kernel.php (hourly). Idempotente.
 */
class ExpireStaleChats extends Command
{
    protected $signature = 'rokepet:expire-stale-chats {--dry-run : Mostrar qué se cerraría sin aplicar cambios}';

    protected $description = 'Cierra conversaciones de soporte de ROKE Pet inactivas por más de 24 horas.';

    public function __construct(private readonly ChatService $chat)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count  = 0;

        ChatConversation::query()
            ->stale()
            ->chunkById(100, function ($conversations) use ($dryRun, &$count) {
                foreach ($conversations as $conversation) {
                    $this->line(" → Cerrando conversación {$conversation->id} (última actividad: {$conversation->lastActivityAt()->diffForHumans()})");

                    if ($dryRun) {
                        $count++;
                        continue;
                    }

                    try {
                        $this->chat->autoExpire($conversation);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::error('rokepet:expire-stale-chats: error cerrando conversación', [
                            'conversation_id' => $conversation->id,
                            'error'           => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Conversaciones inactivas cerradas: {$count}");

        return self::SUCCESS;
    }
}
