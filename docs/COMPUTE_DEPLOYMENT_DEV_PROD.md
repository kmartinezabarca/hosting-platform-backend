# Despliegue del plano de cómputo — Servidores DEV y PROD

> Qué hay que dejar listo en **cada servidor** para que el flujo
> "Conecta GitHub → la IA detecta → app deployada con URL" + diagnóstico de
> fallos + agente IA funcione de punta a punta. Cubre el MVP (semanas 1‑4) y deja
> apuntado lo que el **mes 2** agrega (Horizon, Sentry, usage metering).

---

## 0. Mapa de servicios

| Servicio | Para qué | Dónde corre |
|---|---|---|
| **API Laravel** (este repo) | API v2 de cómputo, orquestador, agente IA | Contenedor (deploy por Jenkins → Coolify) |
| **MySQL** (`roke_*`) + **roke_pet** | Datos plataforma y Pet | Servidor de BD (hoy `100.72.162.112` por Tailscale) |
| **Redis** | Colas + caché + Horizon (prod) | Mismo host de la API o gestionado |
| **Queue workers** | Saga de provisión/deploy, diagnóstico IA | Proceso(s) en el host de la API |
| **Scheduler** | Cron de Laravel (`schedule:run` c/minuto) | Host de la API |
| **Reverb** | WebSockets (logs de build en vivo, chat) | Proceso en el host de la API + proxy `wss://` |
| **Coolify** | Runtime de apps (build + deploy) | **Servidor aparte** (hoy `100.124.151.68:8000`) |
| **Pterodactyl / Wings** | Servidores de juego | Nodos aparte |
| **Cloudflare DNS** | Dominios de API/app + **wildcard de apps** | — |
| **GitHub App** | Conectar repos, webhooks de push | Externo (necesita webhook público) |
| **Stripe** | Pagos (test en dev, **live en prod**) | Externo |
| **Anthropic API** | Agente IA + diagnóstico elocuente | Externo |

**Regla de oro:** la saga de deploy es **asíncrona**. Sin un **worker** en las colas
`provisioning,deployments,ai` no pasa nada después del `202`. Y sin **Reverb** no se
ven los logs en vivo.

---

## 1. Variables de entorno por ambiente

Estas son las diferencias que **sí o sí** cambian entre dev y prod:

| Variable | DEV | PROD |
|---|---|---|
| `APP_ENV` | `local` / `development` | `production` |
| `APP_DEBUG` | `true` | **`false`** |
| `QUEUE_CONNECTION` | `database` o `redis` (NO `sync`) | `redis` |
| `CACHE_DRIVER` | `file`/`redis` | `redis` |
| `SESSION_SECURE_COOKIE` | `false` | `true` |
| `STRIPE_*` | claves **test** | claves **live** |
| `MAIL_MAILER` | `log` | real (Resend/SES) |
| `COMPUTE_APP_DOMAIN` | `apps.rokeindustries.dev` | `apps.rokeindustries.com` (decidir) |
| `COOLIFY_URL` | edge dev | edge prod |
| GitHub webhook URL | `https://api-dev.../api/v2/webhooks/github` | `https://api.../api/v2/webhooks/github` |
| `ANTHROPIC_API_KEY` | opcional (agente off si falta) | requerida para el agente |
| `SENTRY_LARAVEL_DSN` | opcional | recomendada (mes 2) |

Comunes a ambos (plano de cómputo):
```dotenv
# Coolify
COOLIFY_URL=...            # http(s)://edge:8000
COOLIFY_API_TOKEN=...      # token de la API de Coolify
COOLIFY_SERVER_UUID=...    # servidor destino donde se crean las apps
COOLIFY_TEAM_ID=0
COOLIFY_VERIFY_SSL=false   # true si el edge es https con cert válido

# Plano de cómputo
COMPUTE_APP_DOMAIN=apps.rokeindustries.dev   # zona wildcard de apps
COMPUTE_DEPLOY_POLL_SECONDS=8
COMPUTE_MAX_ORCH_ATTEMPTS=150

# GitHub App
GITHUB_APP_ID=4039117
GITHUB_APP_SLUG=roke-platform
# Acepta: ruta a .pem (relativa a la raíz), PEM crudo, o base64 del PEM
GITHUB_APP_PRIVATE_KEY_BASE64=roke-platform-dev.2026-06-12.private-key.pem
GITHUB_WEBHOOK_SECRET=...

# IA
ANTHROPIC_API_KEY=sk-ant-...
PLATFORM_AI_AGENT_ENABLED=true

# Observabilidad (mes 2)
SENTRY_LARAVEL_DSN=https://...ingest.sentry.io/...   # errores backend (vacío = no-op)
SENTRY_TRACES_SAMPLE_RATE=0.2                          # muestreo de performance
# Horizon requiere Redis: QUEUE_CONNECTION=redis + REDIS_* configurado

# Reverb (broadcast)
BROADCAST_DRIVER=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=...     # host público para el front (wss)
REVERB_PORT=443     # en prod detrás de proxy TLS
REVERB_SCHEME=https # en prod
```

> ⚠️ El archivo `*.private-key.pem` **nunca** se commitea (ya está en `.gitignore`).
> En los servidores, colócalo en la raíz del proyecto (o usa ruta absoluta) y
> apunta `GITHUB_APP_PRIVATE_KEY_BASE64` a él.

---

## 2. Pasos en el servidor — comunes (dev y prod)

1. **Deploy del backend** (ya automatizado por Jenkins). Tras cada release:
   ```bash
   composer install --no-dev --optimize-autoloader   # prod
   php artisan migrate --force                         # tablas de cómputo incluidas
   php artisan config:cache route:cache event:cache
   ```
2. **Workers de cola** (proceso persistente — systemd o Supervisor):
   - **PROD (Redis): usa Horizon** — gestiona los workers y da dashboard:
     ```ini
     # /etc/supervisor/conf.d/roke-horizon.conf
     [program:roke-horizon]
     command=php /var/www/api/artisan horizon
     autostart=true
     autorestart=true
     stopwaitsecs=3600   ; deja terminar el job en curso (horizon:terminate al desplegar)
     ```
     Tras cada deploy: `php artisan horizon:terminate` (recarga el código nuevo).
     Dashboard admin-gated en `/horizon` (gate `viewHorizon` → `User::isAdmin()`).
     Las colas ya están configuradas en `config/horizon.php`:
     `provisioning, deployments, ai, default`.
   - **DEV (sin Redis): `queue:work` con `database`**:
     ```ini
     [program:roke-worker]
     command=php /var/www/api/artisan queue:work --queue=provisioning,deployments,ai,default --tries=1 --max-time=3600
     numprocs=2
     autostart=true
     autorestart=true
     ```
   > Colas de cómputo: `provisioning`, `deployments`, `ai`. Nunca uses `sync`.
3. **Scheduler** (cron del sistema):
   ```cron
   * * * * * cd /var/www/api && php artisan schedule:run >> /dev/null 2>&1
   ```
4. **Reverb** (WebSockets, proceso persistente):
   ```ini
   [program:roke-reverb]
   command=php /var/www/api/artisan reverb:start --host=0.0.0.0 --port=8080
   autostart=true
   autorestart=true
   ```
   Y un proxy TLS que exponga `wss://<host>` → `127.0.0.1:8080` (Nginx/Traefik).

---

## 3. Servidor de Coolify (runtime de apps)

Esto es lo que hace falta en el **servidor de Coolify**, no en el de la API:

1. **Server conectado y sano** en Coolify, con Docker corriendo. Su UUID =
   `COOLIFY_SERVER_UUID`.
2. **Puertos 80/443 abiertos** en el edge (Traefik de Coolify). Necesarios para:
   - servir las apps por su dominio,
   - que Let's Encrypt emita certificados (desafío HTTP‑01).
3. **Salida a internet** para que Coolify clone los repos de GitHub (usa el token de
   instalación efímero que genera la API).
4. **Token de API** con permisos para crear projects/applications y disparar deploys
   (= `COOLIFY_API_TOKEN`).

> Si el edge de Coolify solo vive en **Tailscale** (IP `100.x`), las apps solo serán
> alcanzables por VPN y **no** habrá TLS público (HTTP‑01 falla). Para acceso público
> el edge necesita IP pública + 80/443, o usar DNS‑01 con Cloudflare.

---

## 4. DNS (Cloudflare) — el wildcard de apps

Cada app deployada recibe un FQDN:
```
{project}-{env}-{hash}.{COMPUTE_APP_DOMAIN}
# ej. miapp-prod-a1b2c3.apps.rokeindustries.dev
```

Crear **un registro wildcard por ambiente**:

| Ambiente | Registro | Apunta a |
|---|---|---|
| DEV | `*.apps.rokeindustries.dev` `A` | IP pública del edge de Coolify (o Tailscale si es interno) |
| PROD | `*.apps.rokeindustries.com` `A` | IP pública del edge de Coolify de prod |

- **Grey cloud (DNS only)** si Coolify/Traefik maneja el TLS (Let's Encrypt).
- **Orange cloud (proxied)** solo si terminas TLS en Cloudflare (entonces el edge no
  necesita cert, pero hay que configurar el modo SSL Full).

> El proyecto ya tiene `CLOUDFLARE_API_TOKEN` + `CLOUDFLARE_ZONE_ID`, así que el
> registro se puede crear por API o desde el panel.

---

## 5. GitHub App — instalación y webhook

1. **Instalar la App** `roke-platform` (id `4039117`) en el/los repos a deployar.
2. **Webhook URL** (en los ajustes de la GitHub App):
   ```
   https://<api-pública>/api/v2/webhooks/github
   ```
   con el `GITHUB_WEBHOOK_SECRET` (verificación HMAC `X-Hub-Signature-256`).
   - DEV: `https://api-dev.rokeindustries.dev/api/v2/webhooks/github` (o túnel).
   - PROD: `https://api.rokeindustries.com/api/v2/webhooks/github`.
3. **GitHub no alcanza `localhost`.** Si pruebas en tu laptop necesitas un túnel
   (`cloudflared tunnel` / `ngrok`); en el **server dev** ya es público.
4. Flujo de conexión de repos (desde el front/admin):
   `GET /api/v2/github/install-url` → instalar → `POST /api/v2/github/installations/claim`.

> El push a una branch conectada dispara `HandlePushEvent` → auto-deploy (semana 4).

---

## 6. Checklist rápido — DEV

- [ ] `.env` con `APP_ENV=development`, `APP_DEBUG=true`, `QUEUE_CONNECTION=database` (o redis).
- [ ] `php artisan migrate` aplicado (tablas de cómputo).
- [ ] Worker corriendo en `provisioning,deployments,ai,default`.
- [ ] `reverb:start` corriendo + `wss://` proxied.
- [ ] Coolify dev alcanzable (`COOLIFY_URL`, token, server_uuid).
- [ ] `*.apps.rokeindustries.dev` creado en Cloudflare.
- [ ] GitHub App instalada en el repo de prueba; webhook → API dev pública.
- [ ] `.pem` de la App en la raíz; `GITHUB_APP_PRIVATE_KEY_BASE64` apuntando a él.
- [ ] (Opcional) `ANTHROPIC_API_KEY` para el agente IA.

## 7. Checklist rápido — PROD

- [ ] `.env` con `APP_ENV=production`, **`APP_DEBUG=false`**, `QUEUE_CONNECTION=redis`, `CACHE_DRIVER=redis`.
- [ ] `SESSION_SECURE_COOKIE=true`, CORS/Sanctum con dominios de prod.
- [ ] Claves **Stripe live** y `MAIL_MAILER` real.
- [ ] Migraciones aplicadas con `--force`; `config:cache`, `route:cache`, `event:cache`.
- [ ] Workers (Supervisor/systemd, ≥2 procesos) + Scheduler (cron) + Reverb como servicios.
- [ ] Coolify **prod** con edge público, 80/443 abiertos.
- [ ] `*.apps.rokeindustries.com` (o el dominio elegido) en Cloudflare.
- [ ] GitHub App con webhook → API prod pública; `.pem` colocado en el server.
- [ ] `ANTHROPIC_API_KEY` presente (agente + diagnóstico elocuente).
- [ ] **Horizon** corriendo (Supervisor) + Redis activo; dashboard `/horizon` (admin).
- [ ] **Sentry**: `SENTRY_LARAVEL_DSN` configurado; `horizon:terminate` en el deploy.

---

## 8. Smoke test (cualquier ambiente)

1. Crea team → project → environment (entidades de cómputo).
2. Conecta un repo vía GitHub App.
3. `POST /api/v2/environments/{env}/resources` `{ "kind":"app", "name":"api", ... }` → `202`.
4. Observa en vivo:
   - El worker corre la saga (provisión → build → deploy).
   - Logs de build llegan por Reverb (canal `private-deployment.{uuid}`).
   - Al terminar: `Resource.status = running`, `Deployment.status = success`, y la app
     responde en `https://{fqdn}`.
5. Forzar un fallo (repo que truene el build) → `error_summary` pasa de la cola cruda del
   log a la **causa legible** del diagnóstico + push.

Verificación de salud sin disparar nada:
```bash
php artisan tinker --execute="dump(config('compute.app_domain'), config('coolify.server_uuid'));"
php artisan queue:work --once --queue=provisioning   # procesa 1 job de prueba
```

---

## 9. Notas de seguridad

- `*.private-key.pem`, tokens de Coolify/GitHub/Stripe y `APP_KEY` **solo** en el `.env`
  del servidor; nunca en git.
- En prod, `COOLIFY_VERIFY_SSL=true` si el edge usa cert válido.
- El contenido de los logs de build se trata como **datos no confiables** en el prompt del
  diagnóstico (ya está mitigado en `DeploymentDiagnosis`).
