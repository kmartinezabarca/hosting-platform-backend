<?php

namespace App\Services\Minecraft;

/**
 * Realiza pings de estado al protocolo SLP (Server List Ping) de Minecraft.
 * Extraído de GameServerController para poder reutilizarlo desde el scheduler.
 */
class MinecraftPingService
{
    /**
     * Devuelve el array de estado (incluyendo ping_ms) o null si el servidor no responde.
     *
     * @param  int  $timeoutMs  Tiempo máximo de espera en milisegundos.
     */
    public function ping(string $host, int $port = 25565, int $timeoutMs = 2000): ?array
    {
        $timeoutSec = max(1, (int) ceil($timeoutMs / 1000));

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
        if (!$socket) {
            return null;
        }

        stream_set_timeout($socket, $timeoutSec);

        $startMs = (int) (microtime(true) * 1000);

        // Handshake packet (0x00) — Minecraft 1.7+ SLP
        $handshake  = "\x00";
        $handshake .= "\xff\xff\xff\xff\x0f";
        $handshake .= $this->writeString($host);
        $handshake .= pack('n', $port);
        $handshake .= "\x01"; // next state: status

        $this->sendPacket($socket, $handshake);
        $this->sendPacket($socket, "\x00"); // status request

        $length   = $this->readVarInt($socket);
        if ($length === null || $length <= 0) { fclose($socket); return null; }

        $this->readVarInt($socket); // packet ID (discard)
        $jsonLen = $this->readVarInt($socket);

        if ($jsonLen === null || $jsonLen <= 0) { fclose($socket); return null; }

        $json      = '';
        $remaining = $jsonLen;
        while ($remaining > 0) {
            $chunk = fread($socket, $remaining);
            if ($chunk === false || $chunk === '') break;
            $json      .= $chunk;
            $remaining -= strlen($chunk);
        }

        $endMs = (int) (microtime(true) * 1000);
        fclose($socket);

        $data = json_decode($json, true);
        if (!is_array($data)) return null;

        $data['ping_ms'] = $endMs - $startMs;

        if (isset($data['description']) && is_array($data['description'])) {
            $data['description'] = $data['description']['text'] ?? null;
        }

        if (isset($data['players']['sample']) && is_array($data['players']['sample'])) {
            $data['players']['sample'] = collect($data['players']['sample'])
                ->map(fn ($player) => [
                    'name' => is_array($player) ? ($player['name'] ?? null) : null,
                    'id'   => is_array($player) ? ($player['id']   ?? null) : null,
                ])
                ->filter(fn ($player) => ! empty($player['name']))
                ->values()
                ->all();
        }

        return $data;
    }

    // ── Private helpers ──────────────────────────────────────────────────────────

    private function writeString(string $str): string
    {
        return $this->encodeVarInt(strlen($str)) . $str;
    }

    private function sendPacket($socket, string $data): void
    {
        fwrite($socket, $this->encodeVarInt(strlen($data)) . $data);
    }

    private function encodeVarInt(int $value): string
    {
        $bytes = '';
        do {
            $byte  = $value & 0x7F;
            $value = ($value >> 7) & 0x1FFFFFFF;
            if ($value !== 0) $byte |= 0x80;
            $bytes .= chr($byte);
        } while ($value !== 0);
        return $bytes;
    }

    private function readVarInt($socket): ?int
    {
        $value    = 0;
        $position = 0;
        while (true) {
            $byte = fread($socket, 1);
            if ($byte === false || $byte === '') return null;
            $byte      = ord($byte);
            $value    |= ($byte & 0x7F) << $position;
            $position += 7;
            if (!($byte & 0x80)) break;
            if ($position >= 32) return null;
        }
        return $value;
    }
}
