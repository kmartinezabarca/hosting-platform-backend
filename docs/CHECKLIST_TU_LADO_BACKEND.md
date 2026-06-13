# Checklist — tu lado del backend (config / infra / operación)

> Lo que **NO es código** y te toca a ti para que el plano de cómputo + lo de
> mes 2/3 funcione. El detalle explicativo está en
> [`COMPUTE_DEPLOYMENT_DEV_PROD.md`](COMPUTE_DEPLOYMENT_DEV_PROD.md); las
> variables, en [`.env.example`](../.env.example).
> Leyenda: ⛔ bloquea · ⚠️ recomendado · ⭕ opcional.

---

## A. Variables de `.env` a llenar (no las invento)
- [ ] ⛔ `ANTHROPIC_API_KEY` — agente IA + diagnóstico elocuente de builds.
- [ ] ⚠️ `COMPUTE_PRICE_*_MONTHLY/ANNUAL` (starter/pro/team/agency) — precios del catálogo de planes. Vacío → "Sin precio".
- [ ] ⚠️ `COMPUTE_STRIPE_*_MONTHLY/ANNUAL` — price IDs de Stripe (para el checkout).
- [ ] ⚠️ `COMPUTE_GAME_<JUEGO>_EGG/_NEST` — egg/nest de Pterodactyl por juego. Vacío → preset "próximamente".
- [ ] ⭕ `SENTRY_LARAVEL_DSN` — errores backend en Sentry.
- [ ] ⭕ `COMPUTE_BILLING_CURRENCY` — default `MXN`.

## B. Colas, Reverb y scheduler (en cada server)
- [ ] ⛔ `QUEUE_CONNECTION` = `redis` (prod) o `database` (dev) — **NUNCA `sync`** para builds reales.
- [ ] ⛔ Worker corriendo en colas `provisioning,deployments,ai,default` (o **Horizon** con `php artisan horizon`).
- [ ] ⛔ **Reverb** corriendo (`php artisan reverb:start`) + proxy `wss://` (logs en vivo, chat).
- [ ] ⚠️ **Scheduler** (`* * * * * php artisan schedule:run`) — incluye auto-cierre de chats a 24h.
- [ ] ⭕ (prod) `php artisan horizon:terminate` en cada deploy para recargar el código.

## C. Coolify (server de apps)
- [ ] ⛔ Server conectado y sano en Coolify; Docker arriba; `COOLIFY_SERVER_UUID` correcto.
- [ ] ⛔ Puertos **80/443** abiertos en el edge (servir apps + Let's Encrypt).
- [ ] ⚠️ Edge con **IP pública** (hoy `COOLIFY_URL` es Tailscale → apps solo por VPN).

## D. DNS (Cloudflare)
- [ ] ⛔ Registro **`*.apps.rokeindustries.dev` A → IP pública del edge de Coolify**.
      Sin esto las apps deployadas no resuelven.

## E. GitHub App (admin de GitHub)
- [ ] ⛔ Instalar la App `roke-platform` (id 4039117) en los repos a deployar.
- [ ] ⛔ Webhook de la App → `https://<api-pública>/api/v2/webhooks/github` (con `GITHUB_WEBHOOK_SECRET`).
- [ ] ✅ Llave privada: ya acepta ruta a `.pem` (no requiere base64). Coloca el `.pem` en el server.

## F. Migraciones (en cada server tras desplegar)
- [ ] ⛔ `php artisan migrate --force` — tablas de cómputo, `teams.billing_interval`, índices.

## G. Verificar / ejecutar (operativo)
- [ ] ⛔ Revisar que el **pipeline Jenkins** quedó verde tras los pushes de mes 3 (backend + frontend en `origin/develop`).
- [ ] ⚠️ **Rotación de `APP_KEY`** (pendiente de EJECUTAR desde la auditoría de junio).
- [ ] ⚠️ Verificación de **Stripe en vivo**.
- [ ] ⚠️ Validar **`CoolifyDatabaseDriver`** contra Coolify v4 vivo (shape de respuesta no verificado).

---

## Orden sugerido (camino más corto a "deploy real funcionando")
1. `.env`: `QUEUE_CONNECTION=redis` + `ANTHROPIC_API_KEY` + Coolify/GitHub ya configurados.
2. Levantar **Horizon** + **Reverb** + **scheduler** (Supervisor/systemd).
3. **Migrar**.
4. **DNS** wildcard + puertos 80/443 del edge Coolify.
5. **Instalar la GitHub App** en un repo de prueba + webhook.
6. Smoke test: crear proyecto → conectar repo → `POST /api/v2/environments/{env}/resources` → ver build/logs en vivo.
