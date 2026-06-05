# Game Server Runtime Sources

El endpoint `GET /api/services/{uuid}/game-server/software-options` es multi-juego. Primero lee las capacidades del plan (`service_plans.game_type` y `service_plans.game_runtime_options`) y después delega al adapter correspondiente.

Para Minecraft, el backend resuelve las opciones de software desde `App\Services\Minecraft\MinecraftVersionService` y cachea el resultado con la clave `minecraft:software-options`.

Campos SaaS por plan:

- `game_type`: identifica el adapter del juego, por ejemplo `minecraft`, `valheim`, `terraria`, `rust`.
- `game_runtime_options`: JSON de whitelists y/o opciones estáticas por plan.
  - Minecraft: `{ "software": ["paper", "vanilla"], "versions": { "paper": ["1.21.4"] } }`
  - Otros juegos: `{ "software_options": [{ "id": "vanilla", "name": "Vanilla", "versions": ["stable"] }] }`
- `game_config_schema`: reservado para definir formularios/validaciones dinámicas por juego cuando se agreguen adapters más allá de Minecraft.

APIs usadas:

- Vanilla: `https://piston-meta.mojang.com/mc/game/version_manifest_v2.json`
  - Se usan solo entradas `type=release`.
- Paper: `https://fill.papermc.io/v3/projects/paper`
  - Fallback preparado: `https://api.papermc.io/v2/projects/paper`.
- Purpur: `https://api.purpurmc.org/v2/purpur/`
  - Devuelve versiones de Minecraft con builds disponibles.
- Fabric: `https://meta.fabricmc.net/v2/versions/game`
  - Se usan solo entradas `stable=true`.
- Forge: `https://files.minecraftforge.net/net/minecraftforge/forge/promotions_slim.json`
  - Se extraen versiones desde llaves `promos` con sufijos `-latest` y `-recommended`.

Notas de operación:

- El TTL por defecto es 3600 segundos y se configura con `MINECRAFT_VERSIONS_CACHE_TTL`.
- Los softwares habilitados por la plataforma viven en `config/minecraft.php`, pero cada plan filtra lo que puede usar con `game_runtime_options`.
- Si un proveedor falla, ese adapter devuelve una lista vacía y el software se omite de la respuesta hasta que el cache se regenere correctamente.
- El service está preparado para cambiar variables Pterodactyl genéricas:
  - `MINECRAFT_VERSION`
  - `SERVER_SOFTWARE`
  - `SERVER_JARFILE`
  Estas variables pueden ajustarse con env vars si el egg usa otros nombres.
