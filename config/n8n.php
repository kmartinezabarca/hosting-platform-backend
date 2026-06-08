<?php

return [
    // Secreto compartido que n8n debe enviar en cada request entrante a la
    // capa de integración (header Authorization: Bearer <secret>).
    'secret' => env('N8N_WEBHOOK_SECRET'),

    // URL base de webhooks de n8n que el backend invoca para disparar flujos
    // salientes (p. ej. enviar una plantilla de WhatsApp de recordatorio).
    // Ej: https://n8n.tu-dominio.com/webhook
    'webhook_url' => env('N8N_WEBHOOK_URL'),

    // Timeout (segundos) para las llamadas salientes hacia n8n.
    'timeout' => (int) env('N8N_TIMEOUT', 15),

    // Activa/desactiva el envío saliente hacia n8n sin tocar código.
    'enabled' => (bool) env('N8N_ENABLED', false),
];
