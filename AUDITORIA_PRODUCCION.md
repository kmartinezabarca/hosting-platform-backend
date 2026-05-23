# Auditoría de Seguridad y Calidad — Hosting Platform Backend
> Fecha: 2026-05-22 · Estado: **COMPLETADA**

---

## PARTE 1 — LO QUE SE HIZO (cambios en el código)

Todos los cambios ya están aplicados directamente en el repositorio (rama `develop`).

---

### 1.1 Seguridad crítica

#### `app/Http/Controllers/Client/SubscriptionController.php`
- **CRÍTICO** — Se agregó verificación de propiedad del servicio antes de crear una suscripción:
  un usuario ya no puede crear suscripciones en servicios de otro usuario.
- **CRÍTICO** — Se agregó validación de que el `price_id` existe en el catálogo propio
  (antes cualquier `price_id` de Stripe era aceptado).
- **CRÍTICO** — Se agregó verificación de que el método de pago pertenece al cliente de Stripe correcto.
- Se reescribió `getOrCreateStripeCustomer()` para reusar el `stripe_customer_id` guardado
  y evitar crear clientes duplicados en Stripe.

#### `app/Http/Controllers/Admin/AgentController.php`
- Se agregó whitelist para `sort_by` en `tickets()` — evita inyección en cláusula ORDER BY.

---

### 1.2 Configuración y variables de entorno

#### `config/pterodactyl.php`
Se agregaron tres claves nuevas:
```php
'wings_internal_url' => env('PTERODACTYL_WINGS_INTERNAL_URL', 'http://100.94.93.51:8080'),
'wings_public_url'   => env('PTERODACTYL_WINGS_PUBLIC_URL',   'https://mc.rokeindustries.com'),
'verify_ssl'         => env('PTERODACTYL_VERIFY_SSL', true),
```

#### `config/coolify.php`
Se agregó:
```php
'verify_ssl' => env('COOLIFY_VERIFY_SSL', true),
```

---

### 1.3 SSL condicional (nunca hardcodeado)

| Archivo | Cambio |
|---------|--------|
| `app/Services/Coolify/CoolifyService.php` | `withoutVerifying()` condicionado a `COOLIFY_VERIFY_SSL=false` |
| `app/Console/Commands/SyncPterodactylEggs.php` | `withoutVerifying()` condicionado a `PTERODACTYL_VERIFY_SSL=false` |
| `app/Http/Controllers/Admin/GameServerController.php` | Todos los `withoutVerifying()` condicionados al env var |
| `app/Http/Controllers/Client/GameServerController.php` | Todos los `withoutVerifying()` condicionados al env var |

---

### 1.4 IPs y URLs hardcodeadas eliminadas

| Archivo | Cambio |
|---------|--------|
| `app/Http/Controllers/Client/GameServerController.php` | Helper `rewriteWingsUrl()` usando config en lugar de IP fija |
| `app/Http/Controllers/Admin/GameServerController.php` | Mismo helper `rewriteWingsUrl()` |

---

### 1.5 Vulnerabilidades de Path Traversal (file manager)

| Archivo | Cambio |
|---------|--------|
| `app/Http/Controllers/Admin/GameServerController.php` | Regla `not_regex:/\.\.[\/\\]/` en `directory`, `root`, `files.*`, `file` |
| `app/Http/Controllers/Client/FileManagerController.php` | Ya tenía la validación aplicada |

---

### 1.6 Mass assignment — modelos protegidos

| Modelo | Cambio |
|--------|--------|
| `app/Models/ServiceAddOn.php` | Se eliminó `$guarded = []` y se definió `$fillable` explícito |
| `app/Models/User.php` | Se agregaron los 6 campos de preferencias de notificación a `$fillable` y `$casts` — **fix de bug silencioso**: las preferencias no se guardaban nunca porque los campos no estaban en `$fillable` |

---

### 1.7 Middlewares

#### `app/Http/Kernel.php`
- Se eliminó el throttle duplicado en el grupo `api` que hacía que cada request
  contara doble contra el rate limiter.

---

### 1.8 Controladores Admin — filtros y paginación

| Archivo | Cambio |
|---------|--------|
| `AdminController.php` | Whitelist para `status` y `role` en `getUsers()` |
| `AdminController.php` | Validación `max:100` en `payment_method` y `max:500` en `notes`/`reason` |
| `CfdiController.php` | `per_page` capped a 100 + `status` validado contra valores SAT permitidos |
| `BillingCycleController.php` | `$request->all()` → `$validator->validated()` en `store()` y `update()` |
| `CategoryController.php` | `$request->all()` → `$validator->validated()` en `store()` y `update()` |
| `ProductController.php` | `$request->all()` → `$validator->validated()` en `store()` y `update()` |
| `FiscalController.php` | `per_page` capped a 100 en `profiles()` |
| `ChatController.php` | `per_page` capped a 100 en `getActiveRooms()` y `getAllRooms()` |
| `NotificationController.php` | `action_url` → `url\|max:500`, `action_text` → `max:100` en broadcast y sendToUser |
| `GlobalSearchController.php` | `q` truncado a 100 chars — evita queries LIKE costosísimas |
| `PetSearchController.php` | `q` truncado a 100 chars |

---

### 1.9 Controladores Client — validaciones

| Archivo | Cambio |
|---------|--------|
| `InvoiceController.php` | Mensajes de error de PDF/XML protegidos con `config('app.debug')` |
| `InvoiceController.php` | Se eliminaron métodos muertos `store()` y `updateStatus()` (sin rutas) |
| `TicketController.php` | Whitelist en filtros `status` y `priority`; `per_page` capped a 100 |
| `TransactionController.php` | Filtros validados; `per_page` capped a 100; validación de rango de fechas |
| `PaymentController.php` | Mensaje de error de RuntimeException mapeado, sin exponer detalles técnicos |
| `ProfileController.php` | `password` → `max:255` en `updatePassword()`; `per_page` capped a 100 en `getSessions()` |
| `ServiceController.php` | Nombre de backup truncado a 160 chars |
| `ClientSearchController.php` | `q` truncado a 100 chars |

---

### 1.10 Controladores Auth — validaciones

| Archivo | Cambio |
|---------|--------|
| `AuthController.php` | `password` → `max:255` en `register()` |
| `PasswordResetController.php` | `password` → `max:255` en `reset()` |
| `TwoFactorController.php` | `password` → `max:255` en `disable()` |

---

### 1.11 ROKE Pet — URLs de imágenes (bug de fotos no visibles)

#### `app/Http/Controllers/Pet/PetController.php`
- **BUG CORREGIDO** — `publicStorageUrl()` usaba `$request->getSchemeAndHttpHost()` para construir
  la URL de las fotos y guardarla en la base de datos. Detrás de un proxy inverso (nginx con SSL
  termination) esto devolvía `http://` o la IP interna, por lo que la URL almacenada era incorrecta
  y las imágenes no se mostraban.
- **Fix**: ahora usa `asset('storage/' . $path)` que lee `APP_URL` del entorno y siempre genera
  la URL pública correcta (`https://tudominio.com/storage/...`).
- Se eliminó el parámetro `$request` del método (ya no se necesita) y se actualizaron los dos
  llamadores: `uploadPhoto()` y `uploadCover()`.

---

## PARTE 2 — LO QUE DEBES HACER TÚ

Estas son acciones que requieren intervención manual tuya (no son cambios de código).

---

### 2.1 Variables de entorno — PRODUCCIÓN

Agregar al `.env` de **producción** las siguientes variables:

```dotenv
# ── Pterodactyl ──────────────────────────────────────────────
# SSL habilitado en producción (siempre true)
PTERODACTYL_VERIFY_SSL=true

# IP interna de Wings (servidor Pterodactyl)
PTERODACTYL_WINGS_INTERNAL_URL=http://100.94.93.51:8080

# Dominio público de Wings que ven los clientes
PTERODACTYL_WINGS_PUBLIC_URL=https://mc.rokeindustries.com

# ── Coolify ──────────────────────────────────────────────────
COOLIFY_VERIFY_SSL=true

# ── Mailcow ──────────────────────────────────────────────────
MAILCOW_BASE_URL=https://mail.tudominio.com
MAILCOW_API_KEY=tu-api-key-de-mailcow
MAILCOW_DEFAULT_QUOTA_MB=500
```

---

### 2.2 Variables de entorno — DESARROLLO LOCAL

Agregar al `.env` de **desarrollo** (para que las conexiones sin SSL funcionen):

```dotenv
PTERODACTYL_VERIFY_SSL=false
COOLIFY_VERIFY_SSL=false
```

---

### 2.3 Symlink de Storage — OBLIGATORIO para imágenes de ROKE Pet

Las fotos de mascotas se guardan en `storage/app/public/` pero se sirven desde `public/storage/`.
Si el symlink no existe, **todas las imágenes devuelven 404**.

Ejecutar **una sola vez en el servidor**:

```bash
php artisan storage:link
```

Verifica que el directorio `public/storage` exista (como link simbólico) después de ejecutarlo.

> Si ya lo ejecutaste antes, no hace falta repetirlo — a menos que el symlink se haya borrado.

---

### 2.4 Migración pendiente

Si no la has ejecutado todavía, corre:

```bash
php artisan migrate
```

> Esta migración crea la tabla `server_nodes` para Pterodactyl que se añadió
> en la sesión anterior.

---

### 2.5 Verificar Sanctum — expiración de tokens

Los tokens de Sanctum no expiran por defecto. En producción se recomienda configurar
un tiempo de expiración en `config/sanctum.php`:

```php
// config/sanctum.php
'expiration' => 1440, // minutos — 24 horas para clientes
```

O bien en `.env`:
```dotenv
SANCTUM_TOKEN_EXPIRATION=1440
```

> Esto no es un bug crítico, pero es buena práctica para que los tokens viejos
> no sean válidos indefinidamente.

---

### 2.6 Revisar STATUS en tu base de datos (usuarios)

La whitelist de `status` en `AdminController::getUsers()` ahora acepta:
```
active | suspended | pending_verification | banned
```
Verifica que los valores que usas en la columna `users.status` coincidan exactamente
con esta lista. Si tienes otros valores distintos, agrégalos al whitelist en:

```
app/Http/Controllers/Admin/AdminController.php
→ método getUsers(), línea con $allowed = ['active', ...]
```

---

### 2.7 Revisar ROLES en tu base de datos (usuarios)

La whitelist de `role` en `AdminController::getUsers()` ahora acepta:
```
super_admin | admin | support | client
```
Si tienes otros roles, agrégalos en la misma función.

---

### 2.8 Limpiar caché y verificar en producción

Después de hacer el deploy, ejecutar:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

---

### 2.9 Test manual recomendado antes de ir a producción

| Qué probar | Por qué |
|------------|---------|
| Login + logout | Se tocó AuthController |
| Crear suscripción con un servicio de otro usuario | Fix de seguridad crítico |
| Actualizar preferencias de notificación | Bug silencioso corregido |
| Subir/descargar archivo en servidor de juego | Path traversal fix |
| Buscar en admin panel | Limit de `q` a 100 chars |
| Ver perfil / cambiar contraseña | Validation max:255 |
| Panel admin → usuarios con filtro de status/role | Whitelist nuevo |
| Crear/actualizar plan de servicio | Migración de `$request->all()` |

---

## RESUMEN RÁPIDO

| Categoría | Archivos tocados | Estado |
|-----------|-----------------|--------|
| Seguridad crítica (suscripciones) | 1 | ✅ Aplicado |
| SSL condicional | 4 | ✅ Aplicado |
| IPs hardcodeadas | 2 | ✅ Aplicado |
| Path traversal | 2 | ✅ Aplicado |
| Mass assignment | 2 | ✅ Aplicado |
| Middlewares | 1 | ✅ Aplicado |
| Filtros/paginación admin | 10 | ✅ Aplicado |
| Validaciones client | 8 | ✅ Aplicado |
| Validaciones auth | 3 | ✅ Aplicado |
| ROKE Pet — URLs de imágenes | 1 | ✅ Aplicado |
| **`php artisan storage:link`** | — | ⚠️ **Pendiente (tú) — obligatorio para imágenes** |
| **Variables de entorno** | — | ⚠️ **Pendiente (tú)** |
| **`php artisan migrate`** | — | ⚠️ **Pendiente (tú)** |
| **Sanctum token expiration** | — | ⚠️ Recomendado |
