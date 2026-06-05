<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeployRefreshCommand extends Command
{
    protected $signature = 'deploy:refresh
        {--migrate : Run database migrations before caching}
        {--no-cache : Keep Laravel bootstrap caches cleared}
        {--skip-restarts : Do not signal queue/Reverb restarts}';

    protected $description = 'Refresh Laravel deploy state: clear stale caches, optionally migrate, rebuild caches, and restart realtime workers.';

    public function handle(): int
    {
        $this->info('Refreshing deploy state...');

        $this->runRequired('optimize:clear');
        $this->runRequired('cache:clear');
        $this->runRequired('config:clear');
        $this->runRequired('route:clear');
        $this->runRequired('view:clear');
        $this->runOptional('event:clear');
        $this->runOptional('schedule:clear-cache');
        $this->runRequired('package:discover', ['--ansi' => true]);

        if ($this->option('migrate')) {
            $this->runOptional('migrate', [
                '--force' => true,
                '--no-interaction' => true,
                '--graceful' => true,
            ]);
        }

        if (! $this->option('no-cache')) {
            $this->runRequired('config:cache');
            $this->runRequired('route:cache');
            $this->runOptional('event:cache');
            $this->runRequired('view:cache');
        }

        if (! $this->option('skip-restarts')) {
            $this->runOptional('queue:restart');
            $this->runOptional('reverb:restart');
        }

        $this->info('Deploy state refreshed.');

        return self::SUCCESS;
    }

    private function runRequired(string $command, array $arguments = []): void
    {
        $this->line("→ php artisan {$command}");

        $exitCode = $this->call($command, $arguments);
        if ($exitCode !== self::SUCCESS) {
            throw new \RuntimeException("Command {$command} failed with exit code {$exitCode}.");
        }
    }

    private function runOptional(string $command, array $arguments = []): void
    {
        $this->line("→ php artisan {$command}");

        try {
            $exitCode = $this->call($command, $arguments);
            if ($exitCode !== self::SUCCESS) {
                $this->warn("Command {$command} returned exit code {$exitCode}; continuing.");
            }
        } catch (\Throwable $exception) {
            $this->warn("Command {$command} skipped: {$exception->getMessage()}");
        }
    }
}
