<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Yosymfony\Toml\Toml;

class FrpService
{
    private string $host;
    private string $user;
    private string $configPath;
    private string $sshOptions;

    public function __construct()
    {
        $this->host        = config('frp.host');
        $this->user        = config('frp.user');
        $this->configPath  = config('frp.config_path');
        $this->sshOptions  = config('frp.ssh_options');
    }

    /* =========================================================
     | ADD PROXY (SAFE)
     ========================================================= */
    public function addTcpProxy(int $port, string $name): bool
    {
        return Cache::lock("frp-port-{$port}", 10)->block(5, function () use ($port, $name) {

            $proxyName = $this->buildProxyName($port);

            $config = $this->getRemoteConfig();

            $proxies = $config['proxies'] ?? [];

            // evitar duplicados
            foreach ($proxies as $p) {
                if (($p['name'] ?? null) === $proxyName) {
                    Log::info('FRP already exists', compact('proxyName'));
                    return true;
                }
            }

            $proxies[] = $this->buildProxyArray($proxyName, $port);

            $config['proxies'] = $proxies;

            return $this->pushConfig($config, $proxyName, $port);
        });
    }

    /* =========================================================
     | REMOVE PROXY (SAFE)
     ========================================================= */
    public function removeTcpProxy(int $port): bool
    {
        return Cache::lock("frp-port-{$port}", 10)->block(5, function () use ($port) {

            $proxyName = $this->buildProxyName($port);

            $config = $this->getRemoteConfig();

            $proxies = array_filter(
                $config['proxies'] ?? [],
                fn ($p) => ($p['name'] ?? null) !== $proxyName
            );

            $config['proxies'] = array_values($proxies);

            return $this->pushConfig($config, $proxyName, $port, false);
        });
    }

    /* =========================================================
     | READ REMOTE CONFIG
     ========================================================= */
    private function getRemoteConfig(): array
    {
        $process = $this->sshProcess([
            "cat {$this->configPath}"
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Cannot read FRP config: ' . $process->getErrorOutput());
        }

        return Toml::parse($process->getOutput());
    }

    /* =========================================================
     | PUSH CONFIG BACK
     ========================================================= */
    private function pushConfig(array $config, string $proxyName, int $port, bool $isAdd = true): bool
    {
        $toml = $this->arrayToToml($config);

        $escaped = str_replace("'", "'\\''", $toml);

        $commands = [
            "echo '{$escaped}' | sudo tee {$this->configPath} > /dev/null",
            "sudo systemctl reload frpc || sudo systemctl restart frpc",
        ];

        $process = $this->sshProcess($commands);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error('FRP sync failed', [
                'proxy' => $proxyName,
                'error' => $process->getErrorOutput(),
            ]);

            return false;
        }

        Log::info($isAdd ? 'FRP added' : 'FRP removed', [
            'proxy' => $proxyName,
            'port'  => $port,
        ]);

        return true;
    }

    /* =========================================================
     | BUILD PROXY
     ========================================================= */
    private function buildProxyArray(string $proxyName, int $port): array
    {
        return [
            'name'       => $proxyName,
            'type'       => 'tcp',
            'localIP'    => '100.94.93.51',
            'localPort'  => $port,
            'remotePort' => $port,
        ];
    }

    private function buildProxyName(int $port): string
    {
        return "mc-{$port}";
    }

    /* =========================================================
     | ARRAY -> TOML (simple safe serializer)
     ========================================================= */
    private function arrayToToml(array $config): string
    {
        $output = "";

        foreach ($config['proxies'] ?? [] as $proxy) {
            $output .= "\n[[proxies]]\n";
            foreach ($proxy as $k => $v) {
                $output .= "{$k} = " . (is_numeric($v) ? $v : "\"{$v}\"") . "\n";
            }
        }

        return $output;
    }

    /* =========================================================
     | SSH
     ========================================================= */
    private function sshProcess(array $commands): Process
    {
        $command = implode(' && ', $commands);

        return Process::fromShellCommandline(
            "ssh {$this->sshOptions} {$this->user}@{$this->host} '{$command}'"
        );
    }
}
