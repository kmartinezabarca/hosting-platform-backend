# QA — Pagos & Suscripciones (Fases 0–6)

> Checklist de validación en **dev/staging** del rework de pagos, suscripciones,
> webhooks, morosidad, cancelación, cambio de plan y provisioning.
> Marca cada caso `[x]` al validarlo. Usa **tarjetas de prueba de Stripe** (modo test).

---

## 0. Preparación del entorno

- [ ] `.env` con claves de **test** de Stripe: `STRIPE_KEY=pk_test_…`, `STRIPE_SECRET=sk_test_…`.
- [ ] `BILLING_GRACE_PERIOD_DAYS=5` (o el valor deseado), `BILLING_CURRENCY=MXN`, `BILLING_TAX_RATE_PERCENT=16`.
- [ ] Ejecutar migraciones:
  ```bash
  php artisan migrate
  ```
  Verifica que se crearon: `stripe_events`, `provisioning_jobs`, columnas de dunning en `subscriptions`,
  `cancel_at_period_end`, `services.grace_period_ends_at/suspended_at/suspension_reason/provisioning_status/provisioning_error`,
  y el **UNIQUE** en `services.payment_intent_id`.
- [ ] Sincronizar precios a Stripe: `php artisan stripe:sync-plans` y confirmar `stripe_price_id` en `plan_pricing`.
- [ ] Configurar webhook en Stripe → `https://api.rokeindustries.dev/api/stripe/webhook`, copiar `whsec_…` a `STRIPE_WEBHOOK_SECRET`.
- [ ] Habilitar eventos en el endpoint: `payment_intent.succeeded/payment_failed`, `invoice.paid/payment_succeeded/payment_failed/payment_action_required/finalized`, `customer.subscription.created/updated/deleted`, `checkout.session.completed`.
- [ ] (Opcional) Stripe CLI para reenviar eventos: `stripe listen --forward-to https://…/api/stripe/webhook`.
- [ ] Scheduler corriendo: `php artisan schedule:work` (o cron) para los comandos `process-overdue` y `process-pending`.

**Tarjetas de prueba útiles**
- Éxito: `4242 4242 4242 4242`
- Requiere 3DS: `4000 0027 6000 3184`
- Rechazo genérico: `4000 0000 0000 0002`
- Falla en renovación (para dunning): usar `4000 0000 0000 0341` como método guardado.

---

## 1. Cálculo de precio autoritativo (no confiar en frontend)

- [ ] `POST /api/checkout/quote` con `{plan_id, billing_cycle}` devuelve `subtotal`, `tax`, `total`, `quote_id`.
- [ ] El IVA = 16% del subtotal; el descuento por ciclo se aplica antes del IVA.
- [ ] Manipular el total en el navegador NO cambia lo cobrado (el backend recalcula con el `quote_id`).
- [ ] Add-on **no permitido** por el plan → la quote responde `ADD_ON_UNAVAILABLE` (422).
- [ ] Ciclo inactivo / plan inactivo → `BILLING_CYCLE_UNAVAILABLE` / `PLAN_UNAVAILABLE` (422).
- [ ] Cambiar el precio del plan en el admin **después** de cotizar → al contratar responde `QUOTE_CHANGED` (409).

## 2. Idempotencia / anti–doble cobro (Fase 0)

- [ ] **Doble click** en "Pagar" con quote → solo se crea **un** servicio, **un** cargo, **una** factura.
- [ ] Reusar un `quote_id` ya consumido → `QUOTE_ALREADY_USED` (409).
- [ ] Si el pago falla (tarjeta rechazada), la quote se **libera** y se puede reintentar con otra tarjeta.
- [ ] Landing: doble click en checkout → un solo cargo (idempotency-key de Stripe + claim de quote).
- [ ] Reenviar el **mismo evento** de webhook (Stripe CLI `resend`) → `stripe_events` lo marca duplicado y NO reprocesa (revisar log "duplicado ignorado").
- [ ] Intentar insertar dos `services` con el mismo `payment_intent_id` → bloqueado por UNIQUE.

## 3. Webhooks robustos (Fase 1)

- [ ] Cada evento recibido crea fila en `stripe_events` con `status=processed`.
- [ ] Un handler que falla deja `status=failed` + `error`, responde 500, y Stripe reintenta (el reintento se procesa OK).
- [ ] `invoice.paid` de una renovación → `subscriptions.current_period_end` se **actualiza** (verifica que NO queda null — prueba clave del fix API Basil).
- [ ] `customer.subscription.updated` refleja `status` y `cancel_at_period_end` correctos.
- [ ] Crear una suscripción nueva → `current_period_start/end` quedan poblados (no null).

## 4. Pago fallido + gracia + suspensión automática (Fase 2)

- [ ] Forzar fallo de renovación (Stripe CLI: `stripe trigger invoice.payment_failed`, o tarjeta `…0341`).
- [ ] La suscripción pasa a `past_due`, se setea `grace_period_ends_at = hoy + 5 días`, `payment_failed_at`, `last_payment_error`.
- [ ] El **servicio sigue activo** durante la gracia (NO se suspende de inmediato).
- [ ] El cliente recibe notificación con el mensaje de "5 días para actualizar el método de pago".
- [ ] `GET /api/billing/banners` devuelve banner `payment_failed` con `days_left`.
- [ ] Adelantar la fecha (en BD: poner `grace_period_ends_at` en el pasado) y correr:
  ```bash
  php artisan subscriptions:process-overdue
  ```
  → el servicio queda `suspended` con `suspension_reason=payment_overdue`; en Pterodactyl/Coolify el servidor queda suspendido; llega notificación "servicio suspendido".
- [ ] `--dry-run` lista sin aplicar cambios.
- [ ] Pagar la factura pendiente (Stripe genera `invoice.paid`) → servicio **reactivado** automáticamente, gracia limpiada, reactivado en el proveedor.
- [ ] Banner de suspensión desaparece tras el pago.

## 5. Cancelación a fin de periodo (Fase 3)

- [ ] `POST /api/subscriptions/{id}/cancel` (sin `immediate`) → `cancel_at_period_end=true`, servicio **sigue activo**, respuesta trae `ends_at` (fecha exacta).
- [ ] `POST /api/services/{uuid}/cancel` (con suscripción) → programa cancelación a fin de periodo, NO corta el servicio.
- [ ] El detalle del servicio (`GET /api/services/{uuid}`) expone `subscription.cancel_at_period_end` y `ends_at`.
- [ ] `POST /api/services/{uuid}/reactivate-cancellation` antes del fin → quita la marca, el servicio se renovará.
- [ ] `cancel` con `{ "immediate": true }` → cancela ya (caso admin/forzado).
- [ ] Al llegar el fin del periodo (Stripe `customer.subscription.deleted`) → servicio `cancelled`/`terminated`.
- [ ] **Mensual vs anual**: confirmar que `ends_at` corresponde al fin del periodo pagado en cada caso.

## 6. Cambio de plan con proration (Fase 4)

- [ ] **Upgrade** (`POST /api/services/{uuid}/upgrade` con `plan_uuid` de plan mayor) → Stripe factura la diferencia prorrateada de inmediato; `service.plan_id` y `price` actualizados; límites de Pterodactyl actualizados.
- [ ] **Downgrade** → cambio aplicado, crédito prorrateado a la próxima factura.
- [ ] **Cambio de ciclo** (enviar `billing_cycle: "annually"`) → el price de Stripe cambia al anual.
- [ ] Plan de **otra categoría** → 422 "no puedes cambiar a un plan de otra categoría".
- [ ] **Rollback**: simular fallo de BD tras el cambio (o revisar logs) → el price en Stripe se revierte y responde "no se realizaron cargos".
- [ ] Servicio **sin suscripción** (free/one-off) → cambio local + límites del proveedor, sin tocar Stripe.
- [ ] Tarjeta que requiere 3DS en el upgrade → responde 402 (no deja la suscripción rota).

## 7. Provisioning transaccional + reintentos (Fase 5)

- [ ] Contratar un game server (Pterodactyl) → se crea `provisioning_jobs` (provider=pterodactyl) y el servidor se aprovisiona; `services.provisioning_status=succeeded`.
- [ ] Simular fallo del proveedor (apagar API / credenciales malas) al contratar → el servicio NO queda a medias: `provisioning_status=pending`, job con `attempts=1`, `available_at` futuro (backoff), `last_error` poblado.
- [ ] Correr `php artisan provisioning:process-pending` tras restaurar el proveedor → el job pasa a `succeeded` y el servicio se aprovisiona (sin duplicar servidor).
- [ ] Reintentar un job ya exitoso → NO crea un segundo servidor (guarda por `pterodactyl_server_id` / `coolify_app_uuid`).
- [ ] Agotar `max_attempts` (5) → job `failed`, `services.provisioning_status=failed`, banner `provisioning_failed` en `/billing/banners`.
- [ ] `UNIQUE(service_id, provider)` impide dos jobs para el mismo servicio+proveedor.

## 8. Datos del cliente — teléfono (Fase 6)

- [ ] Contratar enviando `phone` (o `phone_number`) y el usuario SIN teléfono → se guarda en `users.phone`.
- [ ] Usuario que YA tiene teléfono → no se sobrescribe en el checkout.
- [ ] `GET /api/profile` devuelve `phone`; el frontend lo reutiliza (no lo vuelve a pedir).
- [ ] `PUT /api/profile` actualiza el teléfono.

## 9. Landing unificado al quote (Fase 6)

- [ ] El modal de checkout del landing muestra **el total que devuelve el backend** (no el calculado en cliente).
- [ ] La petición de contratación del landing incluye `quote_id`.
- [ ] Cambiar de ciclo en el landing recalcula la quote (nuevo total del backend).
- [ ] Error al obtener la quote → botón "Pagar" deshabilitado + aviso "No se pudo calcular el precio".
- [ ] Doble click en el landing → un solo cargo (protegido por claim de quote).

## 10. Seguridad / autorización

- [ ] Usuario A no puede cancelar/cambiar/contratar sobre servicios o suscripciones de usuario B (404/403).
- [ ] El endpoint de webhook rechaza firmas inválidas (400) — probar con payload sin `Stripe-Signature`.
- [ ] El frontend NUNCA envía `amount`; intentar inyectarlo no afecta el cobro.
- [ ] Rate limiting activo donde aplique (búsqueda, verificación de email).
- [ ] Secrets (`STRIPE_SECRET`, `whsec_…`) no aparecen en logs ni respuestas.

---

## Comandos de referencia

```bash
# Migraciones y catálogo
php artisan migrate
php artisan stripe:sync-plans

# Morosidad (gracia → suspensión / reactivación)
php artisan subscriptions:process-overdue --dry-run
php artisan subscriptions:process-overdue

# Provisioning (reintentos con backoff)
php artisan provisioning:process-pending --dry-run
php artisan provisioning:process-pending

# Scheduler (corre todos los comandos programados)
php artisan schedule:work

# Stripe CLI — reenviar/disparar eventos
stripe listen --forward-to https://api.rokeindustries.dev/api/stripe/webhook
stripe trigger invoice.payment_failed
stripe trigger invoice.paid
```

## Consultas SQL de verificación rápida

```sql
-- Eventos de webhook procesados / fallidos
SELECT type, status, attempts, error FROM stripe_events ORDER BY id DESC LIMIT 20;

-- Suscripciones en gracia / canceladas a fin de periodo
SELECT id, status, cancel_at_period_end, grace_period_ends_at, suspended_at, ends_at
FROM subscriptions ORDER BY id DESC LIMIT 20;

-- Jobs de aprovisionamiento
SELECT service_id, provider, status, attempts, max_attempts, available_at, last_error
FROM provisioning_jobs ORDER BY id DESC LIMIT 20;

-- Servicios y su estado de provisioning / suspensión
SELECT id, status, provisioning_status, suspension_reason, grace_period_ends_at, payment_intent_id
FROM services ORDER BY id DESC LIMIT 20;
```
