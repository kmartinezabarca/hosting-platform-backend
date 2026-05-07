<?php

namespace App\Enums;

enum GameProtocol: string
{
    case JAVA = 'java';

    case BEDROCK = 'bedrock';

    /**
     * Java + Bedrock via Geyser/Floodgate
     */
    case CROSSPLAY = 'crossplay';

    /**
     * Java usa registros SRV.
     * Crossplay también porque normalmente
     * el endpoint principal sigue siendo Java.
     */
    public function usesSrvRecord(): bool
    {
        return match ($this) {
            self::JAVA,
            self::CROSSPLAY => true,

            self::BEDROCK => false,
        };
    }

    /**
     * Bedrock necesita mostrar puerto.
     * Java normalmente no gracias al SRV.
     */
    public function displayPort(): bool
    {
        return match ($this) {
            self::BEDROCK => true,

            self::JAVA,
            self::CROSSPLAY => false,
        };
    }

    /**
     * Permite clientes Java.
     */
    public function supportsJava(): bool
    {
        return match ($this) {
            self::JAVA,
            self::CROSSPLAY => true,

            self::BEDROCK => false,
        };
    }

    /**
     * Permite clientes Bedrock.
     */
    public function supportsBedrock(): bool
    {
        return match ($this) {
            self::BEDROCK,
            self::CROSSPLAY => true,

            self::JAVA => false,
        };
    }

    /**
     * Helper útil para frontend/UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::JAVA => 'Java Edition',
            self::BEDROCK => 'Bedrock Edition',
            self::CROSSPLAY => 'Crossplay',
        };
    }
}
