<?php

namespace App\Services\GameServers;

use App\Models\Service;
use App\Services\Minecraft\MinecraftVersionService;
use Illuminate\Support\Arr;
use RuntimeException;

class GameServerRuntimeService
{
    public function __construct(
        private readonly MinecraftVersionService $minecraftVersions
    ) {}

    public function softwareOptions(Service $service): array
    {
        $service->loadMissing('plan');

        return match ($service->plan?->game_type) {
            'minecraft' => $this->minecraftOptionsForPlan($service),
            default => $this->staticOptionsForPlan($service),
        };
    }

    public function assertSupported(Service $service, string $software, string $version): void
    {
        $option = collect($this->softwareOptions($service))->firstWhere('id', $software);

        // if (!$option || !in_array($version, $option['versions'] ?? [], true)) {
        //     throw new RuntimeException('La combinación de software y versión no está soportada por este plan.');
        // }
    }

    public function gameType(Service $service): ?string
    {
        $service->loadMissing('plan');

        return $service->plan?->game_type;
    }

    private function minecraftOptionsForPlan(Service $service): array
    {
        $allowedSoftware = $this->allowedSoftware($service);
        $versionOverrides = $this->versionOverrides($service);

        return collect($this->minecraftVersions->options())
            ->filter(fn (array $option) => empty($allowedSoftware) || in_array($option['id'], $allowedSoftware, true))
            ->map(function (array $option) use ($versionOverrides) {
                $allowedVersions = $versionOverrides[$option['id']] ?? null;

                if (is_array($allowedVersions) && !empty($allowedVersions)) {
                    $option['versions'] = array_values(array_intersect($option['versions'], $allowedVersions));
                }

                return $option;
            })
            ->filter(fn (array $option) => !empty($option['versions']))
            ->values()
            ->all();
    }

    private function staticOptionsForPlan(Service $service): array
    {
        $options = Arr::get($service->plan?->game_runtime_options ?? [], 'software_options', []);

        return collect($options)
            ->filter(fn ($option) => is_array($option) && !empty($option['id']) && !empty($option['versions']))
            ->values()
            ->all();
    }

    private function allowedSoftware(Service $service): array
    {
        return Arr::get($service->plan?->game_runtime_options ?? [], 'software', []);
    }

    private function versionOverrides(Service $service): array
    {
        return Arr::get($service->plan?->game_runtime_options ?? [], 'versions', []);
    }
}
