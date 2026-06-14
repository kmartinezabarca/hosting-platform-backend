<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recibo de pago — roke.pet</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
    .wrapper { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
    .header { background: #0d9488; padding: 28px 32px; }
    .header h1 { color: #fff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -.3px; }
    .header p  { color: rgba(255,255,255,.85); margin: 4px 0 0; font-size: 14px; }
    .body { padding: 28px 32px; color: #374151; }
    .amount { font-size: 30px; font-weight: 800; color: #0f766e; margin: 8px 0 20px; }
    .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
    .info-row:last-child { border-bottom: none; }
    .info-label { color: #6b7280; font-size: 13px; }
    .info-value { color: #111; font-size: 13px; font-weight: 600; }
    .cta { text-align: center; margin: 24px 0 6px; }
    .cta a { display: inline-block; background: #0d9488; color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 14px; font-weight: 600; }
    .footer { background: #f9fafb; padding: 16px 32px; text-align: center; }
    .footer p { margin: 0; color: #9ca3af; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>roke.pet</h1>
      <p>🧾 Recibo de pago</p>
    </div>
    <div class="body">
      <p style="margin-top:0">Hola{{ $name ? ' ' . $name : '' }}, ¡gracias por tu pago!</p>
      <div class="amount">${{ $amount }} {{ $currency }}</div>
      <div class="info-row">
        <span class="info-label">Concepto</span>
        <span class="info-value">Suscripción ROKE PET</span>
      </div>
      <div class="info-row">
        <span class="info-label">Recibo</span>
        <span class="info-value">{{ $invoiceNumber }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Fecha</span>
        <span class="info-value">{{ $dateLabel }}</span>
      </div>
      @if($invoiceUrl)
        <div class="cta">
          <a href="{{ $invoiceUrl }}">Ver / descargar recibo</a>
        </div>
      @endif
    </div>
    <div class="footer">
      <p>roke.pet &mdash; Identificación digital, salud y contacto de emergencia para mascotas.<br>
        Este es un comprobante de pago automático.</p>
    </div>
  </div>
</body>
</html>
