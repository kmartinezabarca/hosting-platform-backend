<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recordatorio — roke.pet</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
    .wrapper { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
    .header { background: #6366f1; padding: 28px 32px; }
    .header h1 { color: #fff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -.3px; }
    .header p  { color: rgba(255,255,255,.8); margin: 4px 0 0; font-size: 14px; }
    .body { padding: 28px 32px; }
    .alert-box { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 14px 16px; margin-bottom: 20px; }
    .alert-box p { margin: 0; color: #92400e; font-size: 14px; font-weight: 500; }
    .type-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; margin-bottom: 16px;
      background: #ede9fe; color: #6d28d9; }
    .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
    .info-row:last-child { border-bottom: none; }
    .info-label { color: #6b7280; font-size: 13px; }
    .info-value { color: #111; font-size: 13px; font-weight: 600; }
    .cta { text-align: center; margin-top: 24px; }
    .cta a { display: inline-block; background: #6366f1; color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 14px; font-weight: 600; }
    .footer { background: #f9fafb; padding: 16px 32px; text-align: center; }
    .footer p { margin: 0; color: #9ca3af; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>roke.pet</h1>
      <p>{{ $typeEmoji }} {{ $typeLabel }}</p>
    </div>
    <div class="body">
      <p style="color:#374151;margin-top:0">Hola <strong>{{ $ownerName }}</strong>,</p>

      <span class="type-badge">{{ $typeEmoji }} {{ $typeLabel }}</span>

      @if($daysUntilDue === 0)
        <div class="alert-box">
          <p>⚠️ <strong>{{ $petName }}</strong> tiene un evento médico programado para <strong>hoy</strong>.</p>
        </div>
      @else
        <div class="alert-box">
          <p>📅 <strong>{{ $petName }}</strong> tiene un evento médico en <strong>{{ $daysUntilDue }} día(s)</strong>.</p>
        </div>
      @endif

      <div class="info-row">
        <span class="info-label">Mascota</span>
        <span class="info-value">{{ $petName }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Evento</span>
        <span class="info-value">{{ $eventName }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Fecha</span>
        <span class="info-value">{{ $dueDate }}</span>
      </div>

      <div class="cta">
        <a href="{{ config('services.rokepet.frontend_url') }}/dashboard">Ver panel</a>
      </div>
    </div>
    <div class="footer">
      <p>roke.pet &mdash; Gestión digital de mascotas<br>
        Puedes desactivar estos recordatorios desde la configuración de tu perfil.</p>
    </div>
  </div>
</body>
</html>
