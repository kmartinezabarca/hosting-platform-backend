<?php

namespace App\Domains\Platform\Compute\Orchestrator;

use App\Domains\Platform\Compute\Orchestrator\Flows\DeployFlow;
use App\Domains\Platform\Compute\Orchestrator\Flows\ProvisionAppFlow;
use InvalidArgumentException;

class FlowRegistry
{
    /** @var array<string, class-string<Flow>> */
    private static array $flows = [];

    private static function defaults(): array
    {
        return [
            ProvisionAppFlow::key() => ProvisionAppFlow::class,
            DeployFlow::key()       => DeployFlow::class,
        ];
    }

    /** Registro adicional (tests, flujos de otros módulos). */
    public static function register(string $key, string $flowClass): void
    {
        self::$flows[$key] = $flowClass;
    }

    public static function resolve(string $key): Flow
    {
        $map = array_merge(self::defaults(), self::$flows);

        if (! isset($map[$key])) {
            throw new InvalidArgumentException("Flow desconocido: {$key}");
        }

        return app($map[$key]);
    }

    public static function flush(): void
    {
        self::$flows = [];
    }
}
