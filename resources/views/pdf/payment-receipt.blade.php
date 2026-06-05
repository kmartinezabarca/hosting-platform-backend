<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Comprobante de Pago {{ $invoice->invoice_number }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a2e; background: #ffffff; }

  .page { padding: 36px 40px; }

  /* ── Header ── */
  .header { display: table; width: 100%; margin-bottom: 28px; }
  .header-left  { display: table-cell; width: 60%; vertical-align: middle; }
  .header-right { display: table-cell; width: 40%; vertical-align: middle; text-align: right; }

  .company-name { font-size: 20px; font-weight: bold; color: #1e3a5f; letter-spacing: 0.5px; }
  .company-sub  { font-size: 10px; color: #6b7280; margin-top: 2px; }

  .badge {
    display: inline-block;
    background: #1e3a5f;
    color: #ffffff;
    font-size: 13px;
    font-weight: bold;
    padding: 6px 16px;
    border-radius: 4px;
    letter-spacing: 0.5px;
  }
  .receipt-number { font-size: 10px; color: #6b7280; margin-top: 4px; }

  /* ── Divider ── */
  .divider { border: none; border-top: 2px solid #1e3a5f; margin: 0 0 24px; }
  .divider-light { border: none; border-top: 1px solid #e5e7eb; margin: 16px 0; }

  /* ── Two-column info ── */
  .info-grid { display: table; width: 100%; margin-bottom: 24px; }
  .info-col   { display: table-cell; width: 50%; vertical-align: top; }
  .info-col + .info-col { padding-left: 24px; }

  .section-title { font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px; }
  .info-row { margin-bottom: 4px; }
  .info-label { color: #6b7280; display: inline-block; width: 90px; }
  .info-value { font-weight: bold; color: #111827; }

  /* ── Items table ── */
  table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  table.items thead tr { background: #1e3a5f; color: #ffffff; }
  table.items thead th { padding: 8px 10px; text-align: left; font-size: 10px; font-weight: bold; }
  table.items thead th.right { text-align: right; }
  table.items tbody tr:nth-child(even) { background: #f8fafc; }
  table.items tbody td { padding: 7px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  table.items tbody td.right { text-align: right; }
  table.items tbody td.mono { font-family: DejaVu Sans Mono, monospace; font-size: 10px; }

  /* ── Totals ── */
  .totals { display: table; width: 100%; margin-bottom: 24px; }
  .totals-spacer { display: table-cell; width: 60%; }
  .totals-block  { display: table-cell; width: 40%; vertical-align: top; }
  .totals-row { display: table; width: 100%; margin-bottom: 4px; }
  .totals-label { display: table-cell; color: #6b7280; }
  .totals-value { display: table-cell; text-align: right; font-family: DejaVu Sans Mono, monospace; font-size: 11px; }
  .totals-total .totals-label { font-weight: bold; color: #111827; font-size: 12px; }
  .totals-total .totals-value { font-weight: bold; color: #1e3a5f; font-size: 13px; }

  /* ── Payment info ── */
  .payment-box { background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 14px 16px; margin-bottom: 20px; }
  .payment-title { font-weight: bold; color: #1e3a5f; margin-bottom: 8px; font-size: 11px; }
  .payment-row { display: table; width: 100%; margin-bottom: 4px; }
  .payment-key { display: table-cell; width: 140px; color: #6b7280; }
  .payment-val { display: table-cell; font-weight: bold; color: #111827; }

  /* ── 72-hour notice ── */
  .notice-box {
    background: #fffbeb;
    border: 1px solid #fbbf24;
    border-left: 4px solid #f59e0b;
    border-radius: 4px;
    padding: 12px 14px;
    margin-bottom: 20px;
  }
  .notice-title { font-weight: bold; color: #92400e; font-size: 11px; margin-bottom: 4px; }
  .notice-body  { color: #78350f; font-size: 10px; line-height: 1.5; }

  /* ── Status stamp ── */
  .stamp { text-align: center; margin: 10px 0 20px; }
  .stamp-inner {
    display: inline-block;
    border: 3px solid #16a34a;
    border-radius: 6px;
    padding: 6px 20px;
    color: #15803d;
    font-weight: bold;
    font-size: 15px;
    letter-spacing: 2px;
    opacity: 0.85;
  }

  /* ── Footer ── */
  .footer { border-top: 1px solid #e5e7eb; padding-top: 12px; color: #9ca3af; font-size: 9px; text-align: center; line-height: 1.6; }
  .footer a { color: #9ca3af; text-decoration: none; }
</style>
</head>
<body>
<div class="page">

  {{-- ── HEADER ── --}}
  <div class="header">
    <div class="header-left">
      <div class="company-name">{{ $company['name'] }}</div>
      @if($company['address'])
      <div class="company-sub">{{ $company['address'] }}</div>
      @endif
      @if($company['rfc'])
      <div class="company-sub">RFC: {{ $company['rfc'] }}</div>
      @endif
    </div>
    <div class="header-right">
      <div class="badge">COMPROBANTE DE PAGO</div>
      <div class="receipt-number">{{ $invoice->invoice_number }}</div>
    </div>
  </div>
  <hr class="divider">

  {{-- ── PAGADO STAMP ── --}}
  <div class="stamp">
    <div class="stamp-inner">&#10003; PAGADO</div>
  </div>

  {{-- ── INFO COLS ── --}}
  <div class="info-grid">
    <div class="info-col">
      <div class="section-title">Datos del Cliente</div>
      <div class="info-row"><span class="info-label">Nombre:</span> <span class="info-value">{{ $invoice->user->name ?? '—' }}</span></div>
      <div class="info-row"><span class="info-label">Email:</span> <span class="info-value">{{ $invoice->user->email ?? '—' }}</span></div>
      @if($invoice->service)
      <div class="info-row"><span class="info-label">Servicio:</span> <span class="info-value">{{ $invoice->service->name }}</span></div>
      @endif
    </div>
    <div class="info-col">
      <div class="section-title">Datos del Comprobante</div>
      <div class="info-row"><span class="info-label">Folio:</span> <span class="info-value">{{ $invoice->invoice_number }}</span></div>
      <div class="info-row"><span class="info-label">Fecha pago:</span> <span class="info-value">{{ $invoice->paid_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</span></div>
      <div class="info-row"><span class="info-label">Moneda:</span> <span class="info-value">{{ strtoupper($invoice->currency ?? 'MXN') }}</span></div>
      <div class="info-row"><span class="info-label">Método:</span> <span class="info-value">{{ ucfirst($invoice->payment_method ?? 'Tarjeta') }}</span></div>
    </div>
  </div>

  <hr class="divider-light">

  {{-- ── ITEMS ── --}}
  <table class="items">
    <thead>
      <tr>
        <th style="width:60%">Concepto</th>
        <th class="right" style="width:15%">Cantidad</th>
        <th class="right" style="width:25%">Importe</th>
      </tr>
    </thead>
    <tbody>
      @foreach($invoice->items as $item)
      <tr>
        <td>{{ $item->description }}</td>
        <td class="right mono">{{ $item->quantity }}</td>
        <td class="right mono">${{ number_format($item->quantity * $item->unit_price, 2) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  {{-- ── TOTALS ── --}}
  <div class="totals">
    <div class="totals-spacer"></div>
    <div class="totals-block">
      <div class="totals-row">
        <span class="totals-label">Subtotal</span>
        <span class="totals-value">${{ number_format($invoice->subtotal, 2) }}</span>
      </div>
      <div class="totals-row">
        <span class="totals-label">IVA ({{ number_format($invoice->tax_rate, 0) }}%)</span>
        <span class="totals-value">${{ number_format($invoice->tax_amount, 2) }}</span>
      </div>
      <hr class="divider-light">
      <div class="totals-row totals-total">
        <span class="totals-label">Total pagado</span>
        <span class="totals-value">${{ number_format($invoice->total, 2) }} {{ strtoupper($invoice->currency ?? 'MXN') }}</span>
      </div>
    </div>
  </div>

  {{-- ── PAYMENT METHOD ── --}}
  <div class="payment-box">
    <div class="payment-title">Detalles del pago</div>
    <div class="payment-row">
      <span class="payment-key">Referencia:</span>
      <span class="payment-val">{{ $invoice->payment_reference ?? '—' }}</span>
    </div>
    <div class="payment-row">
      <span class="payment-key">Procesado por:</span>
      <span class="payment-val">{{ ucfirst($invoice->gateway ?? 'stripe') }}</span>
    </div>
    <div class="payment-row">
      <span class="payment-key">Estado:</span>
      <span class="payment-val" style="color:#16a34a;">Pago exitoso</span>
    </div>
  </div>

  {{-- ── 72-HOUR NOTICE ── --}}
  <div class="notice-box">
    <div class="notice-title">&#9888; Aviso sobre tu Factura (CFDI)</div>
    <div class="notice-body">
      Este documento es un <strong>comprobante de pago interno</strong> y no tiene validez fiscal.<br>
      Si deseas una <strong>Factura Electrónica (CFDI 4.0)</strong> a nombre de tu empresa, tienes hasta el
      <strong>{{ $deadline->format('d/m/Y \a \l\a\s H:i') }} hrs</strong> para ingresar tus datos fiscales
      desde tu panel de cliente. Pasado ese plazo, la factura se emitirá automáticamente a nombre de
      <strong>Público en General</strong> conforme a la regulación del SAT.
    </div>
  </div>

  {{-- ── FOOTER ── --}}
  <div class="footer">
    {{ $company['name'] }}
    @if($company['email']) &nbsp;·&nbsp; {{ $company['email'] }} @endif
    @if($company['phone']) &nbsp;·&nbsp; {{ $company['phone'] }} @endif
    @if($company['website']) &nbsp;·&nbsp; <a href="{{ $company['website'] }}">{{ $company['website'] }}</a> @endif
    <br>
    Este comprobante fue generado automáticamente el {{ now()->format('d/m/Y H:i') }} hrs.
    Folio: {{ $invoice->invoice_number }}
  </div>

</div>
</body>
</html>
