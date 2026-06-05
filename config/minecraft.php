<?php

return [
    'versions_cache_ttl' => env('MINECRAFT_VERSIONS_CACHE_TTL', 3600),

    'defaults' => [
        'software'        => 'paper',
        'version'         => env('MINECRAFT_DEFAULT_VERSION', '1.21.4'),
        'docker_image'    => 'ghcr.io/pterodactyl/yolks:java_21',
        'startup_command' => 'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar server.jar',
        'server_jarfile'  => 'server.jar',
    ],

    'fallback_versions' => [
        'paper'   => ['1.21.4', '1.21.3', '1.21.1'],
        'purpur'  => ['1.21.4', '1.21.3', '1.21.1'],
        'fabric'  => ['1.21.4', '1.21.3', '1.21.1'],
        'forge'   => ['1.21.4', '1.21.3', '1.21.1'],
        'vanilla' => ['1.21.4', '1.21.3', '1.21.1'],
    ],

    // ── Umbrales de versión de Java ────────────────────────────────────────────
    //
    // Mapeados de MAYOR a MENOR. El primer umbral que se cumple gana.
    //
    // Imágenes disponibles en ghcr.io/pterodactyl/yolks:
    //   java_8 | java_11 | java_16 | java_17 | java_21 | java_22 | java_23 | java_24 | java_25
    //
    // Umbrales de Minecraft (basados en requerimientos oficiales de Mojang):
    //   >= 1.22         → Java 21  (puede requerir java_25 cuando esté disponible)
    //   >= 1.20.5       → Java 21
    //   >= 1.18         → Java 17
    //   >= 1.17         → Java 16  (1.17 y 1.17.1 requieren Java 16+ exacto)
    //   <  1.17 (1.7+)  → Java 8
    //
    'java_thresholds' => [
        [
            'min_version' => env('MINECRAFT_JAVA21_THRESHOLD_UPPER', '1.22'),
            'java'        => 21,
            'yolks_tag'   => 'java_21',   // Cambiar a java_25 cuando esté en producción
            'label'       => 'Java 21 (1.22+)',
        ],
        [
            'min_version' => env('MINECRAFT_JAVA21_THRESHOLD', '1.20.5'),
            'java'        => 21,
            'yolks_tag'   => 'java_21',
            'label'       => 'Java 21 (1.20.5 – 1.21.x)',
        ],
        [
            'min_version' => env('MINECRAFT_JAVA17_THRESHOLD', '1.18'),
            'java'        => 17,
            'yolks_tag'   => 'java_17',
            'label'       => 'Java 17 (1.18 – 1.20.4)',
        ],
        [
            'min_version' => env('MINECRAFT_JAVA16_THRESHOLD', '1.17'),
            'java'        => 16,
            'yolks_tag'   => 'java_16',
            'label'       => 'Java 16 (1.17 – 1.17.1)',
        ],
        // Fallback: todo lo anterior a 1.17 → Java 8
    ],

    // Versión de Java para Minecraft < 1.17 (todo lo que no cayó en ningún umbral)
    'java_legacy' => [
        'java'      => 8,
        'yolks_tag' => 'java_8',
        'label'     => 'Java 8 (< 1.17)',
    ],

    // Imágenes disponibles en Pterodactyl Yolks (en orden ASCENDENTE).
    // Usar este array para validar antes de construir el tag.
    'available_yolks_tags' => [
        'java_8', 'java_11', 'java_16', 'java_17',
        'java_21', 'java_22', 'java_23', 'java_24', 'java_25',
    ],

    // Java class major version → Java version (Java SE 8 = major 52, etc.)
    // Usado para parsear "Unsupported class file major version X" de los logs.
    'class_major_version_map' => [
        52 => 8,
        55 => 11,
        60 => 16,
        61 => 17,
        62 => 18,
        63 => 19,
        64 => 20,
        65 => 21,
        66 => 22,
        67 => 23,
        68 => 24,
        69 => 25,
    ],

    // ── Pterodactyl variable names ──────────────────────────────────────────────
    'pterodactyl' => [
        'version_variable'          => env('PTERODACTYL_MC_VERSION_VARIABLE', 'MINECRAFT_VERSION'),
        'software_variable'         => env('PTERODACTYL_MC_SOFTWARE_VARIABLE', 'SERVER_SOFTWARE'),
        'jarfile_variable'          => env('PTERODACTYL_MC_JARFILE_VARIABLE', 'SERVER_JARFILE'),
        'version_variable_aliases'  => [
            env('PTERODACTYL_MC_VERSION_VARIABLE', 'MINECRAFT_VERSION'),
            'VERSION', 'MC_VERSION', 'SERVER_VERSION',
            'PAPER_VERSION', 'VANILLA_VERSION', 'FABRIC_VERSION', 'FORGE_VERSION',
        ],
        'software_variable_aliases' => [
            env('PTERODACTYL_MC_SOFTWARE_VARIABLE', 'SERVER_SOFTWARE'),
            'SOFTWARE', 'SERVER_TYPE', 'TYPE',
        ],
    ],

    // ── Software disponible ────────────────────────────────────────────────────
    'software' => [
        'paper' => [
            'name'        => 'Paper',
            'description' => 'Alto rendimiento, compatible con plugins Bukkit/Spigot.',
            'recommended' => true,
            'provider'    => 'paper',
        ],
        'purpur' => [
            'name'        => 'Purpur',
            'description' => 'Fork de Paper con opciones avanzadas de personalización.',
            'recommended' => false,
            'provider'    => 'purpur',
        ],
        'fabric' => [
            'name'        => 'Fabric',
            'description' => 'Servidor modded ligero basado en Fabric Loader.',
            'recommended' => false,
            'provider'    => 'fabric',
        ],
        'forge' => [
            'name'        => 'Forge',
            'description' => 'Servidor modded compatible con el ecosistema Forge.',
            'recommended' => false,
            'provider'    => 'forge',
        ],
        'vanilla' => [
            'name'        => 'Vanilla',
            'description' => 'Servidor oficial de Minecraft sin plugins.',
            'recommended' => false,
            'provider'    => 'vanilla',
        ],
    ],

    // ── Archivos a preservar cuando se hace un clean install (cambio de versión) ──
    // Solo aplica cuando el egg NO cambia — estos archivos/dirs se mantienen intactos.
    // Para cambios de egg (juego diferente) se borra TODO.
    'preserve_on_version_change' => [
        'world', 'world_nether', 'world_the_end',   // mundos de Minecraft Java Edition
        'DIM-1', 'DIM1',                             // mundos antiguos de dimensiones
        'plugins',                                   // plugins del servidor
        'config',                                    // configuraciones extra
        'ops.json', 'whitelist.json',
        'banned-players.json', 'banned-ips.json',
        'usercache.json',
    ],
];
