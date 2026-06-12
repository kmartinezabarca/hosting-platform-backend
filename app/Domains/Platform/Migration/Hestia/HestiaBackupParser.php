<?php

namespace App\Domains\Platform\Migration\Hestia;

/**
 * Parsea el export de HestiaCP (salida JSON de los comandos `v-list-*`) a DTOs
 * normalizados. NO toca infraestructura: recibe los arrays ya decodificados y
 * devuelve objetos. La salida alimenta a MigrationPlanner.
 *
 * Shapes esperados (claves = dominio / nombre de BD):
 *   web_domains: { "dom.com": { DOCUMENT_ROOT, ALIAS, SSL, BACKEND, SUSPENDED, ... } }
 *   databases:   { "user_db": { DBUSER, TYPE, SUSPENDED, ... } }
 */
class HestiaBackupParser
{
    /**
     * @param array<string, mixed> $export  ['web_domains' => [...], 'databases' => [...]]
     * @return array{web_domains: HestiaWebDomain[], databases: HestiaDatabase[]}
     */
    public function parse(array $export): array
    {
        return [
            'web_domains' => $this->parseWebDomains($export['web_domains'] ?? []),
            'databases'   => $this->parseDatabases($export['databases'] ?? []),
        ];
    }

    /** @return HestiaWebDomain[] */
    private function parseWebDomains(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $domains = [];
        foreach ($raw as $domain => $attrs) {
            if (is_array($attrs)) {
                $domains[] = HestiaWebDomain::fromArray((string) $domain, $attrs);
            }
        }

        return $domains;
    }

    /** @return HestiaDatabase[] */
    private function parseDatabases(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $databases = [];
        foreach ($raw as $name => $attrs) {
            if (is_array($attrs)) {
                $databases[] = HestiaDatabase::fromArray((string) $name, $attrs);
            }
        }

        return $databases;
    }
}
