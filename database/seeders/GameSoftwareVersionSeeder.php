<?php

namespace Database\Seeders;

use App\Models\GameSoftwareVersion;
use Illuminate\Database\Seeder;

/**
 * Versiones de software de servidor compatibles con Pterodactyl / Yolks.
 *
 * Criterios de inclusión:
 *  - La versión tiene egg/startup funcional en Pterodactyl.
 *  - Existe imagen Docker Yolks con el Java requerido (java_8/java_17/java_21).
 *  - La fuente de descarga (API del software) devuelve el JAR para esa versión.
 *  - No se incluyen snapshots, betas ni pre-releases.
 *
 * sort_order: las versiones se insertan de más antigua a más nueva;
 * sort_order = índice + 1 (menor = más antigua, mayor = más nueva).
 * La consulta usa ORDER BY sort_order DESC → más nueva aparece primero.
 *
 * Para agregar versiones futuras: php artisan game:versions add {software} {version}
 */
class GameSoftwareVersionSeeder extends Seeder
{
    // ── Notas de Java por umbral de versión de MC ─────────────────────────────
    private const J8  = 'Requiere Java 8 (Yolks: java_8)';
    private const J16 = 'Requiere Java 16 (Yolks: java_16)';
    private const J17 = 'Requiere Java 17 (Yolks: java_17)';
    private const J21 = 'Requiere Java 21 (Yolks: java_21)';

    public function run(): void
    {
        // Truncar antes de sembrar (idempotente)
        GameSoftwareVersion::truncate();

        foreach ($this->versions() as $identifier => $config) {
            $recommended = $config['recommended'];
            $versions    = $config['versions']; // oldest → newest

            foreach ($versions as $i => $version) {
                GameSoftwareVersion::create([
                    'software_identifier' => $identifier,
                    'version'             => $version[0],
                    'is_active'           => true,
                    'is_recommended'      => $version[0] === $recommended,
                    'sort_order'          => $i + 1,   // 1 = más antigua, N = más nueva
                    'notes'               => $version[1] ?? null,
                ]);
            }
        }

        $this->command->info('GameSoftwareVersionSeeder: ' . GameSoftwareVersion::count() . ' versiones insertadas.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Catálogo de versiones curadas
    // Formato por entrada: [version_string, notas_opcionales]
    // Orden dentro del array: más antigua → más nueva
    // ─────────────────────────────────────────────────────────────────────────

    private function versions(): array
    {
        return [

            // ── Paper MC ──────────────────────────────────────────────────────
            // Alto rendimiento, compatible Bukkit/Spigot.
            // Egg oficial Paper en Pterodactyl Market.
            'paper' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.8.8',  self::J8],
                    ['1.9.4',  self::J8],
                    ['1.10.2', self::J8],
                    ['1.11.2', self::J8],
                    ['1.12.2', self::J8],
                    ['1.13.2', self::J8],
                    ['1.14.4', self::J8],
                    ['1.15.2', self::J8],
                    ['1.16.5', self::J8],
                    ['1.17.1', self::J16],
                    ['1.18.2', self::J17],
                    ['1.19.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.2', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.3', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Velocity ──────────────────────────────────────────────────────
            // Proxy moderno de alto rendimiento (no Minecraft "game server").
            // Usa su propio esquema de versiones, no versiones de MC.
            'velocity' => [
                'recommended' => '3.4.0-SNAPSHOT',
                'versions'    => [
                    ['3.0.0-SNAPSHOT', self::J17],
                    ['3.1.2-SNAPSHOT', self::J17],
                    ['3.2.0-SNAPSHOT', self::J21],
                    ['3.3.0-SNAPSHOT', self::J21],
                    ['3.4.0-SNAPSHOT', self::J21],
                ],
            ],

            // ── Folia ─────────────────────────────────────────────────────────
            // Fork de Paper con soporte de hilos regionales.
            // Disponible desde MC 1.19.4 (experimental en versiones anteriores).
            'folia' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.3', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Purpur ────────────────────────────────────────────────────────
            // Fork de Paper con configuración avanzada.
            'purpur' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.14.4', self::J8],
                    ['1.15.2', self::J8],
                    ['1.16.5', self::J8],
                    ['1.17.1', self::J16],
                    ['1.18.2', self::J17],
                    ['1.19.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.2', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.3', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Purpur + Geyser ───────────────────────────────────────────────
            // Purpur con Geyser/Floodgate preinstalado (acceso desde Bedrock).
            // Mismas versiones que Purpur.
            'purpur-geyser' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.16.5', self::J8],
                    ['1.17.1', self::J16],
                    ['1.18.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.3', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Vanilla ───────────────────────────────────────────────────────
            // Servidor oficial de Mojang sin plugins ni mods.
            'vanilla' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.8.9',  self::J8],
                    ['1.9.4',  self::J8],
                    ['1.10.2', self::J8],
                    ['1.11.2', self::J8],
                    ['1.12.2', self::J8],
                    ['1.13.2', self::J8],
                    ['1.14.4', self::J8],
                    ['1.15.2', self::J8],
                    ['1.16.5', self::J8],
                    ['1.17.1', self::J16],
                    ['1.18.2', self::J17],
                    ['1.19.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.3', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Bedrock (via GeyserMC) ────────────────────────────────────────
            // Servidor Java con Geyser como puente para clientes Bedrock.
            // Las versiones corresponden a la versión Java del servidor.
            'bedrock' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.16.5', self::J8],
                    ['1.17.1', self::J16],
                    ['1.18.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.3', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Fabric ────────────────────────────────────────────────────────
            // Plataforma de mods ligera. El Loader se descarga en tiempo de inicio.
            'fabric' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.14.4', self::J8],
                    ['1.15.2', self::J8],
                    ['1.16.5', self::J8],
                    ['1.17.1', self::J16],
                    ['1.18.2', self::J17],
                    ['1.19.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.2', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.3', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Quilt ─────────────────────────────────────────────────────────
            // Fork de Fabric con soporte mejorado de mods (disponible desde 1.18).
            'quilt' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.18.2', self::J17],
                    ['1.19.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.3', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Forge ─────────────────────────────────────────────────────────
            // Plataforma de mods clásica. El egg descarga el Forge installer
            // de la versión correspondiente durante el start.
            // Se almacena la versión de MC (el egg resuelve la versión de Forge).
            'forge' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.7.10', self::J8],
                    ['1.8.9',  self::J8],
                    ['1.10.2', self::J8],
                    ['1.12.2', self::J8],
                    ['1.14.4', self::J8],
                    ['1.15.2', self::J8],
                    ['1.16.5', self::J8],
                    ['1.17.1', self::J16],
                    ['1.18.2', self::J17],
                    ['1.19.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── NeoForge ──────────────────────────────────────────────────────
            // Fork oficial de Forge. Disponible desde MC 1.20.2.
            'neoforge' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.20.2', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.3', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Arclight ──────────────────────────────────────────────────────
            // Híbrido Forge + Paper. Soporta plugins y mods simultáneamente.
            // Lista reducida: solo versiones con releases estables verificados.
            'arclight' => [
                'recommended' => '1.21.1',
                'versions'    => [
                    ['1.12.2', self::J8],
                    ['1.16.5', self::J8],
                    ['1.18.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.4', self::J17],
                    ['1.21.1', self::J21],
                ],
            ],

            // ── SpongeVanilla ─────────────────────────────────────────────────
            // Plataforma Sponge sobre Minecraft Vanilla.
            'sponge' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.12.2', self::J8],
                    ['1.16.5', self::J8],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── Spigot ────────────────────────────────────────────────────────
            // Compilado con BuildTools en inicio. Compatible Bukkit.
            'spigot' => [
                'recommended' => '1.21.4',
                'versions'    => [
                    ['1.8.8',  self::J8],
                    ['1.9.4',  self::J8],
                    ['1.10.2', self::J8],
                    ['1.12.2', self::J8],
                    ['1.14.4', self::J8],
                    ['1.15.2', self::J8],
                    ['1.16.5', self::J8],
                    ['1.17.1', self::J16],
                    ['1.18.2', self::J17],
                    ['1.19.4', self::J17],
                    ['1.20.1', self::J17],
                    ['1.20.4', self::J17],
                    ['1.20.6', self::J21],
                    ['1.21',   self::J21],
                    ['1.21.1', self::J21],
                    ['1.21.4', self::J21],
                ],
            ],

            // ── BungeeCord ────────────────────────────────────────────────────
            // Proxy para redes multi-servidor. No tiene "versiones" de MC —
            // siempre usa la última build compatible con la red.
            'bungeecord' => [
                'recommended' => 'latest',
                'versions'    => [
                    ['latest', 'Siempre descarga la build más reciente de BungeeCord (Java 17+)'],
                ],
            ],

            // ── Nukkit ────────────────────────────────────────────────────────
            // Servidor nativo para clientes Bedrock Edition (sin Java Proxy).
            'nukkit' => [
                'recommended' => 'latest',
                'versions'    => [
                    ['latest', 'Siempre descarga la build más reciente de Nukkit (Java 17+)'],
                ],
            ],

        ];
    }
}
