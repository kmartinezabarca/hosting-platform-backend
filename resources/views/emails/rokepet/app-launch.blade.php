<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>¡La app ya está disponible! — roke.pet</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
    .wrapper { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
    .header { background: #0d9488; padding: 28px 32px; }
    .header h1 { color: #fff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -.3px; }
    .header p  { color: rgba(255,255,255,.85); margin: 4px 0 0; font-size: 14px; }
    .body { padding: 28px 32px; color: #374151; }
    .body p { line-height: 1.6; }
    .cta { text-align: center; margin: 26px 0 6px; }
    .cta a { display: inline-block; background: #0d9488; color: #fff; text-decoration: none; padding: 13px 30px; border-radius: 10px; font-size: 15px; font-weight: 700; }
    .footer { background: #f9fafb; padding: 16px 32px; text-align: center; }
    .footer p { margin: 0; color: #9ca3af; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>roke.pet</h1>
      <p>🎉 ¡Llegó el día!</p>
    </div>
    <div class="body">
      <p style="margin-top:0">Hola{{ $name ? ' ' . $name : '' }},</p>
      <p>
        Te uniste a nuestra lista de espera y queríamos que fueras de los primeros en saberlo:
        <strong>la app de ROKE PET ya está disponible</strong> 🐾
      </p>
      <p>
        Crea el perfil de tu mascota, activa el modo extraviado y recibe alertas al instante —
        todo desde tu móvil.
      </p>
      <div class="cta">
        <a href="{{ config('services.rokepet.frontend_url') }}">Descargar la app</a>
      </div>
    </div>
    <div class="footer">
      <p>roke.pet &mdash; Identificación digital, salud y contacto de emergencia para mascotas.<br>
        Recibiste este correo porque pediste que te avisáramos del lanzamiento.</p>
    </div>
  </div>
</body>
</html>
