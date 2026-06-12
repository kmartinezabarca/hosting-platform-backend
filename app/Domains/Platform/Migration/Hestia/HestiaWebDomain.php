<?php

namespace App\Domains\Platform\Migration\Hestia;

/**
 * Un dominio web tal como lo exporta HestiaCP (`v-list-web-domains <user> json`).
 * El objeto se normaliza desde el shape crudo del panel; solo se toman los
 * campos relevantes para una migración al plano de cómputo.
 */
final class HestiaWebDomain
{
    public function __construct(
        public readonly string $domain,
        public readonly string $documentRoot,
        /** @var string[] */
        public readonly array $aliases,
        public readonly ?string $phpVersion,
        public readonly bool $ssl,
        public readonly bool $suspended,
    ) {
    }

    /**
     * @param array<string, mixed> $attrs  fila del JSON de v-list-web-domains
     */
    public static function fromArray(string $domain, array $attrs): self
    {
        return new self(
            domain:       $domain,
            documentRoot: (string) ($attrs['DOCUMENT_ROOT'] ?? ''),
            aliases:      self::parseAliases($attrs['ALIAS'] ?? ''),
            phpVersion:   self::parsePhpVersion((string) ($attrs['BACKEND'] ?? '')),
            ssl:          self::yes($attrs['SSL'] ?? 'no'),
            suspended:    self::yes($attrs['SUSPENDED'] ?? 'no'),
        );
    }

    /** ¿El sitio corre PHP (→ app) o es estático (→ static_site)? */
    public function isPhp(): bool
    {
        return $this->phpVersion !== null;
    }

    /** ALIAS viene como "www.dom.com,otro.com" (o vacío). */
    private static function parseAliases(string $alias): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $alias))));
    }

    /** BACKEND "PHP-8_1" → "8.1"; vacío/no-PHP → null (estático). */
    private static function parsePhpVersion(string $backend): ?string
    {
        if (! preg_match('/PHP-?(\d+)[._](\d+)/i', $backend, $m)) {
            return null;
        }

        return "{$m[1]}.{$m[2]}";
    }

    private static function yes(mixed $value): bool
    {
        return strtolower((string) $value) === 'yes';
    }
}
