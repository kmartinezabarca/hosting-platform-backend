# Migración de Pusher a Laravel Reverb

## Resumen de Cambios

Este documento describe la migración completa de Pusher a Laravel Reverb para el sistema de notificaciones en tiempo real de la plataforma de hosting.

## Cambios Realizados

### 1. Configuración de Broadcasting

- **Archivo modificado**: `config/broadcasting.php`
  - Cambiado el driver por defecto de `null` a `reverb`
  - Eliminada la configuración de Pusher
  - Mantenida la configuración de Reverb

- **Archivo modificado**: `.env`
  - Eliminadas todas las variables de Pusher
  - Cambiado `BROADCAST_DRIVER=reverb`
  - Añadidas variables de configuración de Reverb:
    - `REVERB_APP_ID=142248`
    - `REVERB_APP_KEY=febyeijqtsstmmab9dsc`
    - `REVERB_APP_SECRET=tfjwnpr3vfhxtykrdohk`
    - `REVERB_HOST="localhost"`
    - `REVERB_PORT=8080`
    - `REVERB_SCHEME=http`

### 2. Canales de Broadcasting

- **Archivo modificado**: `routes/channels.php`
  - Configurados canales diferenciados para usuarios y administradores
  - Canales de usuario: `user.{userId}`
  - Canales administrativos:
    - `admin.services`
    - `admin.payments`
    - `admin.invoices`
    - `admin.maintenance`
    - `admin.users`
    - `admin.tickets`
    - `admin.notifications`
    - `admin.super` (solo super administradores)
  - Canales de presencia para chat en tiempo real
  - Canal de sistema para notificaciones generales

### 3. Nuevos Eventos Creados

#### Eventos de Servicios:
- `ServiceMaintenanceScheduled` - Mantenimiento programado
- `ServiceMaintenanceCompleted` - Mantenimiento completado
- `ServicePurchased` - Servicio adquirido
- `ServiceReady` - Servicio listo para usar
- `InvoiceStatusChanged` - Cambio de estado de factura

#### Eventos de Pagos:
- `PaymentFailed` - Pago fallido
- `AutomaticPaymentProcessed` - Pago automático procesado

### 4. Listeners y Notificaciones

#### Nuevos Listeners:
- `CreateServiceNotification` - Maneja todas las notificaciones de servicios
- `CreatePaymentNotification` - Maneja todas las notificaciones de pagos

#### Nuevas Notificaciones:
- `ServiceNotification` - Notificación general para servicios
- `PaymentNotification` - Notificación general para pagos

### 5. EventServiceProvider Actualizado

- Registrados todos los nuevos eventos con sus respectivos listeners
- Configuración de métodos específicos para cada tipo de evento

## Tipos de Notificaciones Implementadas

### Para Clientes:
1. **Compra de Servicios**
   - Confirmación de compra
   - Servicio listo para usar
   - Cambios de estado del servicio

2. **Pagos**
   - Pago procesado exitosamente
   - Pago fallido
   - Pago automático procesado

3. **Facturas**
   - Nueva factura generada
   - Cambio de estado de factura
   - Factura vencida

4. **Mantenimiento**
   - Mantenimiento programado
   - Mantenimiento completado

### Para Administradores:
1. **Gestión de Servicios**
   - Nuevas compras de servicios
   - Cambios de estado de servicios
   - Servicios que requieren atención

2. **Gestión de Pagos**
   - Pagos recibidos
   - Pagos fallidos
   - Pagos automáticos

3. **Monitoreo del Sistema**
   - Actividad de usuarios
   - Métricas de servicios
   - Alertas del sistema

## Configuración de Producción

### Variables de Entorno Requeridas:
```env
BROADCAST_DRIVER=reverb
REVERB_APP_ID=your_app_id
REVERB_APP_KEY=your_app_key
REVERB_APP_SECRET=your_app_secret
REVERB_HOST=your_domain.com
REVERB_PORT=443
REVERB_SCHEME=https
```

### Comandos para Despliegue:
```bash
# Limpiar caché
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan event:clear

# Cachear configuración para producción
php artisan config:cache
php artisan event:cache
php artisan route:cache

# Iniciar servidor Reverb
php artisan reverb:start --host=0.0.0.0 --port=8080
```

## Uso del Sistema

### Disparar Eventos desde el Código:
```php
// Ejemplo: Cambio de estado de servicio
event(new ServiceStatusChanged($service, $oldStatus, $newStatus));

// Ejemplo: Pago procesado
event(new PaymentProcessed($transaction));

// Ejemplo: Mantenimiento programado
event(new ServiceMaintenanceScheduled($service, $startTime, $endTime, $description));
```

### Escuchar Eventos en el Frontend:
```javascript
// Conectar a Reverb
const echo = new Echo({
    broadcaster: 'reverb',
    key: process.env.VITE_REVERB_APP_KEY,
    wsHost: process.env.VITE_REVERB_HOST,
    wsPort: process.env.VITE_REVERB_PORT,
    wssPort: process.env.VITE_REVERB_PORT,
    forceTLS: process.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
});

// Escuchar notificaciones de usuario
echo.private(`user.${userId}`)
    .listen('service.status.changed', (e) => {
        console.log('Service status changed:', e);
    })
    .listen('payment.processed', (e) => {
        console.log('Payment processed:', e);
    });

// Escuchar notificaciones de admin (solo para administradores)
echo.private('admin.services')
    .listen('service.purchased', (e) => {
        console.log('New service purchased:', e);
    });
```

## Beneficios de la Migración

1. **Costo Reducido**: Laravel Reverb es gratuito vs Pusher que es de pago
2. **Control Total**: Servidor propio sin dependencias externas
3. **Mejor Rendimiento**: Comunicación directa sin intermediarios
4. **Escalabilidad**: Fácil escalamiento horizontal
5. **Privacidad**: Datos no salen del servidor propio
6. **Integración Nativa**: Mejor integración con el ecosistema Laravel

## Notas Importantes

- Todos los eventos implementan `ShouldBroadcast` para transmisión en tiempo real
- Las notificaciones se almacenan en base de datos y se transmiten por WebSocket
- Los canales están protegidos con autenticación y autorización
- El sistema diferencia automáticamente entre notificaciones de cliente y admin
- Todas las notificaciones incluyen timestamps y datos estructurados

