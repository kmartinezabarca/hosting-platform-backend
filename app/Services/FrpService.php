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
    private string $localIp;
    private string $configPath;
    private string $serviceName;
    private string $sshOptions;

    public function __construct()
    {
        $this->host        = (string) config('frp.host', '100.94.93.51');
        $this->user        = (string) config('frp.user', 'rokeryzen');
        $this->localIp     = (string) config('frp.local_ip', '100.94.93.51');
        $this->configPath  = (string) config('frp.config_path', '/etc/frp/frpc.toml');
        $this->serviceName = (string) config('frp.service_name', 'frpc');
        $this->sshOptions  = (string) config('frp.ssh_options', '-o StrictHostKeyChecking=no -o ConnectTimeout=5');
    }

    /**
     * Sincroniza una lista completa de proxies.
     */
    public function sync(array $proxies): bool
    {
        try {
            $config = $this->getRemoteConfig();
            
            $config['proxies'] = array_map(function ($p) {
                return [
                    'name'       => $p['name'],
                    'type'       => $p['type'] ?? 'tcp',
                    'localIP'    => $p['localIP'] ?? $this->localIp,
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

    public function addTcpProxy(int $port, string $name): bool
    {
        return Cache::lock("frp-port-{$port}", 10)->block(5, function () use ($port, $name) {
            $proxyName = "mc-{$port}";
            $config = $this->getRemoteConfig();
            $proxies = $config['proxies'] ?? [];

            foreach ($proxies as $p) {
                if (($p['name'] ?? null) === $proxyName) return true;
            }

            $proxies[] = [
                'name'       => $proxyName,
                'type'       => 'tcp',
                'localIP'    => $this->localIp,
                'localPort'  => $port,
                'remotePort' => $port,
            ];

            $config['proxies'] = $proxies;
            return $this->pushConfig($config, $proxyName, $port);
        });
    }

    public function removeTcpProxy(int $port): bool
    {
        return Cache::lock("frp-port-{$port}", 10)->block(5, function () use ($port) {
            $proxyName = "mc-{$port}";
            $config = $this->getRemoteConfig();
            $proxies = array_filter($config['proxies'] ?? [], fn ($p) => ($p['name'] ?? null) !== $proxyName);
            $config['proxies'] = array_values($proxies);
            return $this->pushConfig($config, $proxyName, $port, false);
        });
    }

    private function getRemoteConfig(): array
    {
        $process = Process::fromShellCommandline("ssh {$this->sshOptions} {$this->user}@{$this->host} 'cat {$this->configPath}'");
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Cannot read FRP config: ' . $process->getErrorOutput());
        }

        return Toml::parse($process->getOutput());
    }

    private function pushConfig(array $config, string $proxyName, int $port, bool $isAdd = true): bool
    {
        $toml = $this->arrayToToml($config);
        $tempFile = tempnam(sys_get_temp_dir(), 'frp_');
        file_put_contents($tempFile, $toml);

        Log::debug("FRP: Archivo temporal local creado en {$tempFile}");

        try {
            // 1. Enviar archivo por SCP a una carpeta temporal del usuario remoto
            $remoteTemp = "/tmp/frpc_" . time() . ".toml";
            Log::debug("FRP: Enviando por SCP a {$this->user}@{$this->host}:{$remoteTemp}");
            
            $scp = Process::fromShellCommandline("scp {$this->sshOptions} {$tempFile} {$this->user}@{$this->host}:{$remoteTemp}");
            $scp->run();

            if (!$scp->isSuccessful()) {
                throw new \RuntimeException("SCP failed: " . $scp->getErrorOutput());
            }
            Log::debug("FRP: SCP completado con éxito.");

            // 2. Mover el archivo a su destino final con sudo y reiniciar frpc
            $commands = [
                "sudo tee {$this->configPath} < {$remoteTemp} > /dev/null",
                "rm {$remoteTemp}",
                "sudo systemctl restart {$this->serviceName}",
            ];
            
            Log::debug("FRP: Ejecutando comandos remotos: " . implode(' && ', $commands));
            
            $ssh = $this->sshProcess($commands);
            $ssh->run();

            if (!$ssh->isSuccessful()) {
                $error = $ssh->getErrorOutput() ?: $ssh->getOutput();
                throw new \RuntimeException("SSH Move/Reload failed: " . $error);
            }
            Log::debug("FRP: Comandos remotos ejecutados con éxito.");

            Log::info($isAdd ? 'FRP added' : 'FRP removed', ['proxy' => $proxyName, 'port' => $port]);
            return true;

        } catch (\Throwable $e) {
            Log::error('FRP sync failed', ['proxy' => $proxyName, 'error' => $e->getMessage()]);
            // Lanzamos la excepción para que el comando de consola la capture y la muestre
            throw $e;
        } finally {
            if (file_exists($tempFile)) unlink($tempFile);
        }
    }

    private function arrayToToml(array $config): string
    {
        $output = "";
        foreach ($config as $key => $value) {
            if ($key === 'proxies') continue;
            if (is_array($value)) {
                $output .= "\n[{$key}]\n";
                foreach ($value as $sk => $sv) $output .= "{$sk} = " . $this->formatTomlValue($sv) . "\n";
            } else {
                $output .= "{$key} = " . $this->formatTomlValue($value) . "\n";
            }
        }
        foreach ($config['proxies'] ?? [] as $proxy) {
            $output .= "\n[[proxies]]\n";
            foreach ($proxy as $k => $v) $output .= "{$k} = " . $this->formatTomlValue($v) . "\n";
        }
        return trim($output) . "\n";
    }

    private function formatTomlValue($v): string
    {
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_numeric($v)) return (string) $v;
        return "\"{$v}\"";
    }

    private function sshProcess(array $cmds): Process
    {
        $cmd = implode(' && ', $cmds);
        return Process::fromShellCommandline("ssh {$this->sshOptions} {$this->user}@{$this->host} " . escapeshellarg($cmd));
    }
}
