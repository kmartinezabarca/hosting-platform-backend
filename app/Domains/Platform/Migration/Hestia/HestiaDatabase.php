<?php

namespace App\Domains\Platform\Migration\Hestia;

/**
 * Una base de datos exportada por HestiaCP (`v-list-databases <user> json`).
 */
final class HestiaDatabase
{
    public function __construct(
        public readonly string $name,
        public readonly string $user,
        /** Motor normalizado al vocabulario del plano de cómputo: mysql|postgres. */
        public readonly string $engine,
        public readonly bool $suspended,
    ) {
    }

    /**
     * @param array<string, mixed> $attrs  fila del JSON de v-list-databases
     */
    public static function fromArray(string $name, array $attrs): self
    {
        return new self(
            name:      $name,
            user:      (string) ($attrs['DBUSER'] ?? ''),
            engine:    self::normalizeEngine((string) ($attrs['TYPE'] ?? 'mysql')),
            suspended: strtolower((string) ($attrs['SUSPENDED'] ?? 'no')) === 'yes',
        );
    }

    /** HestiaCP usa 'mysql' | 'pgsql'; el plano de cómputo usa 'mysql' | 'postgres'. */
    private static function normalizeEngine(string $type): string
    {
        return in_array(strtolower($type), ['pgsql', 'postgresql', 'postgres'], true)
            ? 'postgres'
            : 'mysql';
    }
}
