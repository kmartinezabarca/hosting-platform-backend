<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Restablece tu contraseña — roke.pet</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f3f0; margin: 0; padding: 0; }
    .wrapper { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,.08); }
    .header { background: linear-gradient(135deg, #E87256 0%, #C4523D 100%); padding: 34px 32px 30px; text-align: center; }
    .header .mark { font-size: 30px; line-height: 1; margin-bottom: 8px; }
    .header h1 { color: #fff; margin: 0; font-size: 22px; font-weight: 800; letter-spacing: .3px; }
    .header p  { color: rgba(255,255,255,.9); margin: 6px 0 0; font-size: 14px; }
    .body { padding: 30px 32px 8px; }
    .body p { color: #3D3631; font-size: 15px; line-height: 1.55; margin: 0 0 16px; }
    .cta { text-align: center; margin: 26px 0 22px; }
    .cta a { display: inline-block; background: linear-gradient(135deg, #E87256 0%, #C4523D 100%); color: #fff !important; text-decoration: none; padding: 14px 34px; border-radius: 14px; font-size: 15px; font-weight: 700; box-shadow: 0 6px 16px rgba(232,114,86,.34); }
    .note { background: #FFF0EA; border: 1px solid #F5C4B4; border-radius: 10px; padding: 12px 16px; margin: 18px 0 4px; }
    .note p { margin: 0; color: #C4523D; font-size: 13px; }
    .fallback { color: #6B6258; font-size: 12px; line-height: 1.5; word-break: break-all; }
    .fallback a { color: #C4523D; }
    .footer { background: #FAF5EE; padding: 18px 32px; text-align: center; }
    .footer p { margin: 0; color: #A09890; font-size: 12px; line-height: 1.5; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <div class="mark">🐾</div>
      <h1>roke.pet</h1>
      <p>Restablecer contraseña</p>
    </div>

    <div class="body">
      <p>Hola <strong>{{ $ownerName }}</strong>,</p>
      <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta en <strong>roke.pet</strong>. Toca el botón para crear una nueva contraseña:</p>

      <div class="cta">
        <a href="{{ $resetUrl }}">Restablecer mi contraseña</a>
      </div>

      <div class="note">
        <p>⏱️ Este enlace expira en {{ $expiresMinutes }} minutos por seguridad.</p>
      </div>

      <p style="margin-top:18px">Si tú no solicitaste este cambio, puedes ignorar este correo: tu contraseña seguirá igual.</p>

      <p class="fallback">
        ¿El botón no funciona? Copia y pega este enlace en tu navegador:<br>
        <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
      </p>
    </div>

    <div class="footer">
      <p>roke.pet &mdash; El perfil vivo de tu mascota<br>
        @if($ipAddress) Solicitud recibida desde la IP {{ $ipAddress }}.<br>@endif
        Un producto de ROKE Industries.</p>
    </div>
  </div>
</body>
</html>
