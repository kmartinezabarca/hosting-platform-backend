<?php

namespace App\Domains\Pet\Console\Commands;

use App\Domains\Pet\Jobs\SendCampaignJob;
use App\Domains\Pet\Models\NotificationCampaign;
use Illuminate\Console\Command;

/**
 * Recoge las campañas de notificación programadas cuya hora ya llegó
 * (status=scheduled, scheduled_at <= now) y despacha su fan-out.
 *
 * Programado en app/Console/Kernel.php (cada minuto).
 */
class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'rokepet:dispatch-scheduled-campaigns';

    protected $description = 'Despacha las campañas de notificación de ROKE Pet cuya fecha programada ya llegó.';

    public function handle(): int
    {
        $due = NotificationCampaign::due()->get();

        foreach ($due as $campaign) {
            SendCampaignJob::dispatch($campaign->id);
            $this->info("Campaña despachada: {$campaign->id} ({$campaign->title})");
        }

        $this->info("Campañas despachadas: {$due->count()}");

        return self::SUCCESS;
    }
}
