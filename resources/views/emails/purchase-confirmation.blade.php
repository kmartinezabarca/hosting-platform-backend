@extends('emails.layout')

@section('title', 'Confirmación de compra — Roke Industries')
@section('header_subtitle', '¡Tu compra fue procesada exitosamente!')

@section('content')

<h2>¡Gracias por tu compra, {{ $user->first_name ?? $user->name }}!</h2>

<p>Tu servicio ha sido activado. Aquí están los detalles de tu pedido:</p>

<div class="info-box">
    <h3>Resumen del pedido</h3>
    <p><strong>Folio:</strong> {{ $invoice->invoice_number }}</p>
    <p><strong>Fecha:</strong> {{ $invoice->paid_at?->format('d/m/Y H:i') }}</p>
    <p><strong>Estado:</strong> <span style="color:#38a169;font-weight:600;">Pagado</span></p>
</div>

<h3>Detalle</h3>
<div style="background:#f7fafc;padding:20px;border-radius:8px;margin:20px 0;">

    <div style="border-bottom:1px solid #e2e8f0;padding:10px 0;display:flex;justify-content:space-between;">
        <div>
            <strong>{{ $service->name }}</strong><br>
            <span style="color:#718096;font-size:14px;">
                {{ $plan->name }} · {{ ucfirst($service->billing_cycle) }}
            </span>
        </div>
        <div style="text-align:right;font-weight:600;">
            ${{ number_format($invoice->subtotal, 2) }} {{ $invoice->currency }}
        </div>
    </div>

    <div style="padding:8px 0;display:flex;justify-content:space-between;color:#718096;font-size:14px;">
        <span>IVA (16%)</span>
        <span>${{ number_format($invoice->tax_amount, 2) }} {{ $invoice->currency }}</span>
    </div>

    <div style="border-top:2px solid #667eea;padding:12px 0;display:flex;justify-content:space-between;">
        <strong style="font-size:16px;">Total pagado</strong>
        <strong style="font-size:16px;">${{ number_format($invoice->total, 2) }} {{ $invoice->currency }}</strong>
    </div>

</div>

@if(!empty($conn))
<div class="info-box">
    <h3>Datos de conexión</h3>
    <p>
        <strong>Dirección:</strong>
        <code>{{ $conn['display'] ?? ($conn['server_ip'].':'.$conn['server_port']) }}</code>
    </p>
    @if(!empty($conn['panel_url']))
    <p>
        <strong>Panel de control:</strong>
        <a href="{{ $conn['panel_url'] }}" style="color:#667eea;">Abrir panel</a>
    </p>
    @endif
</div>
@endif

<div style="text-align:center;margin:30px 0;">
    <a href="{{ config('app.url') }}/client/services/{{ $service->uuid }}" class="button">
        Ver mi servicio
    </a>
</div>

<div class="divider"></div>

<p>Si tienes dudas, escríbenos a
    <a href="mailto:soporte@rokeindustries.com" style="color:#667eea;">
        soporte@rokeindustries.com
    </a>
</p>

<p>Saludos,<br><strong>El equipo de Roke Industries</strong></p>

@endsection
