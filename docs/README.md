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
