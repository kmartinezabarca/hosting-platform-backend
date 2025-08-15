# Hosting Platform Backend

API backend para la plataforma de gestión de servicios de hosting desarrollada con Laravel.

## Características

- **Gestión de Usuarios**: Sistema completo de autenticación y autorización con roles
- **Servicios de Hosting**: Gestión de hosting web, VPS y servidores de juegos
- **Registro de Dominios**: Integración con registradores como Namecheap
- **Sistema de Facturación**: Procesamiento de pagos con Stripe y PayPal
- **Soporte Técnico**: Sistema de tickets integrado
- **Aprovisionamiento Automático**: Integración con Proxmox y Pterodactyl

## Requisitos

- PHP 8.1 o superior
- MySQL 8.0 o superior
- Redis
- Composer
- Node.js (para compilar assets)

## Instalación

### 1. Clonar el repositorio

```bash
git clone <repository-url>
cd hosting-platform/backend
```

### 2. Instalar dependencias

```bash
composer install
```

### 3. Configurar el entorno

```bash
cp .env.example .env
php artisan key:generate
```

Edita el archivo `.env` con tu configuración:

```env
# Base de datos
DB_DATABASE=hosting_platform
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_contraseña

# Configuración de Proxmox
PROXMOX_URL=https://tu-proxmox.com:8006
PROXMOX_USERNAME=api@pve
PROXMOX_PASSWORD=tu_contraseña_api

# Configuración de Pterodactyl
PTERODACTYL_URL=https://tu-panel.com
PTERODACTYL_API_KEY=tu_api_key

# Configuración de Namecheap
NAMECHEAP_API_USER=tu_usuario
NAMECHEAP_API_KEY=tu_api_key
NAMECHEAP_CLIENT_IP=tu_ip_servidor

# Configuración de Stripe
STRIPE_KEY=pk_test_tu_clave_publica
STRIPE_SECRET=sk_test_tu_clave_secreta
STRIPE_WEBHOOK_SECRET=whsec_tu_webhook_secret
```

### 4. Ejecutar migraciones

```bash
php artisan migrate
```

### 5. Ejecutar seeders (opcional)

```bash
php artisan db:seed
```

### 6. Configurar Sanctum

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### 7. Iniciar el servidor

```bash
php artisan serve
```

La API estará disponible en `http://localhost:8000`

## Estructura del Proyecto

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── Admin/          # Controladores de administración
│   │   │   ├── Client/         # Controladores de cliente
│   │   │   └── AuthController.php
│   │   └── Controller.php
│   ├── Middleware/
│   ├── Requests/               # Form Requests para validación
│   └── Resources/              # API Resources
├── Models/                     # Modelos Eloquent
├── Services/                   # Servicios de aplicación
│   ├── Provisioning/          # Módulos de aprovisionamiento
│   └── ProvisioningService.php
├── Jobs/                      # Jobs para colas
├── Events/                    # Eventos del sistema
├── Listeners/                 # Listeners de eventos
├── Policies/                  # Políticas de autorización
└── Contracts/                 # Interfaces y contratos

database/
├── migrations/                # Migraciones de base de datos
├── seeders/                   # Seeders para datos de prueba
└── factories/                 # Factories para testing

routes/
├── api.php                    # Rutas de la API
├── web.php                    # Rutas web
└── console.php                # Comandos de consola
```

## API Endpoints

### Autenticación

```
POST /api/auth/register        # Registrar usuario
POST /api/auth/login          # Iniciar sesión
POST /api/auth/logout         # Cerrar sesión
GET  /api/auth/user           # Obtener usuario autenticado
```

### Administración

```
GET    /api/admin/dashboard           # Dashboard de administración
GET    /api/admin/users              # Listar usuarios
POST   /api/admin/users              # Crear usuario
GET    /api/admin/users/{id}         # Ver usuario
PUT    /api/admin/users/{id}         # Actualizar usuario
DELETE /api/admin/users/{id}         # Eliminar usuario

GET    /api/admin/services           # Listar servicios
POST   /api/admin/services           # Crear servicio
GET    /api/admin/services/{id}      # Ver servicio
PUT    /api/admin/services/{id}      # Actualizar servicio
POST   /api/admin/services/{id}/suspend    # Suspender servicio
POST   /api/admin/services/{id}/unsuspend  # Reactivar servicio
```

### Cliente

```
GET    /api/client/services          # Mis servicios
GET    /api/client/services/{id}     # Ver servicio
POST   /api/client/services/{id}/restart   # Reiniciar servicio
POST   /api/client/services/{id}/stop      # Detener servicio
POST   /api/client/services/{id}/start     # Iniciar servicio
GET    /api/client/services/{id}/stats     # Estadísticas del servicio

GET    /api/client/invoices          # Mis facturas
GET    /api/client/invoices/{id}     # Ver factura
POST   /api/client/invoices/{id}/pay # Pagar factura

GET    /api/client/tickets           # Mis tickets
POST   /api/client/tickets           # Crear ticket
POST   /api/client/tickets/{id}/reply # Responder ticket
```

## Módulos de Aprovisionamiento

### Proxmox

Gestiona máquinas virtuales para hosting web y VPS:

- Creación de VMs desde plantillas
- Gestión del ciclo de vida (inicio, parada, reinicio)
- Monitoreo de recursos
- Gestión de snapshots y backups

### Pterodactyl

Gestiona servidores de juegos:

- Creación de servidores de Minecraft, Rust, etc.
- Gestión de archivos del servidor
- Consola en tiempo real
- Monitoreo de recursos y jugadores

### Namecheap

Gestiona dominios:

- Registro de dominios
- Renovación automática
- Gestión de DNS
- WHOIS privacy

## Colas y Jobs

El sistema utiliza colas para procesar tareas que requieren tiempo:

```bash
# Iniciar worker de colas
php artisan queue:work

# Procesar jobs específicos
php artisan queue:work --queue=provisioning,billing,notifications
```

### Jobs Principales

- `ProvisionServiceJob`: Aprovisiona nuevos servicios
- `ProcessPaymentJob`: Procesa pagos recibidos
- `SendInvoiceJob`: Envía facturas por correo
- `RenewDomainJob`: Renueva dominios automáticamente

## Testing

```bash
# Ejecutar tests
php artisan test

# Ejecutar tests con coverage
php artisan test --coverage
```

## Comandos Artisan Personalizados

```bash
# Verificar servicios vencidos
php artisan hosting:check-overdue

# Generar facturas mensuales
php artisan hosting:generate-invoices

# Sincronizar con proveedores
php artisan hosting:sync-providers

# Limpiar servicios terminados
php artisan hosting:cleanup-terminated
```

## Configuración de Producción

### 1. Optimizaciones

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### 2. Configurar Supervisor para colas

```ini
[program:hosting-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=8
redirect_stderr=true
stdout_logfile=/var/log/hosting-worker.log
stopwaitsecs=3600
```

### 3. Configurar Cron Jobs

```bash
# Agregar al crontab
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

## Seguridad

- Autenticación basada en tokens con Laravel Sanctum
- Rate limiting en todas las rutas de API
- Validación de entrada con Form Requests
- Autorización basada en políticas
- Encriptación de credenciales de API sensibles
- Logs de auditoría para acciones críticas

## Monitoreo

- Logs estructurados con contexto
- Métricas de rendimiento
- Alertas para fallos de aprovisionamiento
- Monitoreo de servicios externos

## Contribución

1. Fork el proyecto
2. Crea una rama para tu feature (`git checkout -b feature/nueva-funcionalidad`)
3. Commit tus cambios (`git commit -am 'Agregar nueva funcionalidad'`)
4. Push a la rama (`git push origin feature/nueva-funcionalidad`)
5. Crea un Pull Request

## Licencia

Este proyecto está licenciado bajo la Licencia MIT.
