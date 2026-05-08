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
        $this->host        = (string) config('frp.host', '100.94.93.51');
        $this->user        = (string) config('frp.user', 'rokeryzen');
        $this->configPath  = (string) config('frp.config_path', '/etc/frp/frpc.toml');
        $this->sshOptions  = (string) config('frp.ssh_options', '-o StrictHostKeyChecking=no -o ConnectTimeout=5');
    }

    /**
     * Sincroniza una lista completa de proxies (usado por el comando de consola).
     */
    public function sync(array $proxies): bool
    {
        try {
            $config = $this->getRemoteConfig();
            
            // Mantenemos la configuración global pero reemplazamos todos los proxies
            $config['proxies'] = array_map(function ($p) {
                return [
                    'name'       => $p['name'],
                    'type'       => $p['type'] ?? 'tcp',
                    'localIP'    => $p['localIP'] ?? '100.94.93.51',
                    'localPort'  => (int) $p['localPort'],
                    'remotePort' => (int) $p['remotePort'],
                ];
            }, $proxies);

            return $this->pushConfig($config, 'bulk-sync', 0);
        } catch (\Throwable $e) {
            Log::error('FRP Bulk Sync failed', ['error' => $e->getMessage()]);
            return false;
        }
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

        // Usamos Base64 para evitar problemas con caracteres especiales y saltos de línea en la terminal
        $base64 = base64_encode($toml);

        $commands = [
            "echo \"{$base64}\" | base64 -d | sudo tee {$this->configPath} > /dev/null",
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
     | ARRAY -> TOML (preserves global config)
     ========================================================= */
    private function arrayToToml(array $config): string
    {
        $output = "";

        // 1. Escribir configuración global (todo lo que no sea 'proxies')
        foreach ($config as $key => $value) {
            if ($key === 'proxies') continue;
            
            if (is_array($value)) {
                // Manejo simple de secciones anidadas como [auth]
                $output .= "\n[{$key}]\n";
                foreach ($value as $subKey => $subValue) {
                    $output .= "{$subKey} = " . $this->formatTomlValue($subValue) . "\n";
                }
            } else {
                $output .= "{$key} = " . $this->formatTomlValue($value) . "\n";
            }
        }

        // 2. Escribir proxies
        foreach ($config['proxies'] ?? [] as $proxy) {
            $output .= "\n[[proxies]]\n";
            foreach ($proxy as $k => $v) {
                $output .= "{$k} = " . $this->formatTomlValue($v) . "\n";
            }
        }

        return trim($output) . "\n";
    }

    private function formatTomlValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        return "\"{$value}\"";
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
