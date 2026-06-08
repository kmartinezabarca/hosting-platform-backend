# ROKE — Soporte WhatsApp con n8n

Automatiza el soporte por WhatsApp: el bot responde con IA usando tu base de
conocimiento y escala a un agente (crea ticket) cuando no puede resolver.

Archivos:
- `roke-whatsapp-support.workflow.json` — workflow importable en n8n.

---

## 1. Requisitos

- **n8n** (recomendado self-hosted, p. ej. en tu Coolify).
- **WhatsApp Business Cloud API** (Meta): app creada, número, Phone Number ID y token.
- **API key de un LLM** (OpenAI por defecto en el workflow; se puede cambiar a Claude).
- El backend ROKE con la integración n8n desplegada (esta rama).

## 2. Backend ROKE (.env de producción)

```env
N8N_ENABLED=true
N8N_WEBHOOK_SECRET=<genera uno largo y aleatorio>
N8N_WEBHOOK_URL=https://n8n.tu-dominio.com/webhook
```

Genera el secreto:
```bash
php artisan tinker --execute="echo \Illuminate\Support\Str::random(48);"
```

Aplica la migración:
```bash
php artisan migrate
```

Prueba que responde (debe dar 200):
```bash
curl -s https://TU-API/api/integrations/n8n/health \
  -H "Authorization: Bearer <N8N_WEBHOOK_SECRET>"
```

## 3. Variables de entorno en n8n

En n8n → **Settings → Variables** (o variables de entorno del contenedor):

| Variable | Valor |
|---|---|
| `ROKE_API_BASE` | `https://TU-API/api/integrations/n8n` |
| `ROKE_N8N_TOKEN` | el mismo valor de `N8N_WEBHOOK_SECRET` |
| `OPENAI_API_KEY` | tu API key de OpenAI |
| `WHATSAPP_PHONE_NUMBER_ID` | el Phone Number ID de Meta |

> Si tu n8n no permite `$env`, reemplaza esas expresiones por los valores
> directos en cada nodo (o usa un nodo *Set* "Config" al inicio).

## 4. Importar el workflow

1. n8n → **Workflows → Import from File** → `roke-whatsapp-support.workflow.json`.
2. Crea una **credencial de WhatsApp** (Meta) y asígnala a los 3 nodos de WhatsApp
   (`WhatsApp Trigger`, `Enviar (handoff)`, `Enviar (respuesta)`) — dicen `REEMPLAZA`.
3. **Activa** el workflow. n8n te dará la **URL del webhook** del trigger.
4. En **Meta** → WhatsApp → Configuration → Webhook, pega esa URL y suscribe el
   campo **messages**. Meta verifica la URL automáticamente.

## 5. Cómo funciona el flujo

```
WhatsApp Trigger (Meta)
  → Extract            (normaliza phone, message, id, name)
  → Inbound            (POST /whatsapp/inbound → registra y trae contexto)
  → ¿Responder bot?    (should_autorespond == true)
       ├─ true → Get Knowledge → LLM → Parse LLM → ¿Escalar?
       │           ├─ handoff → Do Handoff (/whatsapp/handoff) → Enviar (handoff)
       │           └─ reply   → Enviar (respuesta) → Log Reply (/whatsapp/reply)
       └─ false → (no hace nada: un humano ya tomó la conversación)
```

- **Idempotencia:** si Meta reintenta, `wa_message_id` evita duplicar el mensaje.
- **Handoff:** marca el hilo como `human` y, si el contacto es un cliente
  conocido (match por teléfono), crea un **ticket** en tu panel.
- **MVP:** sólo procesa mensajes de texto (ignora audio/imagen/estados).

## 6. System prompt recomendado (amplíalo en el nodo `LLM`)

> Eres el asistente de soporte de **ROKE Industries** (hosting web, servidores de
> juego y dominios). Responde en español, con tono cercano y profesional, claro y
> breve. Usa **únicamente** la base de conocimiento proporcionada; no inventes.
> **Escala a un humano** (action `handoff`) si: el cliente lo pide, hay enojo, o el
> tema es **cobro, reembolso, acceso a la cuenta, datos fiscales o una incidencia
> técnica que no está en la base de conocimiento**. Nunca compartas datos de otros
> clientes. Devuelve **siempre** JSON válido: `{"action":"reply"|"handoff","message":"..."}`.

## 7. Notas / siguientes pasos

- El JSON es un **scaffold probado en estructura**; según tu versión de n8n quizá
  debas reconfirmar 1–2 parámetros al importar (es normal en n8n).
- Para **mensajes proactivos** (recordatorios de pago / vencimientos por WhatsApp)
  ya existe `N8nDispatcher` en el backend: se engancha a los comandos de dunning y
  de vencimientos y dispara un webhook de n8n con una plantilla aprobada por Meta.
- Cambiar a **Claude**: en el nodo `LLM`, apunta a la API de Anthropic y ajusta el
  formato del body (messages / system).
