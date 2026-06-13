# Pendientes de configuración / ops (lado del operador)

> Tareas que **NO son código** — configuración, variables de entorno, DNS, despliegue
> e infraestructura — que dependen de ti para que lo ya implementado funcione en vivo.
> Convención de entornos: **`dev`** (app/api .dev) y **`api-prod`** (.com). Nunca "staging".

Estado del código: todo lo de abajo ya está implementado y pusheado a `develop`.
Lo que falta es **encenderlo** con la config correcta.

---

## 1. GitHub App → deploy automático

- [ ] **Desplegar el frontend** a `app.rokeindustries.dev` con el build que incluye los commits
      `f60bfa14` (fix Setup URL) y `9545cd1e` (robustez del claim). Con eso desaparece el 404.
      Verificar después: `curl -s https://app.rokeindustries.dev/version.json` → `commit` reciente.
- [ ] **GitHub App settings** (github.com → Settings → Developer settings → tu App):
  - Setup URL = `https://app.rokeindustries.dev/github/setup`  ✅ (ya coincide)
  - Webhook URL = `https://api.rokeindustries.dev/api/webhooks/github`  ✅ (ya coincide)
  - (Opcional recomendado) activar **"Redirect on update"** para que al cambiar repos se re-sincronice.
- [ ] **Env en el backend** (`api.rokeindustries.dev`): `GITHUB_APP_ID`, `GITHUB_APP_SLUG`,
      `GITHUB_APP_PRIVATE_KEY_BASE64`, `GITHUB_WEBHOOK_SECRET`.
- [ ] **Revisar discrepancia nginx ↔ deploy** en el Mac Mini: el conf del repo sirve
      `app.rokeindustries.dev` desde `/opt/apps/portal-staging`, pero el pipeline despliega a
      `/opt/apps/portal-dev`. Confirmar que apuntan al mismo sitio (o symlink), si no "deployas pero no ves cambios".

## 2. Coolify (plano de cómputo: apps + bases de datos)

- [ ] **Env por entorno**: `COOLIFY_URL`, `COOLIFY_API_TOKEN`, `COOLIFY_SERVER_UUID`.
- [ ] **DNS wildcard** `*.apps.rokeindustries.dev` (Cloudflare) apuntando al edge/Traefik de Coolify,
      para los subdominios automáticos de las apps.
- [ ] **Verificar el driver de bases de datos contra un Coolify real (en `dev`).**
      `CoolifyDatabaseDriver` está implementado pero **no se ha probado contra Coolify vivo**: el
      shape de la respuesta de conexión es best-effort. Crear una DB de prueba y confirmar que
      host/usuario/contraseña/puerto se leen bien (si el shape difiere, el código falla ruidoso con
      las claves recibidas en el log — ahí se ve qué ajustar).

## 3. SiteBuilder (generador de páginas con IA)

- [ ] **Ollama (dev)**: en `.env.dev` → `PAGE_GENERATOR_DRIVER=ollama`,
      `OLLAMA_BASE_URL=http://<IP-Tailscale-del-Ryzen>:11434`, `OLLAMA_MODEL=<modelo cargado>`.
      (Ollama corre en **roke-ryzen-01**, no en el Mac Mini.)
- [ ] **Claude (de pago, prod)**: `PAGE_GENERATOR_DRIVER=claude` + `ANTHROPIC_API_KEY` ya configurada
      (vive en `config/anthropic.php`); opcional `PAGE_GEN_CLAUDE_MODEL`.
- [ ] **Dominio público de páginas**: apuntar **`rokeindustries.app`** (dominio **separado y SIN cookies**
      — nunca el del api/app) al backend, y setear `SITE_BUILDER_PUBLIC_BASE=https://rokeindustries.app`.
      Así las páginas publicadas quedan en `https://rokeindustries.app/p/{uuid}`.
- [ ] (Futuro / Fase 3) Para "publicar en el hosting propio del cliente": guardar el **web root público**
      por servicio al aprovisionar (hoy no se almacena). Sin eso, esa fase queda pospuesta.

## 4. Notas para quien corra los tests (dev)

- `phpunit.xml` fija `DB_HOST=__MYSQL_IP__` (placeholder de CI). En local correr con la env apuntando
  al MySQL de pruebas: `DB_HOST=100.72.162.112` (usuario `laravel`/`secret`, base `hosting_platform_test`).
- Hay una falla **preexistente y ajena** en `tests/Feature/PetSupportChatTest.php` (dominio Pet) — no es
  de la plataforma de cómputo.

---

_Documento de referencia operativa. El código de todo lo anterior ya está en `develop`._
