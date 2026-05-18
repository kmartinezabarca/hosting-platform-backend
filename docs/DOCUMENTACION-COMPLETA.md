---
title: ROKE Industries - Documentacion Tecnica Completa
pdf_options:
  format: A4
  margin: 22mm 20mm 22mm 20mm
  printBackground: true
  displayHeaderFooter: true
  headerTemplate: "<div style='font-size:8pt;color:#9ca3af;width:100%;text-align:right;padding-right:20mm;font-family:sans-serif;'>ROKE Industries — Documentacion Tecnica</div>"
  footerTemplate: "<div style='font-size:8pt;color:#9ca3af;width:100%;text-align:center;font-family:sans-serif;'><span class=pageNumber></span> / <span class=totalPages></span></div>"
---

<div style="page-break-after:always;text-align:center;padding:80px 40px 60px;">
  <p style="font-size:11pt;color:#6c63ff;font-weight:600;letter-spacing:0.15em;text-transform:uppercase;margin-bottom:16px;">ROKE Industries</p>
  <div style="font-size:26pt;font-weight:700;color:#1e1b2e;line-height:1.25;margin-bottom:24px;">Documentacion Tecnica<br>Completa</div>
  <p style="font-size:11pt;color:#6b7280;margin-bottom:8px;">Hosting Platform &mdash; Backend &amp; Frontend</p>
  <p style="font-size:9.5pt;color:#9ca3af;">15 de mayo de 2026</p>
  <hr style="margin:48px auto;width:80px;border:0;border-top:3px solid #6c63ff;" />
  <p style="font-size:9.5pt;color:#9ca3af;line-height:1.8;">
    API Reference &middot; Arquitectura Tecnica &middot; Funcionalidades del Sistema
  </p>
</div>

<div style="page-break-before:always;background:#1e1b2e;color:white;padding:40px 40px 40px;margin-bottom:40px;text-align:center;">
  <p style="font-size:9pt;color:#6c63ff;font-weight:600;letter-spacing:0.2em;text-transform:uppercase;margin-bottom:12px;">PARTE I</p>
  <div style="font-size:22pt;font-weight:700;margin-bottom:8px;color:white;">Backend</div>
  <p style="font-size:11pt;color:#9ca3af;margin:0;">Laravel 10 &middot; PHP 8.2 &middot; MySQL &middot; Redis &middot; Reverb</p>
</div>

# ROKE Industries — Hosting Platform Backend

## Descripción general

API REST construida con **Laravel 10** que alimenta el portal de clientes y el panel de administración de ROKE Industries. Gestiona usuarios, servicios de hosting, servidores de juego, VPS, facturación electrónica (CFDI 4.0), soporte y cotizaciones.

---

## Stack tecnológico

| Componente | Tecnología |
|---|---|
| Framework | Laravel 10 |
| Lenguaje | PHP 8.2+ |
| Base de datos | MySQL / MariaDB |
| Caché / Colas | Redis |
| Autenticación | Laravel Sanctum (cookies HttpOnly) |
| WebSockets | Laravel Reverb + Broadcasting |
| Correo | SendGrid |
| Pagos | Stripe |
| Facturación electrónica | Facturama (CFDI 4.0 SAT México) |
| Game servers | Pterodactyl Panel API + Wings WebSocket |
| Hosting compartido | HestiaCP API |
| VPS | Proxmox API |
| DNS / Dominios | Namecheap API + Cloudflare API |

---

## Estructura de directorios

```
app/
├── Console/            # Comandos artisan
├── Events/             # Eventos de broadcasting
├── Exceptions/         # Manejo global de excepciones
├── Http/
│   ├── Controllers/
│   │   ├── Admin/      # Controladores del panel admin
│   │   ├── Auth/       # Autenticación, 2FA, OAuth
│   │   ├── Client/     # Controladores del portal cliente
│   │   ├── Api/        # Endpoints públicos
│   │   └── Common/     # Compartidos (webhooks, etc.)
│   └── Middleware/     # Middlewares personalizados
├── Models/             # Modelos Eloquent
├── Notifications/      # Notificaciones (DB + Email)
├── Providers/          # Service providers
└── Services/           # Lógica de negocio / integraciones externas
routes/
├── api.php             # Rutas públicas + hosting
├── auth.php            # Autenticación
├── client.php          # Portal cliente (auth requerida)
└── admin.php           # Panel admin (auth + rol admin)
```

---

## Documentación detallada

- [API Reference](api-reference.md) — Listado completo de endpoints, parámetros y respuestas
- [Arquitectura técnica](architecture.md) — Diseño del sistema, integraciones, flujo de autenticación, modelos de datos

---

## Configuración rápida

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan reverb:start      # WebSockets
php artisan queue:work redis  # Colas
```

### Variables de entorno principales

| Variable | Descripción |
|---|---|
| `APP_URL` | URL base de la aplicación |
| `DB_*` | Conexión MySQL |
| `REDIS_*` | Conexión Redis |
| `STRIPE_KEY` / `STRIPE_SECRET` | Credenciales Stripe |
| `PTERODACTYL_API_URL` / `PTERODACTYL_API_KEY` | API de Pterodactyl Panel |
| `HESTIA_HOST` / `HESTIA_TOKEN` | API de HestiaCP |
| `PROXMOX_HOST` / `PROXMOX_USER` / `PROXMOX_PASSWORD` | API de Proxmox |
| `NAMECHEAP_API_KEY` / `NAMECHEAP_USERNAME` | Namecheap API |
| `CLOUDFLARE_TOKEN` / `CLOUDFLARE_ZONE_ID` | Cloudflare API |
| `FACTURAMA_USER` / `FACTURAMA_PASSWORD` | Facturama (CFDI) |
| `SENDGRID_API_KEY` | SendGrid correo transaccional |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | Laravel Reverb WebSockets |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | Google OAuth |
| `SANCTUM_STATEFUL_DOMAINS` | Dominios permitidos Sanctum |
| `SESSION_DOMAIN` | Dominio de la cookie de sesión |


---

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


---

# API Reference — Hosting Platform Backend

> Base URL: `https://<your-domain>/api`
>
> Autenticación: **Cookie HttpOnly** `auth_token` (Laravel Sanctum). Enviar con `withCredentials: true`.  
> CSRF: Header `X-XSRF-TOKEN` obtenido de la cookie `XSRF-TOKEN`.  
> Formato: `Content-Type: application/json`.

---

## Índice

- [Públicos / Sin autenticación](#públicos--sin-autenticación)
- [Autenticación](#autenticación)
- [Portal Cliente](#portal-cliente)
  - [Dashboard](#dashboard-cliente)
  - [Perfil](#perfil)
  - [Servicios](#servicios)
  - [Game Servers](#game-servers-cliente)
  - [Gestión de archivos](#gestión-de-archivos)
  - [Pagos y suscripciones](#pagos-y-suscripciones)
  - [Facturas](#facturas)
  - [Transacciones](#transacciones)
  - [Tickets de soporte](#tickets-de-soporte)
  - [Dominios](#dominios)
  - [Notificaciones](#notificaciones-cliente)
  - [Fiscal / CFDI](#fiscal--cfdi-cliente)
  - [Chat de soporte](#chat-de-soporte)
  - [Hosting (HestiaCP)](#hosting-hestiacp)
- [Panel Admin](#panel-admin)
  - [Dashboard admin](#dashboard-admin)
  - [Usuarios](#usuarios-admin)
  - [Servicios admin](#servicios-admin)
  - [Facturas admin](#facturas-admin)
  - [Tickets admin](#tickets-admin)
  - [Agentes de soporte](#agentes-de-soporte)
  - [Game Servers admin](#game-servers-admin)
  - [Versiones de software](#versiones-de-software-admin)
  - [Pterodactyl (Eggs)](#pterodactyl-eggs-admin)
  - [Cotizaciones](#cotizaciones-admin)
  - [CFDI admin](#cfdi-admin)
  - [Fiscal admin](#fiscal-admin)
  - [Notificaciones admin](#notificaciones-admin)
  - [Chat admin](#chat-admin)
  - [Catálogo admin](#catálogo-admin)
  - [Blog admin](#blog-admin)
  - [Documentación admin](#documentación-admin)
  - [Sistema y búsqueda](#sistema-y-búsqueda-admin)

---

## Públicos / Sin autenticación

### Catálogo

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/categories` | Lista de categorías de servicios |
| GET | `/categories/with-plans` | Categorías con sus planes incluidos |
| GET | `/categories/slug/{slug}` | Categoría por slug |
| GET | `/billing-cycles` | Ciclos de facturación disponibles |
| GET | `/billing-cycles/{uuid}` | Detalle de un ciclo |
| GET | `/service-plans` | Todos los planes de servicio |
| GET | `/service-plans/{uuid}` | Detalle de un plan |
| GET | `/service-plans/category/{categorySlug}` | Planes por categoría |
| GET | `/service-plans/add-ons/{planSlug}` | Add-ons disponibles para un plan |
| GET | `/products` | Lista de productos |
| GET | `/products/{uuid}` | Detalle de producto |
| GET | `/products/service-type/{serviceType}` | Productos por tipo de servicio |
| GET | `/marketing-services` | Servicios de marketing |

### Game Eggs (catálogo de juegos)

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/game-eggs?id={planUuid}` | Huevos (eggs) de Pterodactyl agrupados por nido para un plan dado |
| GET | `/game-eggs/{id}` | Detalle de un egg específico |

### Software / Versiones

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/software/{identifier}/versions` | Versiones disponibles de un software (ej. `vanilla`, `paper`) |

### Blog

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/blog/posts` | Lista de posts publicados |
| GET | `/blog/posts/featured` | Posts destacados |
| GET | `/blog/posts/{slug}` | Post por slug |
| GET | `/blog/categories` | Categorías del blog |
| GET | `/blog/categories/{categorySlug}/posts` | Posts de una categoría |
| POST | `/blog/subscribe` | Suscribirse al blog |
| POST | `/blog/unsubscribe/{uuid}` | Cancelar suscripción |

### Documentación pública

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/documentation` | Lista de artículos de documentación |
| GET | `/documentation/{slug}` | Artículo por slug |
| GET | `/api-documentation` | Índice de documentación API |
| GET | `/api-documentation/{slug}` | Artículo de doc API |
| POST | `/documentation-requests` | Solicitar un artículo de documentación |
| GET | `/system-status` | Estado del sistema |

### Cotizaciones públicas

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/quotations/public/{token}` | Ver cotización por token (sin auth) |
| POST | `/quotations/public/{token}/viewed` | Marcar cotización como vista |

### Utilidades

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/postal-codes/{code}` | Información de código postal SAT México |
| POST | `/stripe/webhook` | Webhook de eventos Stripe (sin auth) |
| GET | `/docs` | JSON OpenAPI / Swagger |

---

## Autenticación

> Throttle: **10 req/min** en register/Google; **5 req/min** en login/2FA.

### Endpoints públicos de auth

| Método | Endpoint | Descripción |
|---|---|---|
| POST | `/auth/login` | Login con email y contraseña |
| POST | `/auth/register` | Registro de nuevo usuario |
| POST | `/auth/2fa/verify` | Verificar código TOTP al iniciar sesión |
| POST | `/auth/google/callback` | OAuth Google (portal cliente) |
| POST | `/admin/auth/google/callback` | OAuth Google (panel admin; valida rol) |
| GET | `/auth/username/check?username=X` | Verificar disponibilidad de username |
| POST | `/auth/complete-profile` | Completar perfil Google (requiere `setup_token` + `username`) |
| POST | `/forgot-password` | Solicitar email de restablecimiento de contraseña |
| POST | `/reset-password` | Restablecer contraseña con token |

### Endpoints protegidos de auth

> Requieren cookie de sesión válida (`auth:sanctum`).

| Método | Endpoint | Descripción |
|---|---|---|
| POST | `/auth/logout` | Cerrar sesión (invalida cookie) |
| GET | `/auth/me` | Usuario autenticado actual |
| GET | `/user` | Alias de `/auth/me` |
| POST | `/auth/setup-username` | Asignar username a usuario autenticado sin uno |
| GET | `/2fa/status` | Estado actual de 2FA |
| POST | `/2fa/generate` | Generar secreto TOTP (devuelve QR) |
| POST | `/2fa/enable` | Activar 2FA con código de verificación |
| POST | `/2fa/disable` | Desactivar 2FA |
| POST | `/2fa/verify` | Verificar código TOTP (cuando ya está autenticado) |

---

## Portal Cliente

> Middleware: `auth:sanctum` + `session.timeout` en todas las rutas.

### Dashboard cliente

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/dashboard/stats` | Métricas del cliente (servicios activos, gasto mensual, tickets, dominios) |
| GET | `/dashboard/services` | Servicios del cliente para el dashboard |
| GET | `/dashboard/activity` | Actividad reciente del cliente |

### Perfil

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/profile` | Obtener perfil del usuario |
| PUT | `/profile` | Actualizar datos del perfil |
| POST | `/profile/avatar` | Subir foto de perfil |
| PUT | `/profile/email` | Cambiar email |
| PUT | `/profile/password` | Cambiar contraseña |
| POST | `/profile/email/verification-notification` | Reenviar correo de verificación (throttled) |
| GET | `/profile/devices` | Sesiones activas del usuario |
| GET | `/profile/security` | Resumen de seguridad (2FA, sesiones) |
| DELETE | `/profile/account` | Eliminar cuenta |
| DELETE | `/profile/sessions/{uuid}` | Revocar sesión específica |
| POST | `/profile/devices/revoke-others` | Revocar todas las sesiones excepto la actual |

### Servicios

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/services/plans` | Planes disponibles para contratación |
| POST | `/services/contract` | Contratar un nuevo servicio |
| GET | `/services/user` | Servicios del usuario autenticado |
| GET | `/services/metrics` | Métricas de todos los servicios del usuario |
| GET | `/services/{uuid}` | Detalle de un servicio |
| GET | `/services/{uuid}/invoices` | Facturas de un servicio |
| PATCH | `/services/{uuid}/configuration` | Actualizar configuración del servicio |
| PUT | `/services/{uuid}/config` | Alias de configuración |
| POST | `/services/{uuid}/cancel` | Solicitar cancelación |
| POST | `/services/{uuid}/suspend` | Suspender servicio |
| POST | `/services/{uuid}/reactivate` | Reactivar servicio |
| GET | `/services/{uuid}/backups` | Listar backups |
| POST | `/services/{uuid}/backups` | Crear backup |
| POST | `/services/{uuid}/backups/{backupId}/restore` | Restaurar backup |

### Game Servers cliente

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/services/game-servers/{nest_id}/eggs` | Eggs disponibles por nido |
| GET | `/services/{uuid}/game-server/startup` | Configuración de arranque del servidor |
| GET | `/services/{uuid}/game-server/usage` | Métricas en tiempo real (CPU, RAM, red, disco) |
| POST | `/services/{uuid}/game-server/power` | Control de energía (`start` / `stop` / `restart` / `kill`) |
| GET | `/services/{uuid}/game-server/websocket` | Credenciales WebSocket de Wings para la consola |
| POST | `/services/{uuid}/game-server/command` | Enviar comando a la consola |
| GET | `/services/{uuid}/game-server/software-options` | Opciones de software disponibles (eggs del plan) |
| GET | `/services/{uuid}/game-server/configuration` | Configuración actual (software, server.properties, EULA, Java) |
| PATCH | `/services/{uuid}/game-server/software` | Cambiar software / versión |
| PATCH | `/services/{uuid}/game-server/server-properties` | Actualizar `server.properties` |
| POST | `/services/{uuid}/game-server/restart-required` | Marcar que se requiere reinicio |
| GET | `/services/{uuid}/game-server/eula` | Estado de aceptación de EULA (Minecraft Java) |
| POST | `/services/{uuid}/game-server/eula/accept` | Aceptar EULA de Minecraft |
| POST | `/services/{uuid}/game-server/fix-java` | Corregir versión de Java del servidor |
| GET | `/services/{uuid}/game-server/java-check` | Verificar compatibilidad de Java |
| POST | `/services/{uuid}/game-server/java-autofix` | Auto-corregir compatibilidad de Java |
| GET | `/services/{uuid}/game-server/java-requirements` | Requisitos de Java para el egg activo |

### Gestión de archivos

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/services/{uuid}/files/list` | Listar archivos y directorios |
| GET | `/services/{uuid}/files/upload` | Obtener URL de subida (Wings) |
| POST | `/services/{uuid}/files/delete` | Eliminar archivos |
| GET | `/services/{uuid}/files/download` | Obtener URL de descarga |

### Pagos y suscripciones

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/payments/methods` | Métodos de pago guardados |
| POST | `/payments/methods` | Agregar método de pago |
| PUT | `/payments/methods/{id}` | Actualizar (ej. predeterminado) |
| DELETE | `/payments/methods/{id}` | Eliminar método de pago |
| POST | `/payments/setup-intent` | Crear SetupIntent de Stripe |
| POST | `/payments/intent` | Alias de setup-intent |
| POST | `/payments/process` | Procesar pago de una factura |
| GET | `/payments/stats` | Estadísticas de pagos |
| GET | `/payments/transactions` | Historial de transacciones via pagos |
| GET | `/subscriptions` | Suscripciones activas del usuario |
| POST | `/subscriptions` | Crear suscripción |
| GET | `/subscriptions/{id}` | Detalle de suscripción |
| POST | `/subscriptions/{id}/cancel` | Cancelar suscripción |
| POST | `/subscriptions/{id}/resume` | Reanudar suscripción |

### Facturas

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/invoices` | Lista de facturas (soporta paginación y filtros) |
| GET | `/invoices/stats` | Estadísticas de facturación |
| GET | `/invoices/{uuid}` | Detalle de factura |
| GET | `/invoices/{uuid}/receipt` | Descargar comprobante de pago (blob PDF) |
| GET | `/invoices/{uuid}/pdf` | Descargar CFDI en PDF |
| GET | `/invoices/{uuid}/xml` | Descargar CFDI en XML |
| PUT | `/invoices/{uuid}/fiscal-data` | Actualizar RFC/datos CFDI (ventana de 72h tras el pago) |

### Transacciones

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/transactions` | Historial de transacciones |
| GET | `/transactions/stats` | Estadísticas de transacciones |
| GET | `/transactions/recent` | Transacciones recientes (`?limit=N`) |
| GET | `/transactions/{uuid}` | Detalle de transacción |

### Tickets de soporte

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/tickets` | Lista de tickets del usuario |
| POST | `/tickets` | Crear nuevo ticket |
| GET | `/tickets/stats` | Estadísticas de tickets |
| GET | `/tickets/{uuid}` | Detalle de ticket |
| POST | `/tickets/{uuid}/reply` | Agregar respuesta (admite adjuntos) |
| PUT | `/tickets/{uuid}/close` | Cerrar ticket |

### Dominios

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/domains` | Dominios del usuario |
| POST | `/domains` | Registrar dominio |
| GET | `/domains/stats` | Estadísticas de dominios |
| POST | `/domains/check-availability` | Verificar disponibilidad de un dominio |
| GET | `/domains/{uuid}` | Detalle de dominio |
| PUT | `/domains/{uuid}` | Actualizar dominio |
| POST | `/domains/{uuid}/renew` | Renovar dominio |

### Notificaciones cliente

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/notifications` | Lista de notificaciones |
| GET | `/notifications/unread-count` | Conteo de no leídas |
| GET | `/notifications/preferences` | Preferencias de notificación |
| PUT | `/notifications/preferences` | Actualizar preferencias |
| PUT | `/notifications/{notification}/read` | Marcar como leída |
| PUT | `/notifications/mark-all-read` | Marcar todas como leídas |
| DELETE | `/notifications/{notification}` | Eliminar notificación |

### Fiscal / CFDI cliente

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/fiscal/regimes` | Regímenes fiscales SAT disponibles |
| GET | `/fiscal/cfdi-uses` | Usos de CFDI SAT disponibles |
| GET | `/fiscal/profiles` | Perfiles fiscales del usuario |
| POST | `/fiscal/profiles` | Crear perfil fiscal |
| GET | `/fiscal/profiles/{uuid}` | Detalle de perfil |
| PUT | `/fiscal/profiles/{uuid}` | Actualizar perfil |
| DELETE | `/fiscal/profiles/{uuid}` | Eliminar perfil |
| PUT | `/fiscal/profiles/{uuid}/set-default` | Establecer perfil predeterminado |

### Chat de soporte

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/chat/support-room` | Obtener o crear sala de soporte del usuario |
| GET | `/chat/unread-count` | Mensajes no leídos |
| GET | `/chat/history` | Historial de chats |
| GET | `/chat/{ticket}/messages` | Mensajes de un chat |
| POST | `/chat/{ticket}/messages` | Enviar mensaje |
| PUT | `/chat/{ticket}/read` | Marcar mensajes como leídos |
| PUT | `/chat/{ticket}/close` | Cerrar sala de chat |

### Búsqueda cliente

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/search?q={query}` | Búsqueda en el portal cliente |
| GET | `/search/popular` | Términos de búsqueda populares |

### Hosting (HestiaCP)

> Requieren `auth:sanctum` + `session.timeout`.

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/hosting/{uuid}/info` | Información general del hosting |
| GET | `/hosting/{uuid}/files` | Archivos del hosting |
| GET | `/hosting/{uuid}/databases` | Bases de datos |
| POST | `/hosting/{uuid}/databases` | Crear base de datos |
| DELETE | `/hosting/{uuid}/databases/{db}` | Eliminar base de datos |
| GET | `/hosting/{uuid}/emails` | Cuentas de email |
| POST | `/hosting/{uuid}/emails` | Crear cuenta de email |
| DELETE | `/hosting/{uuid}/emails/{account}` | Eliminar cuenta de email |
| GET | `/hosting/{uuid}/domains` | Dominios del hosting |
| POST | `/hosting/{uuid}/domains` | Agregar dominio |
| DELETE | `/hosting/{uuid}/domains/{domain}` | Eliminar dominio |
| GET | `/hosting/{uuid}/stats` | Estadísticas de uso |

---

## Panel Admin

> Middleware: `auth:sanctum` + `session.timeout` + `admin`.  
> Todas las rutas usan el prefijo `/admin`.

### Dashboard admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/dashboard/stats` | Métricas globales del sistema |

### Usuarios admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/users` | Lista de usuarios (filtros: `search`, `status`, `role`, `page`, `per_page`) |
| POST | `/admin/users` | Crear usuario |
| PUT | `/admin/users/{id}` | Actualizar usuario |
| DELETE | `/admin/users/{id}` | Eliminar usuario |
| PUT | `/admin/users/{id}/status` | Cambiar estado del usuario |

### Servicios admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/services` | Lista de servicios (filtros: `search`, `status`, `plan_id`, `page`, `per_page`) |
| POST | `/admin/services` | Crear servicio |
| GET | `/admin/services/{uuid}` | Detalle de servicio |
| PUT | `/admin/services/{uuid}` | Actualizar servicio |
| DELETE | `/admin/services/{uuid}` | Eliminar servicio |
| PUT | `/admin/services/{uuid}/status` | Cambiar estado (`active` / `suspended` / `cancelled`) |
| GET | `/admin/services/{uuid}/support-overview` | Vista de soporte del servicio (tickets relacionados) |

### Facturas admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/invoices` | Lista de facturas |
| GET | `/admin/invoices/stats` | Estadísticas de facturación |
| POST | `/admin/invoices` | Crear factura |
| PUT | `/admin/invoices/{id}` | Actualizar factura |
| DELETE | `/admin/invoices/{id}` | Eliminar factura |
| PUT | `/admin/invoices/{id}/status` | Cambiar estado |
| POST | `/admin/invoices/{id}/mark-paid` | Marcar como pagada |
| POST | `/admin/invoices/{id}/send-reminder` | Enviar recordatorio de pago |
| POST | `/admin/invoices/{id}/cancel` | Cancelar factura |
| GET | `/admin/invoices/{uuid}/receipt` | Descargar comprobante (admin) |
| GET | `/admin/invoices/{serviceId}` | Facturas por servicio |

### Tickets admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/tickets` | Lista de tickets |
| POST | `/admin/tickets` | Crear ticket |
| GET | `/admin/tickets/stats` | Estadísticas |
| GET | `/admin/tickets/categories` | Categorías de tickets |
| GET | `/admin/support-agents` | Agentes disponibles |
| GET | `/admin/tickets/{id}` | Detalle de ticket |
| PUT | `/admin/tickets/{id}` | Actualizar ticket |
| DELETE | `/admin/tickets/{id}` | Eliminar ticket |
| PUT | `/admin/tickets/{id}/status` | Cambiar estado |
| PUT | `/admin/tickets/{id}/priority` | Cambiar prioridad |
| POST | `/admin/tickets/{id}/assign` | Asignar a agente |
| POST | `/admin/tickets/{id}/reply` | Agregar respuesta |

### Agentes de soporte

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/tickets/agents` | Lista de agentes |
| POST | `/admin/tickets/agents` | Crear agente |
| GET | `/admin/tickets/agents/statistics` | Estadísticas de agentes |
| GET | `/admin/tickets/agents/recommended` | Agente recomendado para asignación |
| GET | `/admin/tickets/agents/{uuid}` | Detalle de agente |
| PUT | `/admin/tickets/agents/{uuid}` | Actualizar agente |
| DELETE | `/admin/tickets/agents/{uuid}` | Eliminar agente |
| POST | `/admin/tickets/agents/{uuid}/assign-ticket` | Asignar ticket a agente |
| GET | `/admin/tickets/agents/{uuid}/tickets` | Tickets asignados a un agente |

### Game Servers admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/game-servers` | Lista de game servers |
| GET | `/admin/game-servers/{uuid}` | Detalle de game server |
| POST | `/admin/game-servers/{id}/provision` | Provisionar servidor en Pterodactyl |
| POST | `/admin/game-servers/{id}/suspend` | Suspender servidor |
| POST | `/admin/game-servers/{id}/unsuspend` | Desuspender servidor |
| POST | `/admin/game-servers/{id}/reinstall` | Reinstalar servidor |
| DELETE | `/admin/game-servers/{id}` | Terminar y eliminar servidor |
| GET | `/admin/game-servers/{id}/websocket` | Credenciales WebSocket (bypass admin) |
| GET | `/admin/game-servers/{id}/usage` | Métricas en tiempo real |
| POST | `/admin/game-servers/{id}/power` | Control de energía |
| POST | `/admin/game-servers/{id}/command` | Enviar comando |
| GET | `/admin/game-servers/{id}/files/list` | Listar archivos |
| GET | `/admin/game-servers/{id}/files/upload` | URL de subida |
| POST | `/admin/game-servers/{id}/files/delete` | Eliminar archivos |
| GET | `/admin/game-servers/{id}/files/download` | URL de descarga |

### Versiones de software admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/game-versions` | Lista de versiones (filtros: `software`, `enabled`, `page`) |
| POST | `/admin/game-versions` | Crear versión |
| PUT | `/admin/game-versions/{id}` | Actualizar versión |
| DELETE | `/admin/game-versions/{id}` | Eliminar versión |
| POST | `/admin/game-versions/bulk/{action}` | Acciones en lote (`enable` / `disable` / `delete`) |

### Pterodactyl (Eggs) admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/pterodactyl/eggs` | Lista de eggs registrados |
| PATCH | `/admin/pterodactyl/eggs/{id}` | Actualizar egg |
| POST | `/admin/pterodactyl/eggs/{id}/toggle` | Activar / desactivar egg |
| POST | `/admin/pterodactyl/eggs/sync` | Sincronizar eggs desde Pterodactyl Panel |

### Cotizaciones admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/quotations` | Lista de cotizaciones |
| POST | `/admin/quotations` | Crear cotización |
| GET | `/admin/quotations/{quotation}` | Detalle |
| PUT | `/admin/quotations/{quotation}` | Actualizar |
| DELETE | `/admin/quotations/{quotation}` | Eliminar |
| POST | `/admin/quotations/{quotation}/send` | Enviar cotización al cliente |
| POST | `/admin/quotations/{quotation}/accept` | Marcar como aceptada |
| POST | `/admin/quotations/{quotation}/reject` | Marcar como rechazada |
| POST | `/admin/quotations/{quotation}/reopen` | Reabrir cotización |
| POST | `/admin/quotations/{quotation}/revision` | Crear revisión / nueva versión |
| POST | `/admin/quotations/{quotation}/regenerate-link` | Regenerar link público |

### CFDI admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/cfdi` | Lista de CFDIs con filtros |
| GET | `/admin/cfdi/stats` | Estadísticas de timbrado |
| GET | `/admin/cfdi/{id}` | Detalle de CFDI |
| POST | `/admin/cfdi/{id}/retry` | Reintentar timbrado fallido |
| POST | `/admin/cfdi/{id}/cancel` | Cancelar CFDI ante el SAT |
| GET | `/admin/cfdi/{id}/download/{format}` | Descargar CFDI (`pdf` o `xml`) |

### Fiscal admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/fiscal/regimes` | Catálogo de regímenes SAT |
| PUT | `/admin/fiscal/regimes/{code}/toggle` | Activar / desactivar régimen |
| GET | `/admin/fiscal/cfdi-uses` | Catálogo de usos CFDI SAT |
| PUT | `/admin/fiscal/cfdi-uses/{code}/toggle` | Activar / desactivar uso |
| GET | `/admin/fiscal/profiles` | Perfiles fiscales de clientes |
| GET | `/admin/fiscal/profiles/{uuid}` | Detalle de perfil fiscal |

### Notificaciones admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/notifications/dashboard` | Dashboard de notificaciones |
| GET | `/admin/notifications` | Lista de notificaciones |
| GET | `/admin/notifications/stats` | Estadísticas |
| POST | `/admin/notifications/broadcast` | Enviar notificación a todos los usuarios |
| POST | `/admin/notifications/send-to-user/{user}` | Enviar notificación a usuario específico |
| PUT | `/admin/notifications/mark-all-read` | Marcar todas como leídas |
| PUT | `/admin/notifications/archive-all-read` | Archivar leídas |
| DELETE | `/admin/notifications/archived` | Eliminar archivadas |
| PUT | `/admin/notifications/{notification}/read` | Marcar como leída |
| PUT | `/admin/notifications/{notification}/archive` | Archivar |
| PUT | `/admin/notifications/{notification}/unarchive` | Desarchivar |
| DELETE | `/admin/notifications/{notification}` | Eliminar notificación |

### Chat admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/chat/active-rooms` | Salas de chat activas |
| GET | `/admin/chat/all-rooms` | Todas las salas |
| GET | `/admin/chat/stats` | Estadísticas de chat |
| GET | `/admin/chat/unread-count` | Mensajes no leídos |
| GET | `/admin/chat/{chatRoom}/messages` | Mensajes de una sala |
| POST | `/admin/chat/{chatRoom}/messages` | Enviar mensaje |
| PUT | `/admin/chat/{chatRoom}/assign` | Asignar sala a agente |
| PUT | `/admin/chat/{chatRoom}/close` | Cerrar sala |
| PUT | `/admin/chat/{chatRoom}/reopen` | Reabrir sala |

### Catálogo admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET/POST | `/admin/categories` | Lista / Crear categorías |
| PUT/DELETE | `/admin/categories/{uuid}` | Actualizar / Eliminar |
| GET/POST | `/admin/billing-cycles` | Lista / Crear ciclos de facturación |
| PUT/DELETE | `/admin/billing-cycles/{uuid}` | Actualizar / Eliminar |
| GET/POST | `/admin/service-plans` | Lista / Crear planes |
| GET | `/admin/service-plans/{uuid}` | Detalle |
| PUT/DELETE | `/admin/service-plans/{uuid}` | Actualizar / Eliminar |
| POST | `/admin/service-plans/bulk/{action}` | Acciones en lote |
| GET/POST | `/admin/add-ons` | Lista / Crear add-ons |
| GET | `/admin/add-ons/{uuid}` | Detalle |
| PUT/DELETE | `/admin/add-ons/{uuid}` | Actualizar / Eliminar |
| POST | `/admin/add-ons/{uuid}/attach-to-plan` | Asociar add-on a plan |
| POST | `/admin/add-ons/{uuid}/detach-from-plan` | Desasociar |
| POST/PUT/DELETE | `/admin/products/{uuid}` | CRUD de productos |

### Blog admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET/POST/PUT/DELETE | `/admin/blog-categories` | CRUD de categorías del blog |
| GET/POST/PUT/DELETE | `/admin/blog-posts` | CRUD de posts (apiResource) |
| POST | `/admin/blog/upload-image` | Subir imagen al blog |
| GET/DELETE | `/admin/blog-subscriptions` | Lista / Eliminar suscriptores |
| GET | `/admin/blog-subscriptions/{uuid}` | Detalle de suscriptor |

### Documentación admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET/POST | `/admin/documentation` | Lista / Crear artículos |
| GET/PUT/DELETE | `/admin/documentation/{uuid}` | Detalle / Actualizar / Eliminar |
| GET/POST | `/admin/api-documentation` | Lista / Crear artículos de API docs |
| GET/PUT/DELETE | `/admin/api-documentation/{uuid}` | Detalle / Actualizar / Eliminar |
| GET | `/admin/documentation-requests` | Lista de solicitudes de docs |
| GET | `/admin/documentation-requests/{id}` | Detalle |
| PUT | `/admin/documentation-requests/{id}/mark-resolved` | Marcar como resuelta |
| DELETE | `/admin/documentation-requests/{id}` | Eliminar |

### Sistema y búsqueda admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/search?q={query}` | Búsqueda global (throttle: rate `search`) |
| GET | `/admin/search/popular` | Términos populares de búsqueda |
| GET/POST | `/admin/system-status` | Lista / Crear servicios de estado |
| GET/PUT/DELETE | `/admin/system-status/{uuid}` | Detalle / Actualizar / Eliminar |

---

## Códigos de respuesta comunes

| Código | Significado |
|---|---|
| `200` | OK — respuesta exitosa |
| `201` | Created — recurso creado |
| `204` | No Content — operación exitosa sin cuerpo |
| `400` | Bad Request — validación fallida |
| `401` | Unauthorized — sesión inexistente o expirada |
| `403` | Forbidden — sin permisos suficientes |
| `404` | Not Found — recurso no encontrado |
| `419` | CSRF Token Mismatch — cookie XSRF inválida |
| `422` | Unprocessable Entity — errores de validación Laravel |
| `429` | Too Many Requests — throttle superado |
| `500` | Server Error — error interno |

## Formato de error estándar

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```


---

<div style="page-break-before:always;background:#1e1b2e;color:white;padding:40px 40px 40px;margin-bottom:40px;text-align:center;">
  <p style="font-size:9pt;color:#6c63ff;font-weight:600;letter-spacing:0.2em;text-transform:uppercase;margin-bottom:12px;">PARTE II</p>
  <div style="font-size:22pt;font-weight:700;margin-bottom:8px;color:white;">Frontend</div>
  <p style="font-size:11pt;color:#9ca3af;margin:0;">React 19 &middot; TypeScript &middot; Vite &middot; Tailwind CSS &middot; React Query</p>
</div>

# ROKE Industries — Hosting Platform Frontend

## Descripción general

Aplicación React/TypeScript que implementa el portal de clientes y el panel de administración de ROKE Industries. Incluye gestión de servicios de hosting, game servers, facturación electrónica (CFDI 4.0 SAT México), soporte, cotizaciones y blog.

---

## Stack tecnológico

| Componente | Tecnología |
|---|---|
| Framework | React 19 |
| Lenguaje | TypeScript 6 |
| Bundler | Vite 6 |
| Gestor de paquetes | pnpm 10 |
| Estilos | Tailwind CSS v4 |
| Estado del servidor | TanStack React Query v5 |
| Formularios | react-hook-form + Zod |
| Routing | react-router-dom v7 |
| UI base | Radix UI + shadcn/ui |
| Animaciones | Framer Motion |
| Editor rich text | Tiptap v3 |
| Gráficas | Recharts |
| Pagos | Stripe (`@stripe/react-stripe-js`) |
| Auth Google | `@react-oauth/google` |
| WebSockets | Laravel Echo + Pusher JS → Laravel Reverb |
| Internacionalización | i18next + react-i18next |
| Monitoreo | Sentry (`@sentry/react`) |
| Testing | Vitest + Testing Library + MSW + Playwright |
| Storybook | Componentes UI aislados |

---

## Builds

La aplicación genera **dos builds independientes**:

| Build | Entry point | Directorio de salida | Uso |
|---|---|---|---|
| Portal de clientes | `index.html` | `dist-portal/` | Clientes finales |
| Panel de administración | `index-admin.html` | `dist-admin/` | Administradores y soporte |

---

## Alias de paths (Vite)

| Alias | Directorio real |
|---|---|
| `@` | `src/` |
| `@core` | `src/core/` |
| `@application` | `src/application/` |
| `@infrastructure` | `src/infrastructure/` |
| `@presentation` | `src/presentation/` |
| `@shared` | `src/shared/` |

---

## Variables de entorno (`.env`)

| Variable | Descripción |
|---|---|
| `VITE_API_BASE_URL` | URL base de la API (ej. `https://api.roke.mx/api`) |
| `VITE_API_URL` | URL raíz para broadcasting auth (sin `/api`) |
| `VITE_REVERB_HOST` | Host del servidor Reverb WebSocket |
| `VITE_REVERB_PORT` | Puerto Reverb |
| `VITE_REVERB_SCHEME` | `ws` o `wss` |
| `VITE_STRIPE_PUBLIC_KEY` | Clave pública Stripe |
| `VITE_GOOGLE_CLIENT_ID` | Client ID Google OAuth |
| `VITE_SENTRY_DSN` | DSN de Sentry |

---

## Inicio rápido

```bash
pnpm install
pnpm dev           # Desarrollo (portal cliente)
pnpm dev:admin     # Desarrollo (panel admin)
pnpm build         # Build producción (ambos)
pnpm test          # Tests unitarios con Vitest
pnpm test:e2e      # Tests E2E con Playwright
pnpm storybook     # Servidor de Storybook
```

---

## Documentación detallada

- [Arquitectura técnica](architecture.md) — estructura de capas, routing, estado, WebSockets, React Query
- [Referencia de Hooks](hooks-reference.md) — todos los hooks de datos con parámetros y retornos
- [Guía de funcionalidades](features.md) — módulos del sistema: checkout, game servers, facturación, soporte, admin


---

# Arquitectura técnica — Hosting Platform Frontend

## Estructura de capas (Clean Architecture)

```
src/
├── core/                        # Entidades e interfaces de dominio
│   └── (tipos base, modelos de API)
│
├── application/
│   ├── context/
│   │   ├── AuthContext.tsx      # Contexto de autenticación global
│   │   └── ThemeContext.tsx     # Tema (dark/light)
│   └── hooks/                  # Toda la lógica de datos (React Query)
│       ├── useAuth.ts
│       ├── useGameServer.ts
│       ├── useInvoices.ts
│       ├── useCheckout.ts
│       └── ...
│
├── infrastructure/
│   ├── api/
│   │   └── apiClient.ts         # Instancia Axios base (Sanctum + CSRF)
│   └── services/                # Llamadas HTTP al backend
│       ├── authService.ts
│       ├── serviceService.ts
│       ├── adminServicesService.ts
│       ├── invoiceService.ts
│       ├── echoService.ts       # WebSockets (Laravel Echo)
│       └── ...
│
├── presentation/
│   ├── components/
│   │   ├── features/            # Componentes de dominio
│   │   │   ├── checkout/
│   │   │   ├── services/
│   │   │   │   └── game-server/ # GameServerDetail, ModsManager, etc.
│   │   │   ├── tickets/
│   │   │   ├── invoices/
│   │   │   └── admin/
│   │   └── ui/                  # Primitivas (shadcn/ui + Radix)
│   ├── layouts/
│   │   ├── AppPortal.tsx        # Router del portal cliente
│   │   └── AppAdmin.tsx         # Router del panel admin
│   └── pages/
│       ├── auth/
│       ├── client/
│       └── admin/
│
└── shared/
    ├── constants/
    │   └── queryConfig.ts       # Políticas de caché React Query
    └── utils/                   # Formatters, validaciones, geo, i18n, CFDI
```

---

## Routing

### Portal de Clientes — `AppPortal.tsx`

#### Rutas públicas (sin sesión)

| Ruta | Componente | Descripción |
|---|---|---|
| `/login` | `LoginPage` | Login con email/contraseña y Google OAuth |
| `/register` | `RegisterPage` | Registro de nuevo usuario |
| `/verify-2fa` | `Verify2FAPage` | Verificación de código TOTP |
| `/forgot-password` | `ForgotPasswordPage` | Solicitud de reset de contraseña |
| `/reset-password` | `ResetPasswordPage` | Formulario con token de reset |
| `/auth/complete-profile` | `CompleteProfilePage` | Completa perfil de Google (setup_token) |
| `/auth/setup-username` | `SetupUsernamePage` | Asignar username a usuario sin uno |

#### Rutas protegidas (`/client/*`)

| Ruta | Componente | Descripción |
|---|---|---|
| `/client/` | `ClientDashboardPage` | Dashboard principal del cliente |
| `/client/services` | `ClientServicesPage` | Lista de servicios |
| `/client/services/:uuid` | `ServiceDetailPage` | Detalle de servicio (redirige a game server o hosting si aplica) |
| `/client/hosting/:uuid` | `HostingDetailPage` | Detalle de hosting HestiaCP |
| `/client/services/:uuid/manage` | `ServiceManagementPage` | Configuración avanzada del servicio |
| `/client/invoices` | `ClientInvoicesPage` | Facturación (comprobantes, métodos de pago, transacciones) |
| `/client/tickets` | `ClientTicketsPage` | Tickets de soporte |
| `/client/profile` | `ClientProfilePage` | Perfil, seguridad, 2FA, sesiones |
| `/client/catalog` | `ContractServicePage` | Catálogo de planes para contratar |
| `/client/checkout` | `CheckoutPage` | Flujo de checkout multi-step |
| `/client/checkout/success` | `CheckoutSuccessPage` | Confirmación de compra |

### Panel de Administración — `AppAdmin.tsx`

#### Rutas públicas admin

| Ruta | Componente | Descripción |
|---|---|---|
| `/login` | `AdminLoginPage` | Login admin (email + Google con validación de rol) |
| `/cotizacion/:token` | `QuotationPublicPage` | Cotización pública por token |

#### Rutas protegidas (`/admin/*`)

| Ruta | Componente |
|---|---|
| `/admin/` | `AdminDashboardPage` |
| `/admin/services` | `AdminServicesPage` |
| `/admin/services/:uuid` | `AdminServiceDetailPage` |
| `/admin/users` | `AdminUsersPage` |
| `/admin/invoices` | `AdminInvoicesPage` |
| `/admin/cfdi` | `AdminCfdiPage` |
| `/admin/tickets` | `AdminTicketsPage` |
| `/admin/game-servers` | `AdminGameServersPage` |
| `/admin/game-versions` | `AdminGameVersionsPage` |
| `/admin/quotations` | `AdminQuotationsPage` |
| `/admin/quotations/:uuid` | `AdminQuotationDetailPage` |
| `/admin/blog` | `AdminBlogPage` |
| `/admin/blog/editor` | `AdminBlogEditorPage` |
| `/admin/blog/categories` | `AdminBlogCategoriesPage` |
| `/admin/categories` | `AdminCategoriesPage` |
| `/admin/service-plans` | `AdminServicePlansPage` |
| `/admin/add-ons` | `AdminAddOnsPage` |
| `/admin/notifications` | `AdminNotificationsPage` |
| `/admin/profile` | `AdminProfilePage` |
| `/admin/system-status` | `AdminSystemStatusPage` |
| `/admin/documentation` | `AdminDocumentationPage` |
| `/admin/api-docs` | `AdminApiDocumentationPage` |
| `/admin/documentation-requests` | `AdminDocumentationRequestsPage` |
| `/admin/user-requests` | `AdminUserRequestsPage` |

---

## Infraestructura HTTP — apiClient

Archivo: `src/infrastructure/api/apiClient.ts`

```typescript
// Dos instancias Axios:
const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,  // ej. https://api.roke.mx/api
  withCredentials: true,                        // Envía cookies en cada request
  xsrfCookieName: 'XSRF-TOKEN',               // CSRF automático
  xsrfHeaderName: 'X-XSRF-TOKEN',
});

const rootClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL,       // Para broadcasting auth
  withCredentials: true,
});
```

**Interceptor de respuesta:**
- `401` → redirige a `/login?reason=session_expired`
- `419` → recarga la página (CSRF mismatch)
- Flag `_skipAuthRedirect: true` en la config del request para evitar redirect en rutas de auth

---

## Estado global — React Query

### Configuración de caché (`src/shared/constants/queryConfig.ts`)

| Perfil | `staleTime` | `gcTime` | `refetchOnWindowFocus` | `refetchInterval` | Uso típico |
|---|---|---|---|---|---|
| `static` | 15 min | 1 hora | No | No | Categorías, ciclos de facturación |
| `dynamic` | 1 min | 5 min | Sí | 5 min | Dashboard stats, actividad |
| `sensitive` | 2 min | 10 min | No | No | Perfil, seguridad, 2FA |
| `session` | 1 min | 5 min | Sí | No | Sesiones activas |
| Default global | 5 min | 15 min | No | No | General |

**Lógica de retry personalizada:**
- No reintenta en `401` (sesión inválida)
- Hasta 3 reintentos en errores `5xx`
- Hasta 2 reintentos en otros errores

### Naming convention de queryKeys

```typescript
// Formato: ['entidad', 'subtipoOoperación', ...parámetros]
['auth', 'me']
['gameServer', 'usage', serviceUuid]
['gameServer', 'configuration', serviceUuid]
['nest', 'eggs', nestID]                    // nestID en la key para no mezclar cachés
['admin', 'services', filters]
['invoices', filters]
['ticket', uuid]
```

### Contexto de autenticación

`src/application/context/AuthContext.tsx`:
- Envuelve `useCurrentUser` (query `['auth','me']`)
- Expone `{ user, isAuthenticated, isLoading, isAdmin }`
- Consumido por las rutas protegidas y los componentes de header

---

## WebSockets en tiempo real

### echoService (`src/infrastructure/services/echoService.ts`)

```typescript
// Singleton de Laravel Echo
const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: import.meta.env.VITE_REVERB_PORT,
  wssPort: import.meta.env.VITE_REVERB_PORT,
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'wss',
  enabledTransports: ['ws', 'wss'],
  authorizer: (channel) => ({
    authorize: (socketId, callback) => {
      rootClient.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
        .then(res => callback(false, res.data))
        .catch(err => callback(true, err));
    },
  }),
});
```

### Canales utilizados

| Canal | Tipo | Datos |
|---|---|---|
| `private-user.{userId}` | Privado | Notificaciones personales del cliente |
| `private-admin.{userId}` | Privado | Notificaciones del panel admin |
| `private-chat.{roomId}` | Privado | Mensajes de chat de soporte |

### Consola de game server

El frontend conecta **directamente** a Pterodactyl Wings (no pasa por Reverb):
1. `GET /services/{uuid}/game-server/websocket` → devuelve `{ socket, token }` de Wings
2. Frontend abre WebSocket nativo a la URL de Wings con el token
3. Protocolo Wings: autenticar → `{ event: "auth", args: [token] }` → luego recibir eventos `console output`, `stats`, `status`

---

## Formularios — react-hook-form + Zod

Patrón estándar en formularios:

```typescript
const schema = z.object({
  email: z.string().email(),
  password: z.string().min(8),
});

const { register, handleSubmit, formState: { errors } } = useForm({
  resolver: zodResolver(schema),
});

const mutation = useLogin();

const onSubmit = (data) => mutation.mutate(data);
```

---

## Internacionalización (i18n)

- Configurado con `i18next` + `react-i18next`
- Archivos de traducción en `src/shared/utils/i18n/`
- Idioma por defecto: español (`es`)
- Uso: `const { t } = useTranslation(); t('key.nested')`

---

## Monitoreo con Sentry

- Inicializado en el entry point con `Sentry.init({ dsn: VITE_SENTRY_DSN })`
- `ErrorBoundary` de Sentry envuelve la app completa
- Errores no manejados, promesas rechazadas y errores de React capturados automáticamente
- `SectionErrorBoundary` para secciones individuales con fallback UI

---

## Arquitectura de componentes de UI

### Jerarquía

```
Page (lógica de datos, layout general)
  └── Layout Component (header, sidebar, tabs)
        └── Feature Component (lógica de presentación + acciones)
              └── UI Component (shadcn/ui primitivos: Button, Dialog, Badge...)
```

### Primitivos UI (shadcn/ui + Radix)

Disponibles en `src/presentation/components/ui/`:

`Button`, `Input`, `Select`, `Checkbox`, `Badge`, `Dialog`, `Sheet`, `Tabs`, `Card`, `Table`, `Pagination`, `Avatar`, `Dropdown`, `Tooltip`, `Toast`, `Skeleton`, `Switch`, `Textarea`, `Label`, `Separator`, `ScrollArea`, `Collapsible`, `Command`, `Popover`, `Calendar`, `DatePicker`, ...

---

## Testing

### Tests unitarios y de integración (Vitest + Testing Library)

```bash
pnpm test              # Modo watch
pnpm test --run        # Una sola pasada
pnpm test:coverage     # Con cobertura
```

### Mocking de API (MSW)

`src/mocks/` contiene handlers para interceptar requests HTTP en tests:
```typescript
// Ejemplo de handler
http.get('/api/auth/me', () => HttpResponse.json({ data: mockUser }))
```

### Tests E2E (Playwright)

```bash
pnpm test:e2e          # Ejecutar tests E2E
pnpm test:e2e --ui     # Interfaz visual de Playwright
```

---

## Patrón de desarrollo de nuevas funcionalidades

1. **Definir el tipo** en `src/core/` o inline en el servicio
2. **Crear el servicio** en `src/infrastructure/services/`
3. **Crear el hook** en `src/application/hooks/` (React Query)
4. **Crear el componente** en `src/presentation/components/features/`
5. **Crear la página** en `src/presentation/pages/`
6. **Registrar la ruta** en `AppPortal.tsx` o `AppAdmin.tsx`


---

# Referencia de Hooks — Hosting Platform Frontend

> Todos los hooks viven en `src/application/hooks/`.  
> Usan **TanStack React Query v5** internamente.  
> Los hooks de **Query** devuelven `{ data, isLoading, isError, error, refetch }`.  
> Los hooks de **Mutation** devuelven `{ mutate, mutateAsync, isPending, isError, error, isSuccess }`.

---

## Autenticación (`useAuth.ts`)

| Hook | Tipo | Descripción | Retorna |
|---|---|---|---|
| `useCurrentUser()` | Query | Usuario autenticado actual. `queryKey: ['auth','me']`, `staleTime: 5min`, sin refetch en focus. | `User \| undefined` |
| `useLogin()` | Mutation | `POST /auth/login`. Invalida `['auth','me']` en éxito. | — |
| `useLoginWithGoogle()` | Mutation | `POST /auth/google/callback` con `credential: id_token`. | `{ requires_setup, setup_token? }` |
| `useAdminLoginWithGoogle()` | Mutation | `POST /admin/auth/google/callback`. Valida rol admin. | — |
| `useRegister()` | Mutation | `POST /auth/register`. | — |
| `useVerify2FA()` | Mutation | `POST /auth/2fa/verify` con `{ code, temp_token }`. | — |
| `useLogout()` | Mutation | `POST /auth/logout`. Limpia todo el query cache. | — |
| `useCompleteProfile()` | Mutation | `POST /auth/complete-profile` con `{ setup_token, username }`. | — |
| `useSetupUsername()` | Mutation | `POST /auth/setup-username`. Para usuarios autenticados sin username. | — |
| `useForgotPassword()` | Mutation | `POST /forgot-password`. | — |
| `useResetPassword()` | Mutation | `POST /reset-password` con token. | — |

---

## Dashboard cliente (`useDashboard.ts`)

| Hook | Tipo | queryKey | Descripción |
|---|---|---|---|
| `useDashboardStats()` | Query | `['dashboard','stats']` | Métricas: servicios activos, gasto mensual, tickets abiertos, dominios. `staleTime: 1min`, `refetchInterval: 5min`. |
| `useDashboardServices()` | Query | `['dashboard','services']` | Servicios del cliente para el dashboard. |
| `useDashboardActivity()` | Query | `['dashboard','activity']` | Actividad reciente. `refetchOnWindowFocus: true`. |

---

## Servicios cliente (`useServices.ts`)

| Hook | Tipo | queryKey | Descripción |
|---|---|---|---|
| `useUserServices()` | Query | `['services','user']` | Todos los servicios del usuario. Normaliza specs, metrics, billing_cycle. |
| `useServiceDetails(uuid)` | Query | `['service', uuid]` | Detalle completo de un servicio. |
| `useServiceInvoices(uuid)` | Query | `['service', uuid, 'invoices']` | Facturas asociadas al servicio. |
| `useUpdateServiceConfig(uuid)` | Mutation | — | `PATCH /services/{uuid}/configuration`. Invalida `['service', uuid]`. |
| `useServiceBackups(uuid)` | Query | `['service', uuid, 'backups']` | Lista de backups. |
| `useCreateBackup(uuid)` | Mutation | — | `POST /services/{uuid}/backups`. Invalida lista de backups. |
| `useRestoreBackup(uuid)` | Mutation | — | `POST /services/{uuid}/backups/{backupId}/restore`. |
| `useServicesMetrics()` | Query | `['services','metrics']` | Métricas de todos los servicios del usuario. |

---

## Game Server cliente (`useGameServer.ts`)

| Hook | Tipo | queryKey | Descripción |
|---|---|---|---|
| `useGameServerUsage(uuid, enabled?)` | Query | `['gameServer','usage', uuid]` | Métricas en tiempo real (CPU, RAM, red, disco). `refetchInterval: 30s`, `staleTime: 0`. |
| `useGameServerSoftwareOptions(uuid, enabled?)` | Query | `['gameServer','softwareOptions', uuid]` | Opciones de software disponibles (eggs del plan). `staleTime: 10min`. |
| `useGameServerConfiguration(uuid, enabled?)` | Query | `['gameServer','configuration', uuid]` | Configuración actual: software, server.properties, EULA, Java. `staleTime: 30s`. |
| `useUpdateGameServerSoftware(uuid)` | Mutation | — | `PATCH /services/{uuid}/game-server/software`. Invalida `configuration` y `service`. |
| `useUpdateGameServerProperties(uuid)` | Mutation | — | `PATCH /services/{uuid}/game-server/server-properties`. |
| `useAcceptGameServerEula(uuid)` | Mutation | — | `POST /services/{uuid}/game-server/eula/accept`. Invalida `configuration`. |
| `useFixGameServerJava(uuid)` | Mutation | — | `POST /services/{uuid}/game-server/fix-java` con `targetJava?: number`. Invalida `configuration`. |
| `useGameServerPower(uuid)` | Mutation | — | `POST /services/{uuid}/game-server/power` con signal: `'start' \| 'stop' \| 'restart' \| 'kill'`. Invalida `usage`. |
| `useNestEggs(nestID, enabled?)` | Query | `['nest','eggs', nestID]` | Eggs de Pterodactyl para un nido. `enabled` requiere `nestID > 0`. `staleTime: 10min`. |
| `useGameServerStartup(uuid)` | Query | `['gameServer','startup', uuid]` | Configuración de arranque del servidor (env vars, variables Pterodactyl). |

---

## Checkout (`useCheckout.ts`)

| Hook | Tipo | queryKey | Descripción |
|---|---|---|---|
| `useGameEggs(planUuid)` | Query | `['gameEggs', planUuid]` | Eggs agrupados por nido para un plan. Usado en selector de juego del checkout. |
| `usePlanAddons(planId)` | Query | `['planAddons', planId]` | Add-ons disponibles para un plan. |
| `usePaymentMethods()` | Query | `['paymentMethods']` | Métodos de pago guardados del usuario. |

---

## Facturas e invoices (`useInvoices.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useInvoices(filters?)` | Query | Lista paginada de facturas. Acepta filtros `status`, `page`, `per_page`. |
| `useInvoiceStats()` | Query | Totales, pagadas, pendientes, vencidas. |
| `useProcessPayment()` | Mutation | `POST /payments/process` con `{ invoice_id, payment_method_id }`. |
| `useSetDefaultPaymentMethod()` | Mutation | `PUT /payments/methods/{id}` → establece como predeterminado. |
| `useDeletePaymentMethod()` | Mutation | `DELETE /payments/methods/{id}`. |
| `useAddPaymentMethod()` | Mutation | `POST /payments/methods` con `payment_method_id` de Stripe. |
| `useCreateSetupIntent()` | Mutation | `POST /payments/setup-intent` → devuelve `client_secret` de Stripe. |
| `useUpdateInvoiceFiscalData(uuid)` | Mutation | `PUT /invoices/{uuid}/fiscal-data`. Solo disponible en ventana de 72h. |
| `useDownloadCfdi(uuid, format)` | Mutation | `GET /invoices/{uuid}/pdf` o `/xml`. Descarga automática del blob. |
| `useDownloadReceipt(uuid)` | Mutation | `GET /invoices/{uuid}/receipt`. Descarga blob. |
| `useAdminDownloadReceipt(uuid)` | Mutation | `GET /admin/invoices/{uuid}/receipt`. Versión admin. |
| `useTransactions(filters?)` | Query | Historial de transacciones con filtros. |
| `useTransactionStats()` | Query | Estadísticas de transacciones. |

---

## Tickets de soporte (`useTickets.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useTickets(params?)` | Query | Lista de tickets del usuario con filtros (`status`, `search`). |
| `useTicket(uuid)` | Query | Detalle de ticket. |
| `useTicketStats()` | Query | Estadísticas: abiertos, resueltos, en espera. |
| `useCreateTicket()` | Mutation | `POST /tickets`. Invalida lista. |
| `useAddReply(uuid)` | Mutation | `POST /tickets/{uuid}/reply`. Admite `FormData` para adjuntos. |
| `useCloseTicket(uuid)` | Mutation | `PUT /tickets/{uuid}/close`. |

---

## Perfil cliente (`useProfile.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useProfile()` | Query | Datos del perfil del usuario. Con optimistic update. |
| `useUpdateProfile()` | Mutation | `PUT /profile`. Optimistic update + rollback en error. |
| `useUploadAvatar()` | Mutation | `POST /profile/avatar` (FormData). Invalida `['profile']` y `['auth','me']`. |
| `useSecurity()` | Query | Resumen de seguridad (2FA activo, sesiones). |
| `useUpdatePassword()` | Mutation | `PUT /profile/password`. |
| `useResendEmailVerification()` | Mutation | `POST /profile/email/verification-notification`. |
| `useSessions()` | Query | Dispositivos activos. `refetchOnWindowFocus: true`. |
| `useRevokeSession(uuid)` | Mutation | `DELETE /profile/sessions/{uuid}`. |
| `useRevokeOtherSessions()` | Mutation | `POST /profile/devices/revoke-others`. |
| `useTwoFactor()` | Hook compuesto | CRUD de 2FA: `generate`, `enable`, `disable`, `verify`. |

---

## Fiscal / CFDI cliente (`useFiscal.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useFiscalProfiles()` | Query | Perfiles fiscales guardados (RFC, razón social, régimen). |
| `useCreateFiscalProfile()` | Mutation | `POST /fiscal/profiles`. |
| `useUpdateFiscalProfile(uuid)` | Mutation | `PUT /fiscal/profiles/{uuid}`. |
| `useDeleteFiscalProfile(uuid)` | Mutation | `DELETE /fiscal/profiles/{uuid}`. |
| `useSetDefaultFiscalProfile(uuid)` | Mutation | `PUT /fiscal/profiles/{uuid}/set-default`. |
| `useFiscalRegimes()` | Query | Catálogo de regímenes SAT. `staleTime: 15min`. |
| `useCfdiUses()` | Query | Catálogo de usos de CFDI SAT. `staleTime: 15min`. |

---

## Notificaciones cliente (`useClientNotifications.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useClientNotifications()` | Query | Lista de notificaciones del usuario. |
| `useUnreadNotificationsCount()` | Query | Conteo de no leídas. `refetchInterval: 30s`. |
| `useMarkNotificationRead(id)` | Mutation | `PUT /notifications/{id}/read`. |
| `useMarkAllNotificationsRead()` | Mutation | `PUT /notifications/mark-all-read`. |
| `useDeleteNotification(id)` | Mutation | `DELETE /notifications/{id}`. |
| `useNotificationPreferences()` | Query | Preferencias de notificación. |
| `useUpdateNotificationPreferences()` | Mutation | `PUT /notifications/preferences`. |

---

## Chat de soporte (`useSupportChat.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useSupportRoom()` | Query | Obtiene o crea la sala de soporte del usuario. |
| `useChatMessages(ticketId)` | Query | Mensajes de una sala. Se actualiza vía WebSocket. |
| `useSendChatMessage(ticketId)` | Mutation | `POST /chat/{ticket}/messages`. |
| `useChatUnreadCount()` | Query | Mensajes no leídos en chat. |
| `useMarkChatRead(ticketId)` | Mutation | `PUT /chat/{ticket}/read`. |

---

## Admin — Dashboard (`useAdminDashboard.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useAdminStats()` | Query | Métricas globales: usuarios totales, servicios activos, ingresos, tickets. |
| `useAdminUsers(params?)` | Query | Lista de usuarios con filtros. |
| `useCreateUser()` | Mutation | `POST /admin/users`. |
| `useUpdateUser(id)` | Mutation | `PUT /admin/users/{id}`. |
| `useDeleteUser(id)` | Mutation | `DELETE /admin/users/{id}`. |
| `useUpdateUserStatus(id)` | Mutation | `PUT /admin/users/{id}/status`. |
| `useAdminServices(params?)` | Query | Lista de servicios con filtros. |
| `useUpdateServiceStatus(uuid)` | Mutation | `PUT /admin/services/{uuid}/status`. |
| `useAdminInvoices(params?)` | Query | Lista de facturas. |
| `useCreateInvoice()` | Mutation | `POST /admin/invoices`. |
| `useUpdateInvoiceStatus(id)` | Mutation | `PUT /admin/invoices/{id}/status`. |
| `useMarkInvoiceAsPaid(id)` | Mutation | `POST /admin/invoices/{id}/mark-paid`. |
| `useAdminTickets(params?)` | Query | Lista de tickets. |
| `useUpdateTicketStatus(id)` | Mutation | `PUT /admin/tickets/{id}/status`. |
| `useAssignTicket(id)` | Mutation | `POST /admin/tickets/{id}/assign`. |
| `useAddTicketReply(id)` | Mutation | `POST /admin/tickets/{id}/reply`. |
| `useTicketCategories()` | Query | Categorías de tickets. `staleTime: 15min`. |
| `useSupportAgents()` | Query | Agentes disponibles para asignación. |

---

## Admin — Servicios (`useAdminServices.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useAdminServices(params?)` | Query | Lista paginada con filtros: `search`, `status`, `plan_id`, `page`, `per_page`. |
| `useAdminService(uuid)` | Query | Detalle de un servicio. |
| `useAdminServiceSupportOverview(uuid)` | Query | Tickets y soporte relacionados al servicio. |
| `useAdminServicesStats()` | Query | Estadísticas de servicios. |
| `useAdminServiceHistory(uuid)` | Query | Historial de cambios del servicio. |
| `useAdminServicesByUser(userId)` | Query | Servicios de un usuario específico. |
| `useCreateAdminService()` | Mutation | `POST /admin/services`. |
| `useUpdateAdminService(uuid)` | Mutation | `PUT /admin/services/{uuid}`. |
| `useDeleteAdminService(uuid)` | Mutation | `DELETE /admin/services/{uuid}`. |
| `useChangeAdminServiceStatus(uuid)` | Mutation | `PUT /admin/services/{uuid}/status`. |
| `useSuspendAdminService(uuid)` | Mutation | Cambia estado a `suspended`. |
| `useReactivateAdminService(uuid)` | Mutation | Cambia estado a `active`. |

---

## Admin — Facturas (`useAdminInvoices.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useAdminInvoices(params?)` | Query | Lista paginada de facturas. |
| `useAdminInvoice(id)` | Query | Detalle de factura. |
| `useAdminInvoicesStats()` | Query | Estadísticas de facturación. |
| `useAdminOverdueInvoices()` | Query | Facturas vencidas. |
| `useAdminRevenueReport()` | Query | Reporte de ingresos. |
| `useCreateAdminInvoice()` | Mutation | Crear factura manual. |
| `useUpdateAdminInvoice(id)` | Mutation | Actualizar factura. |
| `useDeleteAdminInvoice(id)` | Mutation | Eliminar factura. |
| `useMarkInvoiceAsPaid(id)` | Mutation | `POST /admin/invoices/{id}/mark-paid`. |
| `useUpdateInvoiceStatus(id)` | Mutation | Cambiar estado. |
| `useCancelInvoice(id)` | Mutation | `POST /admin/invoices/{id}/cancel`. |
| `useSendInvoiceReminder(id)` | Mutation | `POST /admin/invoices/{id}/send-reminder`. |

---

## Admin — Tickets (`useAdminTickets.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useAdminTickets(params?)` | Query | Lista de tickets con filtros avanzados. |
| `useAdminTicket(id)` | Query | Detalle de ticket. |
| `useAdminTicketsStats()` | Query | Estadísticas: abiertos, resueltos, por prioridad, por agente. |
| `useAdminTicketCategories()` | Query | Categorías disponibles. |
| `useAdminTicketAgents()` | Query | Agentes con sus estadísticas. |
| `useAdminTicketReplies(id)` | Query | Respuestas de un ticket. |
| `useAdminTicketPerformance()` | Query | Métricas de rendimiento de agentes. |
| `useCreateAdminTicket()` | Mutation | Crear ticket desde admin. |
| `useUpdateAdminTicket(id)` | Mutation | Actualizar ticket. |
| `useDeleteAdminTicket(id)` | Mutation | Eliminar ticket. |
| `useChangeTicketStatus(id)` | Mutation | Cambiar estado. |
| `useChangeTicketPriority(id)` | Mutation | Cambiar prioridad. |
| `useAssignTicket(id)` | Mutation | Asignar a agente. |
| `useAddTicketReply(id)` | Mutation | Agregar respuesta. |
| `useCloseTicket(id)` | Mutation | Cerrar ticket. |
| `useReopenTicket(id)` | Mutation | Reabrir ticket. |

---

## Admin — Usuarios (`useUsers.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useUsers(params?)` | Query | Lista de usuarios con filtros: `search`, `status`, `role`, `page`, `per_page`. |
| `useUser(id)` | Query | Detalle de usuario. |
| `useCreateUser()` | Mutation | `POST /admin/users`. |
| `useUpdateUser(id)` | Mutation | `PUT /admin/users/{id}`. |
| `useDeleteUser(id)` | Mutation | `DELETE /admin/users/{id}`. |
| `useChangeUserStatus(id)` | Mutation | `PUT /admin/users/{id}/status`. |
| `useUsersStats()` | Query | Estadísticas de usuarios (totales, activos, nuevos esta semana). |
| `useUsersRecentActivity()` | Query | Actividad reciente de usuarios. |

---

## Admin — Game Servers (`useAdminGameServers.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useAdminGameServers(params?)` | Query | Lista de game servers con filtros. |
| `useAdminGameServer(id)` | Query | Detalle de game server. |
| `useProvisionGameServer(id)` | Mutation | `POST /admin/game-servers/{id}/provision`. Provisiona en Pterodactyl. |
| `useSuspendGameServer(id)` | Mutation | `POST /admin/game-servers/{id}/suspend`. |
| `useUnsuspendGameServer(id)` | Mutation | `POST /admin/game-servers/{id}/unsuspend`. |
| `useReinstallGameServer(id)` | Mutation | `POST /admin/game-servers/{id}/reinstall`. |
| `useDeleteGameServer(id)` | Mutation | `DELETE /admin/game-servers/{id}`. |

---

## Admin — CFDI (`useAdminCfdi.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useAdminCfdis(params?)` | Query | Lista de CFDIs con filtros: `status`, `date_from`, `date_to`. |
| `useAdminCfdiStats()` | Query | Estadísticas: timbrados, fallidos, cancelados, monto total. |
| `useAdminCfdiDetail(id)` | Query | Detalle de un CFDI. |
| `useRetryCfdi(id)` | Mutation | `POST /admin/cfdi/{id}/retry`. Reintenta timbrado. |
| `useCancelCfdi(id)` | Mutation | `POST /admin/cfdi/{id}/cancel`. Cancela ante SAT. |
| `useDownloadAdminCfdi(id, format)` | Mutation | `GET /admin/cfdi/{id}/download/{format}`. Descarga PDF o XML. |

---

## Admin — Cotizaciones (`useQuotations.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useQuotations(params?)` | Query | Lista de cotizaciones con filtros. |
| `useQuotation(uuid)` | Query | Detalle de cotización. |
| `usePublicQuotation(token)` | Query | Cotización pública por token (sin auth). |
| `useCreateQuotation()` | Mutation | Crear cotización. |
| `useUpdateQuotation(uuid)` | Mutation | Actualizar. |
| `useDeleteQuotation(uuid)` | Mutation | Eliminar. |
| `useSendQuotation(uuid)` | Mutation | Enviar al cliente por email. |
| `useRegenerateQuotationLink(uuid)` | Mutation | Generar nuevo token de acceso público. |
| `useAcceptQuotation(uuid)` | Mutation | Marcar como aceptada. |
| `useRejectQuotation(uuid)` | Mutation | Marcar como rechazada. |
| `useReopenQuotation(uuid)` | Mutation | Reabrir cotización expirada/rechazada. |
| `useCreateQuotationRevision(uuid)` | Mutation | Crear nueva versión de la cotización. |

---

## Admin — Game Versions (`useAdminGameVersions.ts`)

| Hook | Tipo | Descripción |
|---|---|---|
| `useAdminGameVersions(filters?)` | Query | Lista de versiones de software (`software`, `enabled`, `page`). |
| `useCreateAdminGameVersion()` | Mutation | Crear versión. |
| `useUpdateAdminGameVersion(id)` | Mutation | Actualizar versión (nombre, URL de descarga, estado recomendado). |
| `useDeleteAdminGameVersion(id)` | Mutation | Eliminar versión. |
| `useBulkAdminGameVersions()` | Mutation | `POST /admin/game-versions/bulk/{action}`. Acciones en lote: `enable`, `disable`, `delete`. |

---

## Búsqueda global (`useGlobalSearch.ts`)

```typescript
const { query, results, isLoading, search, clearSearch, hasResults, popularQueries, recentSearches } =
  useGlobalSearch();
```

- Query debounced **400ms**.
- Mínimo **2 caracteres** para disparar búsqueda.
- `recentSearches` almacenado en **localStorage**.
- `popularQueries` via `GET /admin/search/popular`.

---

## Catálogo admin (`useServicePlans.ts`, `useAdminAddOns.ts`)

| Hook | Descripción |
|---|---|
| `useAdminServicePlans(params?)` | Lista de planes con filtros. |
| `useCreateServicePlan()` | Crear plan. |
| `useUpdateServicePlan(uuid)` | Actualizar plan. |
| `useDeleteServicePlan(uuid)` | Eliminar plan. |
| `useBulkServicePlans()` | Acciones en lote. |
| `useAdminCategories()` | Categorías de servicios. |
| `useAdminAddOns(params?)` | Lista de add-ons. |
| `useCreateAdminAddOn()` | Crear add-on. |
| `useUpdateAdminAddOn(uuid)` | Actualizar add-on. |
| `useDeleteAdminAddOn(uuid)` | Eliminar add-on. |
| `useAttachAddonToPlan(uuid)` | Asociar add-on a plan. |
| `useDetachAddonFromPlan(uuid)` | Desasociar. |
| `useBillingCycles()` | Ciclos de facturación disponibles. |

---

## Notificaciones admin (`useAdminNotifications.ts`)

| Hook | Descripción |
|---|---|
| `useAdminNotifications()` | Lista de notificaciones del admin. |
| `useAdminNotificationStats()` | Estadísticas (no leídas, archivadas). |
| `useBroadcastNotification()` | Enviar notificación a todos los usuarios. |
| `useSendNotificationToUser(userId)` | Enviar a usuario específico. |
| `useMarkAdminNotificationRead(id)` | Marcar como leída. |
| `useMarkAllAdminNotificationsRead()` | Marcar todas como leídas. |
| `useArchiveNotification(id)` | Archivar. |
| `useDeleteAdminNotification(id)` | Eliminar. |

---

## Hooks de utilidades

| Hook | Ubicación | Descripción |
|---|---|---|
| `use-mobile` | `src/application/hooks/` | Breakpoint responsive. Retorna `isMobile: boolean`. |
| `useSessionManager()` | `useSessionManager.ts` | Detecta inactividad y dispara logout automático. |
| `useHosting(uuid)` | `useHosting.ts` | Info, bases de datos, emails y dominios del hosting HestiaCP. |
| `useSoftwareVersions(identifier)` | `useSoftwareVersions.ts` | Versiones de software para un egg (ej. `vanilla`, `paper`). |
| `useFileManager(uuid)` | `useFileManager.ts` | Listar, subir, eliminar y descargar archivos de un servicio. |
| `useAdminFileManager(id)` | `useAdminFileManager.ts` | Gestor de archivos con bypass admin. |
| `useAdminChat(roomId)` | `useAdminChat.ts` | Chat de soporte admin: mensajes, envío, asignación. |
| `useChatInteractions(roomId)` | `useChatInteractions.ts` | Interacciones del chat (typing, leídos). |


---

# Guía de Funcionalidades — Hosting Platform Frontend

Documentación módulo a módulo de las funcionalidades implementadas en el portal de clientes y el panel de administración.

---

## Portal de Clientes

### Autenticación y registro

**Archivos:** `src/presentation/pages/auth/`

#### Login (`LoginPage`)
- Formulario email + contraseña con validación Zod.
- Botón **Iniciar sesión con Google** via `@react-oauth/google`.
- Si el backend retorna `requires_2fa: true` → redirige a `/verify-2fa` con `temp_token` en estado.
- Si Google retorna `requires_setup: true` → redirige a `/auth/complete-profile`.
- Mensaje de razón cuando hay `?reason=session_expired` en la URL.

#### Registro (`RegisterPage`)
- Campos: nombre, email, contraseña, confirmación de contraseña.
- Envía `POST /auth/register`. Redirige a login tras registro exitoso.

#### Verificación 2FA (`Verify2FAPage`)
- Formulario de 6 dígitos.
- Llama a `useVerify2FA()` con el `temp_token` recibido en el login.

#### Completar perfil Google (`CompleteProfilePage`)
- Requerido cuando el usuario nuevo se registra con Google y no tiene username.
- Verifica disponibilidad de username en tiempo real via `GET /auth/username/check`.
- Envía `POST /auth/complete-profile { setup_token, username }`.

#### Reset de contraseña
- `ForgotPasswordPage` → solicita email.
- `ResetPasswordPage` → recibe token por URL, valida contraseña nueva.

---

### Dashboard del cliente (`ClientDashboardPage`)

**Hook:** `useDashboardStats`, `useDashboardServices`, `useDashboardActivity`

**Métricas mostradas:**
- Servicios activos
- Gasto mensual actual
- Tickets de soporte abiertos
- Dominios registrados

**Secciones:**
- **Servicios recientes** — lista de últimos servicios con estado y tipo.
- **Actividad reciente** — log de acciones del usuario (pagos, tickets, cambios de configuración).
- **Acciones rápidas** — accesos directos a: Contratar servicio, Crear ticket, Ver facturas, Administrar perfil.

---

### Servicios del cliente

#### Lista de servicios (`ClientServicesPage`)

**Hook:** `useUserServices()`

- Grid de tarjetas de servicios con: nombre, estado (badge), tipo (icono), fecha de próximo pago.
- Barra de búsqueda y filtros por estado.
- Click en tarjeta → navega a detalle.

#### Detalle de servicio (`ServiceDetailPage`)

**Hook:** `useServiceDetails(uuid)`

- Muestra specs del plan, estado, IP de conexión, fecha de renovación.
- Si el servicio es **game server** → redirige automáticamente a `GameServerDetail`.
- Si es **hosting** → redirige a `/client/hosting/:uuid`.
- **Acciones disponibles:**
  - Crear backup
  - Cancelar servicio (con confirmación)
  - Reactivar servicio (si está suspendido)

#### Configuración avanzada (`ServiceManagementPage`)

**Tabs:**
1. **General** — nombre del servicio, notas.
2. **Security** — configuraciones de acceso.
3. **Danger Zone** — cancelar servicio permanentemente.

---

### Game Server — Vista completa (`GameServerDetail`)

**Archivo:** `src/presentation/components/features/services/game-server/GameServerDetail.tsx`

**Hooks:** `useGameServerUsage`, `useGameServerConfiguration`, `useGameServerPower`

El componente central para la gestión de game servers. Se adapta al tipo de egg (no solo Minecraft).

#### Tabs

| Tab | Contenido | Visible cuando |
|---|---|---|
| **Consola** | Terminal WebSocket en tiempo real, control de energía | Siempre |
| **Configuración** | Software, server.properties, propiedades técnicas | Siempre |
| **Mods / Plugins / Archivos** | Gestor de archivos + marketplace | Siempre (label varía por egg) |

#### Label del tab de archivos (`getFilesTabLabel`)

- Eggs con mod loader (Forge, Fabric, NeoForge, Quilt) → **"Mods"**
- Eggs con plugin loader (Paper, Spigot, Bukkit, Purpur, Arclight, Sponge, Folia) → **"Plugins"**
- Resto → **"Archivos del servidor"**

#### Modales condicionales (solo eggs de Minecraft Java)

La función `isJavaMinecraftEgg(eggName)` determina si aplican los modales. Solo se activan para Java Edition; no aplican a Bedrock, BungeeCord, Velocity, Nukkit.

| Modal | Trigger |
|---|---|
| **Java version mismatch** | `javaMismatch: true` en la configuración, o el parser de consola detectó versión Java incompatible |
| **EULA** | `eulaAccepted: false` en la configuración, o el parser de consola detectó `eula.txt` |

#### Consola WebSocket

1. `GET /services/{uuid}/game-server/websocket` → `{ socket: "wss://...", token: "..." }`
2. `new WebSocket(socket)` → `send(JSON.stringify({ event: "auth", args: [token] }))`
3. Eventos recibidos: `console output` (líneas de log), `stats` (CPU/RAM en tiempo real), `status` (running/offline/starting/stopping)
4. Parser de consola detecta patrones:
   - `eula.txt` → activa flag `consoleDetectedEula`
   - `Java version mismatch` / `requires Java X` → activa `consoleDetectedJava`

#### Control de energía

Botones: **Iniciar**, **Detener**, **Reiniciar**, **Forzar detención (Kill)**.  
Usa `useGameServerPower(uuid)` → `POST /services/{uuid}/game-server/power { signal: "start|stop|restart|kill" }`.

---

### Game Server — Configuración (`GameServerSettings`)

**Archivo:** `src/presentation/components/features/services/game-server/GameServerSettings.tsx`

#### Tabs de configuración

| Tab | Descripción | Visible cuando |
|---|---|---|
| **Software** | Selector de versión de software (vanilla, paper, forge…) | Siempre |
| **server.properties** | Editor de propiedades del servidor | Solo eggs Minecraft (`isMinecraftEgg`) |
| **Propiedades** | Variables de startup de Pterodactyl | Siempre |

#### Detección de tipo de egg

```
isJavaSoftware(name)     → EULA aplica (excluye Bedrock/proxies aunque tengan "vanilla")
isMinecraftEgg(name)     → Tab server.properties visible
isModrinthSupported(name)→ Tab Marketplace visible (solo loaders: forge, fabric, paper…)
```

#### Exclusiones de Java

`JAVA_EXCLUSION_KEYWORDS = ["bedrock", "nukkit", "bungeecord", "bungee", "velocity", "proxy"]`

Estos términos tienen prioridad sobre cualquier keyword del allowlist (ej. "Vanilla Bedrock" no activa el modal de EULA aunque "vanilla" esté en la lista Java).

#### Modal EULA

- Aparece cuando `isJavaSoftware && !eulaAccepted`.
- Requiere que el usuario lea y acepte los términos de Minecraft EULA.
- Botón **Aceptar EULA** → `useAcceptGameServerEula()`.

#### Modal Java version mismatch

- Aparece cuando `isJavaSoftware && javaMismatch`.
- Muestra la versión actual y la requerida.
- Botón **Corregir automáticamente** → `useFixGameServerJava()`.

#### Visuales por egg (`getSoftwareVisual`)

Cada tipo de egg tiene una combinación de **icono + color + badge** definida en `SOFTWARE_VISUAL`. Eggs soportados con visual dedicado:

Minecraft: `vanilla`, `paper`, `spigot`, `bukkit`, `fabric`, `forge`, `neoforge`, `quilt`, `arclight`, `sponge`, `folia`, `purpur`, `bungeecord`, `velocity`, `bedrock`, `nukkit`

Otros juegos: `tf2`, `insurgency`, `csgo`, `cs2`, `gmod`, `source`, `ark`, `arksa`, `rust`, `palworld`, `fivem`, `teamspeak`, `mumble`

Para eggs no listados usa un fallback genérico con `Gamepad2` como icono.

---

### Game Server — Mods y Archivos (`ModsManager`)

**Archivo:** `src/presentation/components/features/services/game-server/ModsManager.tsx`

#### Tabs del gestor

| Tab | Descripción | Visible cuando |
|---|---|---|
| **Archivos** | Explorador de archivos del servidor | Siempre |
| **Marketplace (Modrinth)** | Buscador e instalador de mods/plugins | `isModrinthSupported(eggName)` |

#### Directorio por defecto (`detectDirectory`)

| Tipo de egg | Directorio |
|---|---|
| Forge, Fabric, Quilt, NeoForge | `/mods` |
| Paper, Spigot, Bukkit, Arclight, Sponge, Folia, Purpur | `/plugins` |
| Resto | `/` (raíz) |

#### Soporte de Modrinth (`isModrinthSupported`)

Solo eggs con mod/plugin loader soportados:
- `forge`, `fabric`, `quilt`, `neoforge` → mods
- `spigot`, `paper`, `bukkit`, `purpur`, `arclight`, `sponge`, `folia` → plugins

**No soportan Modrinth:** vanilla, bedrock, nukkit, bungeecord, velocity, cualquier servidor de otros juegos (Rust, ARK, CS2…).

---

### Checkout multi-step (`CheckoutPage`)

**Archivo:** `src/presentation/pages/client/CheckoutPage.tsx`

**Hooks:** `useGameEggs`, `usePlanAddons`, `usePaymentMethods`

#### Flujo de pasos

```
Step 1: Selector de juego (solo si el plan es game_server)
   └── Grid de eggs agrupados por nido (Pterodactyl)
   └── Cada egg muestra: nombre, descripción, icono

Step 2: Configuración del servicio
   ├── Nombre del servidor / dominio
   ├── Add-ons opcionales (checkboxes con precio incremental)
   ├── Ciclo de facturación (mensual, semestral, anual)
   └── Datos de facturación (RFC, razón social, régimen SAT)
        └── Soporta perfiles fiscales guardados

Step 3: Pago y confirmación
   ├── Resumen del pedido con total
   ├── Selector de método de pago guardado
   ├── Formulario de nueva tarjeta (Stripe Elements)
   └── Botón "Confirmar y pagar"
```

#### Sidebar de resumen

Siempre visible durante el checkout, muestra:
- Plan seleccionado y precio base
- Add-ons seleccionados con precios individuales
- Ciclo de facturación y descuento aplicado
- Total actualizado en tiempo real

---

### Facturación (`ClientInvoicesPage`)

**Hooks:** `useInvoices`, `useInvoiceStats`, `usePaymentMethods`, `useTransactions`

#### Tabs

| Tab | Contenido |
|---|---|
| **Comprobantes** | Lista de facturas con filtros por estado (pendiente, pagada, vencida, cancelada) |
| **Métodos de pago** | Tarjetas guardadas, botón agregar (Stripe Elements), botón establecer predeterminado, eliminar |
| **Transacciones** | Historial de transacciones procesadas |

#### Acciones por factura

- **Pagar** — abre modal de selección de método de pago → `useProcessPayment()`
- **Descargar PDF** — descarga CFDI PDF via blob
- **Descargar XML** — descarga CFDI XML via blob
- **Descargar comprobante** — comprobante de pago interno
- **Actualizar datos fiscales** — disponible en ventana de 72h tras el pago

#### Flujo de pago de factura

1. Click "Pagar" → `PaymentModal` muestra métodos guardados
2. Usuario selecciona método o agrega uno nuevo
3. `POST /payments/process { invoice_id, payment_method_id }`
4. Backend procesa en Stripe y timbra CFDI si corresponde
5. Factura pasa a estado `paid`

---

### Tickets de soporte (`ClientTicketsPage`)

**Hooks:** `useTickets`, `useCreateTicket`, `useAddReply`, `useCloseTicket`

- Lista de tickets con filtros de estado y búsqueda.
- **Crear ticket** — modal con: categoría, asunto, descripción, adjuntos.
- **Detalle de ticket** — vista de conversación con historial de respuestas.
- **Chat en tiempo real** — `TicketChatDockPortal` dockable (portal React) para chat vía WebSocket (canal `private-chat.{roomId}`).
- **Cerrar ticket** — botón con confirmación.

---

### Perfil (`ClientProfilePage`)

**Hooks:** `useProfile`, `useSecurity`, `useTwoFactor`, `useSessions`

#### Tabs

| Tab | Contenido |
|---|---|
| **Perfil** | Foto (crop con react-image-crop), nombre, email, username |
| **Seguridad** | Cambiar contraseña, activar/desactivar 2FA (QR code), dispositivos activos |
| **Sesiones** | Lista de sesiones activas con IP, dispositivo, fecha. Botón revocar por sesión o "cerrar todas las otras" |

#### 2FA

1. `POST /2fa/generate` → devuelve secreto + imagen QR (Base64)
2. Usuario escanea con Google Authenticator / Authy
3. `POST /2fa/enable { code: "123456" }` → habilita 2FA
4. En futuros logins el backend requiere TOTP antes de emitir cookie

---

### Hosting HestiaCP (`HostingDetailPage`)

**Hook:** `useHosting(uuid)`

- **Info general** — panel, IP, nombre de cuenta, paquete.
- **Bases de datos** — lista, crear (nombre + usuario), eliminar.
- **Cuentas de email** — lista, crear, eliminar.
- **Dominios** — dominios adicionales en la cuenta, agregar, eliminar.
- **Estadísticas** — uso de disco, ancho de banda, archivos.

---

## Panel de Administración

### Dashboard admin (`AdminDashboardPage`)

**Hook:** `useAdminStats()`

**Métricas principales (4 cards):**
- Total de usuarios registrados
- Servicios activos
- Ingresos del mes
- Tickets abiertos

**Gráficas:**
- Ingresos mensuales (Recharts LineChart, últimos 12 meses)
- Nuevos usuarios por semana (BarChart)
- Distribución de tickets por estado (PieChart)

**Actividad reciente:** tabla de últimas acciones del sistema.

**Acciones rápidas:** links a Usuarios, Servicios, Facturas, Tickets.

---

### Gestión de servicios admin (`AdminServicesPage`)

**Hooks:** `useAdminServices`, `useCreateAdminService`, `useUpdateAdminService`, `useDeleteAdminService`

#### Lista principal

- Tabla paginada con selector de filas (10/25/50/100/150 por página).
- Columnas: nombre, usuario, plan, categoría, estado, próximo pago, acciones.
- Filtros: búsqueda libre, estado (`active`/`suspended`/`cancelled`/`pending`), plan.
- Acciones por fila: ver detalle, editar, suspender/reactivar, eliminar.

#### Sheet de creación / edición

Panel lateral (`Sheet`) con formulario contextual según la **categoría del servicio**:

| Categoría | Campos técnicos adicionales |
|---|---|
| `game_server` | **GameServerEggPicker** — grid interactivo de eggs agrupados por nido |
| `hosting` | Dominio, paquete de HestiaCP, credenciales |
| `vps` | CPU, RAM, disco, nodo de Proxmox |
| `database` | Motor (MySQL/PostgreSQL), nombre, credenciales |
| `profesionales` | Descripción del servicio, entregables |

#### GameServerEggPicker

Componente interno de `AdminServicesPage`:
- Carga eggs via `useGameEggs(planUuid)` (mismo endpoint que el checkout).
- Muestra grid de eggs agrupados por nido.
- Al seleccionar: establece `egg_id` y `game` en la configuración.
- Campo de override manual para IDs de egg no listados.
- En modo edición: pre-selecciona el egg actual del servicio.
- Muestra badge con egg seleccionado.

---

### Gestión de usuarios admin (`AdminUsersPage`)

**Hooks:** `useUsers`, `useCreateUser`, `useUpdateUser`, `useDeleteUser`, `useChangeUserStatus`

- Tabla paginada con filtros: búsqueda, estado, rol.
- **Stats cards** en la parte superior: total, activos, suspendidos, administradores.
- Sheet de creación/edición: nombre, email, rol, estado, contraseña temporal.
- Acciones: cambiar estado (activo/suspendido), ver servicios del usuario, eliminar.

---

### Gestión de facturas admin (`AdminInvoicesPage`)

**Hooks:** `useAdminInvoices`, `useAdminInvoicesStats`, `useMarkInvoiceAsPaid`, `useCancelInvoice`

- Tabla de facturas con filtros: estado, búsqueda, rango de fechas.
- Stats: total cobrado, pendiente, vencidas, canceladas.
- Acciones: marcar pagada, enviar recordatorio, cancelar, ver detalle, descargar comprobante.

---

### CFDI admin (`AdminCfdiPage`)

**Hooks:** `useAdminCfdis`, `useAdminCfdiStats`, `useRetryCfdi`, `useCancelCfdi`, `useDownloadAdminCfdi`

- Lista de CFDIs con estado de timbrado (timbrado, fallido, cancelado).
- Stats: total timbrados, fallidos, monto total timbrado.
- **Reintentar timbrado** — para CFDIs con estado `failed`. Vuelve a llamar a Facturama.
- **Cancelar CFDI** — cancela ante el SAT (requiere motivo de cancelación).
- **Descargar PDF/XML** — descarga blob del CFDI.

---

### Tickets admin (`AdminTicketsPage`)

**Hooks:** `useAdminTickets`, `useAdminTicketsStats`, `useChangeTicketStatus`, `useChangeTicketPriority`, `useAssignTicket`

- Vista de lista con filtros avanzados: estado, prioridad, agente asignado, categoría.
- Stats: abiertos, en progreso, resueltos, promedio de resolución.
- **AdminTicketSheet** — sheet lateral con:
  - Historial completo de conversación.
  - Formulario de respuesta con adjuntos.
  - Selector de estado y prioridad.
  - Selector de agente para asignación.
  - Botones cerrar / reabrir.
  - Chat en tiempo real integrado.

---

### Game Servers admin (`AdminGameServersPage`)

**Hooks:** `useAdminGameServers`, `useProvisionGameServer`, `useSuspendGameServer`, `useReinstallGameServer`

- Lista de servers con estado en Pterodactyl (online/offline/installing).
- **Provisionar** — llama al backend que crea el servidor en Pterodactyl Panel.
- **Suspender / Desuspender** — congela el servidor en Pterodactyl.
- **Reinstalar** — reinstala el egg en el servidor.
- **Eliminar** — termina y elimina el servidor de Pterodactyl.

---

### Versiones de software (`AdminGameVersionsPage`)

**Hooks:** `useAdminGameVersions`, `useCreateAdminGameVersion`, `useBulkAdminGameVersions`

- Tabla de versiones con columnas: software, versión, URL de descarga, estado (habilitado/recomendado).
- Selector de filas por página (10/25/50/100/150).
- Checkboxes para selección múltiple → acciones en lote: habilitar, deshabilitar, eliminar.
- Formulario de creación/edición: software identifier, versión, URL, flags.

---

### Cotizaciones (`AdminQuotationsPage` + `AdminQuotationDetailPage`)

**Hooks:** `useQuotations`, `useSendQuotation`, `useAcceptQuotation`, `useCreateQuotationRevision`

#### Ciclo de vida de una cotización

```
draft → sent → viewed → accepted ✓
                      → rejected ✗
                            → reopen → draft (nueva revisión)
```

#### Funcionalidades

- Crear cotización con líneas de items, precios, descuentos, fecha de validez.
- **Enviar** — envía email al cliente con link público.
- **Link público** (`/cotizacion/:token`) — el cliente puede aceptar o rechazar sin auth.
- **Marcar vista** — cuando el cliente abre el link, se registra automáticamente.
- **Revisiones** — crear nueva versión de la cotización preservando el historial.
- **Regenerar link** — invalida el token anterior y genera uno nuevo.

---

### Blog (`AdminBlogPage` + `AdminBlogEditorPage`)

**Editor:** Tiptap v3 (extensiones: Bold, Italic, Heading, BulletList, Code, Image, Link, Table, Highlight, TextAlign, Placeholder, Youtube)

- **Lista de posts** con estado (borrador, publicado), fecha, categoría.
- **Editor** — rich text completo, subida de imágenes via `POST /admin/blog/upload-image`.
- **Categorías** — CRUD de categorías del blog.
- **Suscriptores** — lista de suscriptores con opción de eliminar.

---

### Búsqueda global admin (`AdminHeader` + `AdminSearch`)

- Atajo de teclado `Ctrl+K` / `Cmd+K` → abre modal de búsqueda.
- Debounce de 400ms, mínimo 2 caracteres.
- Resultados agrupados por tipo: usuarios, servicios, facturas, tickets.
- Historial de búsquedas recientes en localStorage.
- Términos populares cargados desde `GET /admin/search/popular`.

---

### Notificaciones admin

- Bell icon en el header con badge de no leídas.
- Dropdown de notificaciones recientes.
- `AdminNotificationsPage` — centro completo: lista, marcar leída, archivar, eliminar.
- **Broadcast** — enviar notificación a todos los usuarios del sistema.
- **Enviar a usuario** — notificación personalizada a un usuario específico.

---

## Funcionalidades transversales

### Perfiles fiscales (CFDI 4.0)

Disponibles tanto en el checkout como en la edición de facturas:

- El usuario puede guardar múltiples perfiles fiscales (RFC, razón social, régimen fiscal SAT, uso de CFDI).
- Al seleccionar un perfil guardado, se auto-rellenan los campos.
- Validación de RFC mexicano.
- Consulta de código postal via `GET /postal-codes/{code}` → auto-rellena colonia y municipio.
- El CFDI se puede actualizar en las **72 horas** siguientes al pago.

### Notificaciones en tiempo real

- Canal `private-user.{userId}` (Reverb) → badge de notificaciones se actualiza sin polling.
- Toast automático al recibir notificación nueva.
- Tipos de notificación: pago procesado, servicio próximo a vencer, ticket respondido, CFDI emitido.

### Responsive design

- Tailwind CSS v4 con breakpoints estándar (`sm`, `md`, `lg`, `xl`, `2xl`).
- `use-mobile` hook detecta breakpoint `< 768px` para ajustar comportamiento de componentes.
- Sidebar admin colapsable en pantallas pequeñas.
- Tablas con scroll horizontal en móvil.
