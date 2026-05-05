<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isGameServer = $this->isPterodactylManaged();
        $details      = $this->connection_details ?? [];

        // ── Runtime del game server (software + versión) ──────────────────
        // Se almacena en configuration.game_server.runtime tras cada cambio
        // de software vía MinecraftServerConfigurationService::updateSoftware().
        $runtime      = $this->configuration['game_server']['runtime'] ?? [];

        // software_name → nombre legible guardado desde v2 ("Paper", "Nukkit", …)
        // software      → ID interno, puede ser "paper" o un egg_id numérico como "28"
        // Fallback: intentar resolver el nombre desde el config de Minecraft.
        $rawSoftware  = $runtime['software'] ?? null;
        $gameSoftware = $runtime['software_name']
            ?? (is_string($rawSoftware) && !is_numeric($rawSoftware)
                ? (config("minecraft.software.{$rawSoftware}.name") ?? ucfirst($rawSoftware))
                : null)
            ?? $rawSoftware;

        $gameVersion  = $runtime['version']      ?? null;   // 1.21.11, latest, snapshot…
        $javaVersion  = $runtime['java_version'] ?? null;   // 17, 21, 25

        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'name'          => $this->name,
            'domain'        => $this->domain,
            'status'        => $this->status,
            'billing_cycle' => $this->billing_cycle,
            'price'         => (float) $this->price,
            'setup_fee'     => (float) $this->setup_fee,
            'next_due_date' => optional($this->next_due_date)->toDateString(),
            'notes'         => $this->notes,
            'configuration' => $this->configuration,
            'restart_required' => $this->restart_required,
            'pending_changes_count' => $this->pending_changes_count,

            // ── Tipo de servicio ──────────────────────────────────────────
            'service_type'  => $this->whenLoaded('plan', fn() => $this->service_type, 'other'),
            'is_game_server'=> $isGameServer,

            // ── Runtime info (game servers) ───────────────────────────────
            // Campos de primer nivel para acceso rápido desde el frontend
            // sin tener que navegar por configuration.game_server.runtime.*
            'game_software' => $isGameServer ? $gameSoftware : null,
            'game_version'  => $isGameServer ? $gameVersion  : null,
            'java_version'  => $isGameServer ? $javaVersion  : null,

            // ── Datos de conexión (game servers e infra) ─────────────────
            // Solo se exponen si el servicio tiene connection_details
            'connection'    => $details ? [
                'server_ip'   => $details['server_ip']   ?? null,
                'server_port' => $details['server_port'] ?? null,
                // display: lo que el usuario copia para conectarse
                //   Java:    kmartinez.rokeindustries.com
                //   Bedrock: kmartinez-bedrock.rokeindustries.com:19132
                //   Fallback (sin DNS): 192.168.1.1:25565
                'display'     => $details['display']     ?? null,
                'hostname'    => $details['hostname']    ?? null,
                'is_java'     => $details['is_java']     ?? null,
                // 'panel_url' => $details['panel_url']  ?? null,
                'identifier'  => $details['identifier']  ?? null,
            ] : null,

            // ── Plan ─────────────────────────────────────────────────────
            'plan' => $this->whenLoaded('plan', fn() => [
                'id'            => $this->plan->id,
                'uuid'          => $this->plan->uuid,
                'name'          => $this->plan->name,
                'slug'          => $this->plan->slug,
                'category_slug' => $this->plan->category?->slug,
                'category'      => $this->plan->category?->name,
                'game_type'     => $this->plan->game_type,
                // Recursos del plan (límites del servidor de juego)
                'limits'        => $isGameServer ? ($this->plan->pterodactyl_limits ?? null) : null,
                'feature_limits'=> $isGameServer ? ($this->plan->pterodactyl_feature_limits ?? null) : null,
            ]),

            // ── Usuario (solo en admin) ───────────────────────────────────
            'user' => $this->whenLoaded('user', fn() => [
                'id'    => $this->user->id,
                'name'  => trim($this->user->first_name . ' ' . $this->user->last_name),
                'email' => $this->user->email,
            ]),

            // ── Add-ons ───────────────────────────────────────────────────
            'add_ons' => $this->whenLoaded('selectedAddOns', fn() =>
                $this->selectedAddOns->map(fn($a) => [
                    'uuid'       => $a->uuid,
                    'name'       => $a->name,
                    'unit_price' => (float) $a->unit_price,
                ])
            ),

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
