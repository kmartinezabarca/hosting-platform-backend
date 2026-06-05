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

> Middleware: `auth:sanctum` + `session.timeout` + control de rol por grupo
> (`role:…`). Todas las rutas usan el prefijo `/admin`.
>
> **Acceso por rol** (ver [Roles y acceso](#roles-y-acceso-del-panel-admin)):
> - `support` → usuarios (solo lectura), servicios, dominios, tickets/chat,
>   documentación, api-documentation y estado del sistema.
> - `admin` + `super_admin` → todo el negocio (finanzas, catálogo, analytics,
>   blog, cotizaciones, etc.) **excepto** backups y auditoría.
> - `super_admin` → además backups y log de auditoría.

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

## Roles y acceso del panel admin

El módulo admin se divide en grupos por rol mediante el middleware `role:`. Si el
usuario autenticado no tiene un rol permitido en el grupo, responde `403` con
`{"success": false, "error_code": "INSUFFICIENT_PRIVILEGES"}`. Sin sesión → `401`.

| Recurso | support | admin | super_admin |
|---|:---:|:---:|:---:|
| Usuarios (lectura `GET /admin/users`) | ✅ | ✅ | ✅ |
| Usuarios (crear/editar/eliminar/estado + herramientas) | — | ✅ | ✅ |
| Servicios, Dominios, Tickets/Chat | ✅ | ✅ | ✅ |
| Documentación, API-docs, System status | ✅ | ✅ | ✅ |
| Analytics (dashboard de ingresos) | ✅ | ✅ | ✅ |
| Dashboard stats, Facturas, Catálogo, Blog, Cotizaciones, CFDI/Fiscal, Pet, Solicitudes | — | ✅ | ✅ |
| Backups, Log de auditoría | — | — | ✅ |

> Nota: `support` ve el **dashboard de analytics** (§0), pero la **gestión
> financiera** (facturas, reembolsos, CFDI) permanece restringida a
> admin/super_admin.

## Herramientas de soporte sobre el usuario (admin / super_admin)

| Método | Endpoint | Descripción |
|---|---|---|
| POST | `/admin/users/{id}/impersonate` | Inicia suplantación de un cliente. Responde `{ "data": { "redirect_url": "…/client/dashboard?impersonation_token=…" } }`. El token es de **un solo uso** (TTL 60 s). |
| POST | `/admin/users/{id}/reset-2fa` | Desactiva el 2FA del usuario (deberá reconfigurarlo). |
| POST | `/admin/users/{id}/send-password-reset` | Envía correo de restablecimiento de contraseña (broker de Laravel). |

### Suplantación — intercambio de sesión (auth)

| Método | Endpoint | Auth | Descripción |
|---|---|---|---|
| POST | `/auth/impersonate/exchange` | Público (throttle 5/min) | Body `{ "token": "…" }`. Canjea el token de un solo uso por una **sesión (cookie) del cliente**. Responde `{ "data": { "user", "impersonated": true, "redirect_to": "/client/dashboard" } }`. |
| POST | `/auth/impersonate/leave` | `auth:sanctum` | Termina la suplantación y **restaura la sesión del admin** original. |

## Solicitudes de usuario

Solicitudes (`documentation_request` / `api_documentation_request`) que el cliente
envía y el staff aprueba o rechaza. Al resolverse se notifica al solicitante
(in-app + broadcast).

**Cliente** (`auth:sanctum`):

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/user-requests` | Lista las solicitudes propias (filtro `status`) |
| POST | `/user-requests` | Crea una solicitud `{ kind, subject, description? }` |
| GET | `/user-requests/{id}` | Detalle de una solicitud propia |

**Admin** (`admin` / `super_admin`):

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/user-requests` | Lista (filtros: `status`, `kind`, `search`, `page`, `per_page`) |
| GET | `/admin/user-requests/{id}` | Detalle |
| POST | `/admin/user-requests/{id}/approve` | Aprobar `{ note? }` |
| POST | `/admin/user-requests/{id}/reject` | Rechazar `{ reason (requerido) }` |

## Reembolsos de facturas (admin / super_admin)

| Método | Endpoint | Descripción |
|---|---|---|
| POST | `/admin/invoices/{id}/refund` | Reembolsa una factura **pagada** vía Stripe. Body `{ amount? (parcial; omitir = total), reason (requerido) }`. Marca la factura como `refunded` y registra una transacción `refund`. |

## Analytics (support / admin / super_admin)

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/analytics/overview` | Métricas de ingresos. Query `range` ∈ `7d\|30d\|90d\|12m` (default `30d`). |

Respuesta `data`: `range`, `currency`, `revenue_total`, `revenue_change_pct`,
`mrr`, `mrr_change_pct`, `arr`, `churn_rate`, `new_customers`,
`active_subscriptions`, `arpu`, `ltv`, `revenue_series[]`, `customers_series[]`,
`plan_distribution[]`, `revenue_by_category[]`.

> Montos sumados **sin conversión FX** (datos en USD/MXN mezclados); `currency`
> es solo etiqueta de reporte. Revenue = transacciones `payment` completadas
> (bruto). MRR normaliza ciclos (anual/semanal/diario) a mensual.

## Log de auditoría (solo super_admin)

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/admin/audit-logs` | Listado paginado. Filtros: `actor_id`, `action`, `target_type`, `from`, `to`, `search`, `page`, `per_page`. |
| GET | `/admin/audit-logs/actions` | Lista de `action` distintos (para filtros). |

Cada entrada: `id`, `actor_id`, `actor_name`, `actor_email`, `actor_role`,
`action`, `target_type`, `target_id`, `description`, `ip_address`, `user_agent`,
`changes` (`{ campo: [antes, después] }`), `created_at`.

Acciones registradas actualmente: `user.impersonated`, `user.impersonation_ended`,
`user.two_factor_reset`, `user.password_reset_sent`, `user.deleted`,
`user.status_changed`, `service.status_changed`, `invoice.cancelled`,
`invoice.refunded`, `user_request.approved`, `user_request.rejected`,
`plan.created`, `plan.updated`, `plan.deleted`.
