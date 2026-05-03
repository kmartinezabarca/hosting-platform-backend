<?php

return [
    'versions_cache_ttl' => env('MINECRAFT_VERSIONS_CACHE_TTL', 3600),

    'defaults' => [
        'software' => 'paper',
        'version' => env('MINECRAFT_DEFAULT_VERSION', '1.21.4'),
        'docker_image' => 'ghcr.io/pterodactyl/yolks:java_21',
        'startup_command' => 'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar server.jar',
        'server_jarfile' => 'server.jar',
    ],

    'fallback_versions' => [
        'paper' => ['1.21.4', '1.21.3', '1.21.1'],
        'purpur' => ['1.21.4', '1.21.3', '1.21.1'],
        'fabric' => ['1.21.4', '1.21.3', '1.21.1'],
        'forge' => ['1.21.4', '1.21.3', '1.21.1'],
        'vanilla' => ['1.21.4', '1.21.3', '1.21.1'],
    ],

    'pterodactyl' => [
        'version_variable' => env('PTERODACTYL_MC_VERSION_VARIABLE', 'MINECRAFT_VERSION'),
        'software_variable' => env('PTERODACTYL_MC_SOFTWARE_VARIABLE', 'SERVER_SOFTWARE'),
        'jarfile_variable' => env('PTERODACTYL_MC_JARFILE_VARIABLE', 'SERVER_JARFILE'),
        'version_variable_aliases' => [
            env('PTERODACTYL_MC_VERSION_VARIABLE', 'MINECRAFT_VERSION'),
            'VERSION',
            'MC_VERSION',
            'SERVER_VERSION',
            'PAPER_VERSION',
            'VANILLA_VERSION',
            'FABRIC_VERSION',
            'FORGE_VERSION',
        ],
        'software_variable_aliases' => [
            env('PTERODACTYL_MC_SOFTWARE_VARIABLE', 'SERVER_SOFTWARE'),
            'SOFTWARE',
            'SERVER_TYPE',
            'TYPE',
        ],
    ],

    'software' => [
        'paper' => [
            'name' => 'Paper',
            'description' => 'Alto rendimiento, compatible con plugins Bukkit/Spigot.',
            'recommended' => true,
            'provider' => 'paper',
        ],
        'purpur' => [
            'name' => 'Purpur',
            'description' => 'Fork de Paper con opciones avanzadas de personalización.',
            'recommended' => false,
            'provider' => 'purpur',
        ],
        'fabric' => [
            'name' => 'Fabric',
            'description' => 'Servidor modded ligero basado en Fabric Loader.',
            'recommended' => false,
            'provider' => 'fabric',
        ],
        'forge' => [
            'name' => 'Forge',
            'description' => 'Servidor modded compatible con el ecosistema Forge.',
            'recommended' => false,
            'provider' => 'forge',
        ],
        'vanilla' => [
            'name' => 'Vanilla',
            'description' => 'Servidor oficial de Minecraft sin plugins.',
            'recommended' => false,
            'provider' => 'vanilla',
        ],
    ],
];
