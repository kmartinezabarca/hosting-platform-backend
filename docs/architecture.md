# Arquitectura técnica — Hosting Platform Backend

## Visión general

El backend sigue una arquitectura **MVC clásica de Laravel** con capas de servicio para las integraciones externas. El sistema orquesta múltiples proveedores de infraestructura (Pterodactyl, HestiaCP, Proxmox, Namecheap, Cloudflare) bajo una API REST unificada, con autenticación stateful via cookies Sanctum y notificaciones en tiempo real por WebSockets (Reverb).

---

## Flujo de autenticación

### Login estándar

```
Cliente                     Backend                      Base de datos
   |                            |                               |
   |-- POST /auth/login ------->|                               |
   |   { email, password }      |-- Valida credenciales ------->|
   |                            |<-- Usuario encontrado ---------|
   |                            |                               |
   |                            |-- ¿2FA habilitado? ---------->|
   |                            |   Si: retorna { requires_2fa: true }
   |                            |   No: genera token Sanctum    |
   |                            |-- Guarda UserSession -------->|
   |<-- Set-Cookie: auth_token --|                               |
   |   (HttpOnly, SameSite)      |                               |
```

### Verificación 2FA

```
POST /auth/2fa/verify
  { code: "123456", temp_token: "xxx" }
→ Valida TOTP con Google Authenticator / Authy
→ Si válido: emite cookie auth_token completa
```

### Google OAuth

```
Frontend obtiene id_token de Google
→ POST /auth/google/callback { credential: "id_token" }
→ Backend verifica con Google API
→ Si usuario nuevo: retorna { setup_token, requires_setup: true }
→ Frontend redirige a /auth/complete-profile
→ POST /auth/complete-profile { setup_token, username }
→ Cookie emitida
```

### Middleware de autenticación

| Middleware | Función |
|---|---|
| `auth:sanctum` | Valida la cookie `auth_token` en cada request |
| `InjectTokenFromCookie` | Inyecta el token de la cookie en el header `Authorization` para que Sanctum lo procese |
| `session.timeout` | Cierra sesión automáticamente tras N minutos de inactividad (configurable en `.env`) |
| `admin` | Verifica que el usuario tenga rol `admin` o `support` |
| `TrackUserSession` | Registra IP, user-agent y timestamp en `UserSession` |
| `throttle:10,1` | Rate limit de 10 req/min para registro y OAuth |
| `throttle:5,1` | Rate limit de 5 req/min para login y 2FA |

---

## Modelos de datos

### User

| Campo | Tipo | Descripción |
|---|---|---|
| `uuid` | UUID | Identificador público |
| `name` | string | Nombre completo |
| `email` | string | Email único |
| `username` | string | Username único (requerido para Google OAuth completado) |
| `password` | hashed | Contraseña |
| `role` | enum | `client`, `admin`, `support` |
| `status` | enum | `active`, `suspended`, `pending` |
| `google_id` | string? | ID de cuenta Google |
| `avatar` | string? | URL del avatar |
| `two_factor_secret` | encrypted? | Secreto TOTP |
| `two_factor_enabled` | bool | Estado 2FA |

**Relaciones:** `services`, `invoices`, `tickets`, `paymentMethods`, `sessions`, `fiscalProfiles`, `notifications`

### Service

| Campo | Tipo | Descripción |
|---|---|---|
| `uuid` | UUID | Identificador público |
| `user_id` | FK | Propietario |
| `plan_id` | FK | Plan de servicio |
| `status` | enum | `active`, `suspended`, `cancelled`, `pending` |
| `category` | string | Tipo de servicio (`game_server`, `hosting`, `vps`, `database`) |
| `name` | string | Nombre descriptivo |
| `configuration` | JSON | Configuración técnica (IP, puerto, egg_id, etc.) |
| `billing_cycle` | string | Ciclo de facturación |
| `next_due_date` | date | Próxima fecha de cobro |
| `provisioned_at` | timestamp? | Fecha de aprovisionamiento |

**Relaciones:** `user`, `plan`, `invoices`, `tickets`, `backups`

### Invoice

| Campo | Tipo | Descripción |
|---|---|---|
| `uuid` | UUID | Identificador público |
| `user_id` | FK | Cliente |
| `service_id` | FK? | Servicio relacionado |
| `status` | enum | `pending`, `paid`, `cancelled`, `overdue` |
| `total` | decimal | Monto total |
| `currency` | string | `MXN` / `USD` |
| `due_date` | date | Fecha de vencimiento |
| `paid_at` | timestamp? | Fecha de pago |
| `stripe_payment_intent_id` | string? | ID de PaymentIntent Stripe |
| `cfdi_uuid` | string? | UUID del CFDI SAT |
| `cfdi_status` | enum? | Estado del timbrado CFDI |

**Relaciones:** `items`, `user`, `service`, `receipt`, `transactions`

### Ticket

| Campo | Tipo | Descripción |
|---|---|---|
| `uuid` | UUID | Identificador público |
| `user_id` | FK | Cliente |
| `service_id` | FK? | Servicio relacionado |
| `agent_id` | FK? | Agente asignado |
| `category_id` | FK? | Categoría |
| `status` | enum | `open`, `in_progress`, `waiting_client`, `resolved`, `closed` |
| `priority` | enum | `low`, `medium`, `high`, `critical` |
| `subject` | string | Asunto |
| `body` | text | Contenido inicial |

**Relaciones:** `user`, `agent`, `replies`, `service`

### ServicePlan

| Campo | Tipo | Descripción |
|---|---|---|
| `uuid` | UUID | Identificador público |
| `name` | string | Nombre del plan |
| `slug` | string | Slug URL |
| `category_id` | FK | Categoría |
| `status` | enum | `active`, `inactive` |
| `specs` | JSON | Especificaciones técnicas |
| `is_featured` | bool | Plan destacado |

**Relaciones:** `category`, `pricings`, `features`, `addOns`, `services`

### Quotation

| Campo | Tipo | Descripción |
|---|---|---|
| `uuid` | UUID | Identificador público |
| `token` | string | Token de acceso público (URL pública) |
| `status` | enum | `draft`, `sent`, `viewed`, `accepted`, `rejected`, `expired` |
| `client_name` / `client_email` | string | Datos del cliente |
| `items` | JSON | Líneas de cotización |
| `total` | decimal | Total |
| `valid_until` | date | Fecha de expiración |
| `version` | int | Número de versión (para revisiones) |

### GameSoftwareVersion

| Campo | Tipo | Descripción |
|---|---|---|
| `software` | string | Identificador (ej. `paper`, `vanilla`) |
| `version` | string | Número de versión |
| `download_url` | string? | URL de descarga |
| `is_enabled` | bool | Disponible para los clientes |
| `is_recommended` | bool | Versión recomendada |

### PterodactylEgg

| Campo | Tipo | Descripción |
|---|---|---|
| `pterodactyl_id` | int | ID en Pterodactyl Panel |
| `nest_id` | int | ID del nido en Pterodactyl |
| `name` | string | Nombre del egg |
| `display_name` | string? | Nombre visible para el cliente |
| `description` | text? | Descripción |
| `is_active` | bool | Visible en el catálogo |

---

## Integraciones externas

### Pterodactyl Panel

- **Propósito:** Aprovisionamiento, gestión y monitoreo de game servers.
- **Protocolo:** REST API + Wings WebSocket.
- **Autenticación:** API Key en header `Authorization: Bearer`.
- **Flujo de aprovisionamiento:**
  1. Admin crea servicio → `POST /admin/game-servers/{id}/provision`
  2. Backend llama a Pterodactyl API → crea servidor con egg, recursos y env vars
  3. Pterodactyl Wings provisiona el contenedor Docker
  4. Cliente obtiene credenciales WebSocket via `/game-server/websocket`
  5. Frontend conecta directamente a Wings para la consola en tiempo real

### HestiaCP

- **Propósito:** Hosting compartido (cuentas cPanel, bases de datos, emails, DNS).
- **Protocolo:** REST API.
- **Autenticación:** Token de API en header.
- **Operaciones:** Crear/suspender cuenta, gestionar bases de datos, emails y dominios.

### Proxmox

- **Propósito:** VPS (máquinas virtuales).
- **Protocolo:** REST API.
- **Autenticación:** Usuario + contraseña + ticket de sesión.
- **Operaciones:** Crear VM, snapshot, power control.

### Stripe

- **Propósito:** Procesamiento de pagos.
- **Integración frontend:** `@stripe/react-stripe-js` con SetupIntent para guardar tarjetas.
- **Integración backend:** Laravel Cashier + webhooks (`POST /stripe/webhook`).
- **Flujo de pago:**
  1. Frontend solicita `POST /payments/setup-intent` → Stripe SetupIntent
  2. Stripe.js captura datos de tarjeta de forma segura
  3. Método guardado como `PaymentMethod` en la DB
  4. `POST /payments/process` → cobra PaymentIntent en Stripe → crea `Transaction` + actualiza `Invoice`

### Facturama (CFDI 4.0 SAT México)

- **Propósito:** Emisión de facturas electrónicas conforme al SAT.
- **Flujo:**
  1. Pago exitoso → backend genera CFDI via Facturama API
  2. SAT timbra el comprobante → devuelve UUID + XML sellado
  3. Se guarda `cfdi_uuid` en `Invoice`
  4. Cliente puede descargar PDF/XML via `/invoices/{uuid}/pdf` y `/xml`
  5. Si falla el timbrado: admin puede reintentar via `/admin/cfdi/{id}/retry`
  6. Cancelación: `/admin/cfdi/{id}/cancel` → cancela ante el SAT

### Namecheap

- **Propósito:** Registro y gestión de dominios.
- **Operaciones:** Verificar disponibilidad, registrar, renovar, transferir.

### Cloudflare

- **Propósito:** DNS y protección para dominios de clientes.
- **Operaciones:** Crear/actualizar registros DNS, gestionar zonas.

### SendGrid

- **Propósito:** Correo transaccional.
- **Usos:** Verificación de email, reset de contraseña, recordatorios de facturas, notificaciones de soporte.

### Google OAuth

- **Propósito:** Login social.
- **Flujo:** Frontend obtiene `id_token` de Google → backend verifica con Google API → crea/asocia usuario.
- **Panel admin:** Valida adicionalmente que el usuario tenga rol `admin` o `support`.

---

## WebSockets (Laravel Reverb)

- **Servidor:** Laravel Reverb (alternativa open-source a Pusher).
- **Configuración:** `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`.
- **Autenticación de canales privados:** `POST /broadcasting/auth` valida la cookie Sanctum.
- **Canales:**
  - `private-user.{userId}` — notificaciones personales del cliente
  - `private-admin.{userId}` — notificaciones del panel admin
  - `private-chat.{roomId}` — mensajes de chat de soporte en tiempo real
  - Game server: el cliente conecta **directamente** a Wings (Pterodactyl), no pasa por Reverb

---

## Sistema de colas (Redis)

| Cola | Uso |
|---|---|
| `default` | Trabajos generales |
| `emails` | Envío de correos via SendGrid |
| `notifications` | Notificaciones push a usuarios |
| `cfdi` | Timbrado y cancelación de CFDIs |
| `provisioning` | Aprovisionamiento de servidores (largo plazo) |

Comando para procesar: `php artisan queue:work redis --queue=emails,notifications,cfdi,provisioning,default`

---

## Arquitectura de módulos

```
┌─────────────────────────────────────────────────────────────────┐
│                         Frontend (React)                        │
│                Portal Cliente         Panel Admin               │
└───────────────────────┬────────────────────────┬────────────────┘
                        │ HTTPS (REST API)        │
                        ▼                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Laravel 10 API Backend                        │
│                                                                 │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────┐   │
│  │Auth Layer│  │  Client  │  │  Admin   │  │  Public API  │   │
│  │ Sanctum  │  │ Routes   │  │  Routes  │  │  (no auth)   │   │
│  └──────────┘  └──────────┘  └──────────┘  └──────────────┘   │
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                    Service Layer                          │  │
│  │  PterodactylService  HestiaService  ProxmoxService       │  │
│  │  StripeService       FacturamaService  NamecheapService  │  │
│  │  CloudflareService   SendGridService                     │  │
│  └──────────────────────────────────────────────────────────┘  │
└────────────────────────────┬────────────────────────────────────┘
                             │
           ┌─────────────────┼──────────────────┐
           ▼                 ▼                  ▼
      ┌─────────┐      ┌──────────┐       ┌─────────┐
      │  MySQL  │      │  Redis   │       │ Reverb  │
      │   DB    │      │ Cache/Q  │       │   WS    │
      └─────────┘      └──────────┘       └─────────┘
           │
     ┌─────┴────────────────────────────────┐
     ▼                    ▼                 ▼
┌──────────┐       ┌──────────┐      ┌──────────┐
│Pterodactyl│      │ HestiaCP │      │ Proxmox  │
│  Panel   │       │  Panel   │      │   API    │
└──────────┘       └──────────┘      └──────────┘
     │
     ▼
┌──────────┐
│  Wings   │ ←── WebSocket directo desde el navegador del cliente
│  (Docker)│
└──────────┘
```

---

## Flujo de aprovisionamiento de servicios

### Game Server

```
1. Cliente hace checkout → POST /services/contract
   { plan_id, configuration: { egg_id, game, ... } }

2. Backend crea Service en DB (status: pending)

3. Admin confirma / pago procesado → POST /admin/game-servers/{id}/provision

4. PterodactylService:
   - Crea usuario en Pterodactyl (si no existe)
   - Crea servidor con egg_id, resources (ram, disk, cpu), env vars
   - Pterodactyl retorna server_id y credenciales

5. Backend actualiza Service:
   - configuration.pterodactyl_id = server_id
   - status = active

6. Cliente accede al servidor:
   - GET /services/{uuid}/game-server/websocket → obtiene token Wings
   - Frontend conecta WebSocket a Wings para consola en tiempo real
```

### Hosting

```
1. POST /services/contract → Service creado (status: pending)
2. Admin provisiona → HestiaService.createAccount(domain, username, password, package)
3. HestiaCP crea cuenta con bases de datos, emails, FTP
4. Service.status = active, configuration.hestia_user = username
```

---

## Ciclo de vida de facturas y CFDI

```
Pago exitoso
    │
    ▼
Invoice.status = paid
Invoice.paid_at = now()
    │
    ▼
Job: GenerateCfdi (cola cfdi)
    │
    ├──► Facturama API → timbrar CFDI
    │         │
    │         ├─► Éxito: Invoice.cfdi_uuid = UUID_SAT
    │         │         Invoice.cfdi_status = stamped
    │         │         Envía email con PDF+XML al cliente
    │         │
    │         └─► Error: Invoice.cfdi_status = failed
    │                   Admin puede reintentar via /admin/cfdi/{id}/retry
    │
    └──► Crea Receipt (comprobante de pago interno)
         Envía email de confirmación de pago
```

---

## Seguridad

| Medida | Implementación |
|---|---|
| Autenticación | Cookie HttpOnly (no accesible por JS) + Sanctum |
| CSRF | Token XSRF-TOKEN en cookie + header X-XSRF-TOKEN |
| 2FA | TOTP (Google Authenticator / Authy) |
| Rate limiting | Throttle middleware (10 req/min registro, 5 req/min login) |
| Sesión timeout | Middleware `session.timeout` (inactividad configurable) |
| Roles | Middleware `admin` valida rol antes de cualquier ruta admin |
| Contraseñas | bcrypt hashing (Laravel Hash facade) |
| Secreto 2FA | Encriptado en DB con `encrypted` cast de Eloquent |
| API Keys externas | Solo en `.env` (nunca en código fuente) |
| Dominios Sanctum | `SANCTUM_STATEFUL_DOMAINS` lista blanca estricta |
