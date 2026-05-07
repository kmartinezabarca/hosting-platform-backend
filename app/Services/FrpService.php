<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class FrpService
{
    private string $frpcPath;
    private string $frpcConfig;
    private string $ryzenHost;
    private string $ryzenUser;

    public function __construct()
    {
        $this->frpcPath   = config('frp.frpc_path', '/etc/frp/frpc.toml');
        $this->ryzenHost  = config('frp.ryzen_host', '100.94.93.51');
        $this->ryzenUser  = config('frp.ryzen_user', 'rokeryzen');
    }

    public function addTcpProxy(int $port, string $name): bool
    {
        $proxyName = "mc-{$port}";

        $entry = "\n[[proxies]]\n"
            . "name       = \"{$proxyName}\"\n"
            . "type       = \"tcp\"\n"
            . "localIP    = \"{$this->ryzenHost}\"\n"
            . "localPort  = {$port}\n"
            . "remotePort = {$port}\n";

        // Ejecutar via SSH desde SRV-DELL al SRV-RYZEN
        $escaped = escapeshellarg($entry);
        $cmd = "ssh -o StrictHostKeyChecking=no {$this->ryzenUser}@{$this->ryzenHost} "
            . "\"echo {$escaped} | sudo tee -a {$this->frpcPath} > /dev/null "
            . "&& sudo systemctl restart frpc\"";

        $output = [];
        $code   = 0;
        exec($cmd . " 2>&1", $output, $code);

        if ($code !== 0) {
            Log::error("FrpService::addTcpProxy falló", [
                'port'   => $port,
                'output' => implode("\n", $output),
                'code'   => $code,
            ]);
            return false;
        }

        Log::info("FrpService: proxy TCP agregado", ['port' => $port, 'name' => $proxyName]);
        return true;
    }

    public function removeTcpProxy(int $port): bool
    {
        $proxyName = "mc-{$port}";

        // Eliminar el bloque del proxy del toml via Python
        $script = "python3 -c \""
            . "import re, sys\n"
            . "content = open('{$this->frpcPath}').read()\n"
            . "pattern = r'\\\\n\\\\[\\\\[proxies\\\\]\\\\]\\\\nname.*?= \\\\\"{$proxyName}\\\".*?remotePort.*?\\\\n'\n"
            . "content = re.sub(pattern, '', content, flags=re.DOTALL)\n"
            . "open('{$this->frpcPath}', 'w').write(content)\n"
            . "\"";

        $cmd = "ssh -o StrictHostKeyChecking=no {$this->ryzenUser}@{$this->ryzenHost} "
            . "\"{$script} && sudo systemctl restart frpc\"";

        $output = [];
        $code   = 0;
        exec($cmd . " 2>&1", $output, $code);

        if ($code !== 0) {
            Log::warning("FrpService::removeTcpProxy falló", [
                'port'   => $port,
                'output' => implode("\n", $output),
            ]);
            return false;
        }

        Log::info("FrpService: proxy TCP eliminado", ['port' => $port]);
        return true;
    }
}
