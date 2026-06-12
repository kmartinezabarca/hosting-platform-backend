# ADR 0001 — Servidores de juego: Pterodactyl (delegado), no Coolify

**Estado:** aceptada · **Fecha:** 2026-06-12 · **Contexto:** plano de cómputo (API v2), mes 3 "más juegos".

## Decisión

Los **servidores de juego corren en Pterodactyl/Wings (stack v1)**, NO en Coolify.
El plano de cómputo v2 es el **panel unificado**: cuando provisione un juego,
**delega** en el servicio v1 existente (`GameServerProvisioningService`) a través
de un `GameServerDriver` delgado. **No** se reimplementa la lógica de Pterodactyl
ni se fuerza el juego sobre Coolify.

## Por qué (correcta y viable)

1. **Coolify no es un panel de juegos.** Es un PaaS para apps/contenedores web
   (build packs, ruteo HTTP, TLS). No tiene el concepto de *egg*, slots de
   jugadores, consola/RCON, `server.properties`, mods, ni proxy UDP/TCP de los
   puertos de juego. Meter juegos en Coolify sería forzar la herramienta.
2. **Ya existe un stack de juegos completo y con ingresos en v1:**
   `GameServerProvisioningService` (`provision`/`suspend`/`unsuspend`/`reinstall`/
   `terminate`/`syncStatus`) sobre Pterodactyl + Wings + proxies FRP, con
   auto-fix de Java e histórico de ping. Reescribirlo sería desperdicio y riesgo.
3. **"Todo es Coolify" aplica al hosting web**, no a los juegos: son productos
   distintos sobre infra distinta (Coolify para apps; Pterodactyl para juegos).

## Implicaciones

- `game_server` **no** se provisiona vía Coolify ni se duplica en v2. v2 hoy ya
  **espeja** los game servers de v1 en modo lectura (`ComputeMirror`) para el
  dashboard unificado — eso se mantiene.
- El **catálogo de presets** (`config('compute.game_presets')` + `GET /v2/game-presets`)
  es la fuente de verdad informativa del selector: specs reales por juego y un
  flag `available` que depende de que el *egg* esté configurado por env. No
  expone ids de proveedor.
- Habilitar un juego nuevo (FiveM/Rust/Palworld) = **importar su egg al panel
  Pterodactyl** (tarea de infra/admin) + setear `COMPUTE_GAME_<JUEGO>_EGG/_NEST`.
  Sin eso, el preset se muestra como "próximamente" (`available=false`).

## Camino de implementación (cuando se quiera que v2 provisione juegos)

Requiere infra viva para verificar; por eso no se construye a ciegas:

1. `GameServerDriver` (contrato en `Compute/Providers/Contracts/`) con un
   `Fake` para tests y un real que **delega** en `GameServerProvisioningService`.
2. `ProvisionGameServerFlow` (saga) con pasos: resolver preset → crear server en
   Pterodactyl (vía driver) → esperar instalación → marcar recurso running.
   Quita el admin-confirm para pagos liquidados (objetivo semana 4).
3. Puente `Resource` (v2) ↔ `Service` (v1): el recurso v2 referencia el service
   v1; el espejo ya existe para el camino inverso.
4. Habilitar `kind=game_server` en `CreateResourceRequest` **solo** si el preset
   está `available` (egg configurado), para no exponer un camino roto.

## Alternativas descartadas

- **Juegos en Coolify:** técnicamente inviable (no es panel de juegos).
- **Reimplementar Pterodactyl en v2:** duplicación del stack v1 con ingresos;
  alto riesgo, sin beneficio.
