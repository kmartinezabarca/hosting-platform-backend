<?php

namespace App\Domains\Platform\Migration\Hestia;

/**
 * Convierte el export parseado de HestiaCP en un PLAN de migración al plano de
 * cómputo (qué recursos se crearían). No ejecuta nada: produce la propuesta que
 * el usuario revisa/confirma y que más adelante el agente de IA puede refinar
 * (detección de framework, build command). Determinista y testeable sin infra.
 *
 * Heurística de mapeo:
 *   - dominio web con PHP  → recurso 'app' (con php_version)
 *   - dominio web estático → recurso 'static_site'
 *   - base de datos        → recurso 'database' (engine mysql|postgres)
 * Los dominios/BD suspendidos se omiten con aviso.
 */
class MigrationPlanner
{
    /**
     * @param array{web_domains: HestiaWebDomain[], databases: HestiaDatabase[]} $parsed
     * @return array<string, mixed>
     */
    public function plan(array $parsed): array
    {
        $resources = [];
        $warnings  = [];

        foreach ($parsed['web_domains'] as $domain) {
            if ($domain->suspended) {
                $warnings[] = "Dominio suspendido, omitido del plan: {$domain->domain}";
                continue;
            }

            $resources[] = [
                'kind'          => $domain->isPhp() ? 'app' : 'static_site',
                'name'          => $domain->domain,
                'domain'        => $domain->domain,
                'aliases'       => $domain->aliases,
                'php_version'   => $domain->phpVersion,
                'ssl'           => $domain->ssl,
                'document_root' => $domain->documentRoot,
                'source'        => 'hestia:web_domain',
            ];
        }

        foreach ($parsed['databases'] as $database) {
            if ($database->suspended) {
                $warnings[] = "Base de datos suspendida, omitida del plan: {$database->name}";
                continue;
            }

            $resources[] = [
                'kind'    => 'database',
                'name'    => $database->name,
                'engine'  => $database->engine,
                'db_user' => $database->user,
                'source'  => 'hestia:database',
            ];
        }

        return [
            'summary' => [
                'web_domains'       => count($parsed['web_domains']),
                'databases'         => count($parsed['databases']),
                'resources_planned' => count($resources),
            ],
            'resources' => $resources,
            'warnings'  => $warnings,
            // Lo que HestiaCP tiene pero el plano de cómputo no migra automático.
            'unsupported' => [
                'Cuentas de correo, cron jobs, usuarios FTP y registros DNS no se migran '
                    . 'automáticamente; revísalos manualmente tras la migración.',
            ],
        ];
    }
}
