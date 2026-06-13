<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\ApiRequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PruneApiRequestLogs extends Command
{
    protected $signature = 'api-logs:prune
        {--days= : Override API_REQUEST_LOG_RETENTION_DAYS}
        {--dry-run : Show how many rows would be deleted}';

    protected $description = 'Delete old API request logs using the configured retention window.';

    public function handle(): int
    {
        if (! Schema::hasTable('api_request_logs')) {
            $this->warn('api_request_logs table does not exist yet; nothing to prune.');

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?: config('api_logging.retention_days', 90));

        if ($days < 1) {
            $this->error('Retention days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = ApiRequestLog::where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("[DRY-RUN] {$count} api_request_logs older than {$cutoff->toDateTimeString()} would be deleted.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} api_request_logs older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
