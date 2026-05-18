@extends('emails.layout')

@section('title', 'Nueva factura generada - Roke Industries')

@section('header_subtitle', 'Tu factura está lista para descargar')

@section('content')
    @php
        $customerName = trim($user->full_name ?? '')
            ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
            ?: $user->email;
        $currency = strtoupper($invoice->currency ?? 'MXN');
        $invoiceNumber = $invoice->invoice_number ?? $invoice->number ?? $invoice->folio ?? 'No disponible';
        $statusLabels = [
            'draft' => ['label' => 'Borrador', 'color' => '#718096'],
            'sent' => ['label' => 'Enviada', 'color' => '#3182ce'],
            'processing' => ['label' => 'Procesando', 'color' => '#d69e2e'],
            'paid' => ['label' => 'Pagada', 'color' => '#38a169'],
            'overdue' => ['label' => 'Vencida', 'color' => '#e53e3e'],
            'cancelled' => ['label' => 'Cancelada', 'color' => '#718096'],
            'refunded' => ['label' => 'Reembolsada', 'color' => '#d69e2e'],
        ];
        $status = $invoice->status ?? 'sent';
        $statusMeta = $statusLabels[$status] ?? ['label' => ucfirst((string) $status), 'color' => '#718096'];
        $panelUrl = rtrim(config('app.frontend_url', config('app.url')), '/') . '/client/invoices';
    @endphp

    <h2>Hola {{ $customerName }},</h2>
    
    <p>Se ha generado una nueva factura para tu cuenta. Puedes revisarla y descargarla cuando gustes.</p>
    
    <div class="info-box">
        <h3>Detalles de la factura</h3>
        <p><strong>Número:</strong> {{ $invoiceNumber }}</p>
        <p><strong>Fecha de emisión:</strong> {{ ($invoice->created_at ?? now())->format('d/m/Y') }}</p>
        <p><strong>Fecha de vencimiento:</strong> {{ ($invoice->due_date ?? now()->addDays(30))->format('d/m/Y') }}</p>
        <p><strong>Monto total:</strong> ${{ number_format($invoice->total ?? 0, 2) }} {{ $currency }}</p>
        <p><strong>Estado:</strong> 
            <span style="color: {{ $statusMeta['color'] }}; font-weight: 600;">{{ $statusMeta['label'] }}</span>
        </p>
    </div>
    
        <h3>Resumen de servicios facturados</h3>
    <div style="background-color: #f7fafc; padding: 20px; border-radius: 8px; margin: 20px 0;">
        @if(isset($invoiceItems) && is_array($invoiceItems))
            @foreach($invoiceItems as $item)
            <div style="border-bottom: 1px solid #e2e8f0; padding: 15px 0; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="color: #2d3748; margin-bottom: 5px;">{{ data_get($item, 'description', 'Servicio') }}</h4>
                    <p style="color: #718096; font-size: 14px; margin: 0;">
                        Período: {{ data_get($item, 'period', 'No especificado') }}
                    </p>
                    @if(data_get($item, 'quantity') && data_get($item, 'quantity') > 1)
                    <p style="color: #718096; font-size: 14px; margin: 0;">Cantidad: {{ data_get($item, 'quantity') }}</p>
                    @endif
                </div>
                <div style="text-align: right;">
                    <p style="font-weight: 600; color: #2d3748; margin: 0;">${{ number_format(data_get($item, 'amount', data_get($item, 'total', 0)), 2) }} {{ $currency }}</p>
                </div>
            </div>
            @endforeach
        @else
            <div style="padding: 15px 0;">
                <h4 style="color: #2d3748; margin-bottom: 5px;">Servicios de hosting</h4>
                <p style="color: #718096; font-size: 14px; margin: 0;">
                    Período: {{ ($invoice->period_start ?? now()->startOfMonth())->format('d/m/Y') }} - {{ ($invoice->period_end ?? now()->endOfMonth())->format('d/m/Y') }}
                </p>
            </div>
        @endif
        
        @if(isset($invoice->subtotal) || isset($invoice->tax) || isset($invoice->total))
        <div style="border-top: 1px solid #e2e8f0; padding: 15px 0; margin-top: 15px;">
            @if(isset($invoice->subtotal))
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Subtotal:</span>
                <span>${{ number_format($invoice->subtotal, 2) }} {{ $currency }}</span>
            </div>
            @endif
            @if(($invoice->tax_amount ?? $invoice->tax ?? 0) > 0)
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Impuestos:</span>
                <span>${{ number_format($invoice->tax_amount ?? $invoice->tax, 2) }} {{ $currency }}</span>
            </div>
            @endif
            <div style="display: flex; justify-content: space-between; border-top: 2px solid #667eea; padding-top: 10px; font-weight: 600; font-size: 18px;">
                <span>Total:</span>
                <span>${{ number_format($invoice->total ?? 0, 2) }} {{ $currency }}</span>
            </div>
        </div>
        @endif
    </div>
    
    <div style="text-align: center;">
        <a href="{{ $downloadUrl ?? $panelUrl }}" class="button">Ver factura</a>
    </div>
    
    @if(in_array($status, ['sent', 'processing', 'overdue'], true))
    <div class="info-box" style="border-left-color: #d69e2e;">
        <h3>Pago pendiente</h3>
        <p>Esta factura aún está pendiente de pago. El pago se procesará automáticamente si tienes configurado un método de pago automático.</p>
        <p>Si necesitas actualizar tu método de pago, puedes hacerlo desde tu panel de control.</p>
    </div>
    @endif
    
    <div class="divider"></div>
    
    <h3>Información adicional</h3>
    <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
        <li style="margin-bottom: 8px;">Puedes descargar todas tus facturas desde el panel de control</li>
        <li style="margin-bottom: 8px;">Las facturas se conservan por tiempo indefinido</li>
        <li style="margin-bottom: 8px;">Recibirás una copia por cada factura generada</li>
        <li style="margin-bottom: 8px;">Para cambios en la información de facturación, contacta a soporte</li>
    </ul>
    
    <p>Si necesitas una copia adicional de esta factura o tienes alguna pregunta sobre los cargos, no dudes en contactarnos en <a href="mailto:soporte@rokeindustries.com" style="color: #667eea;">soporte@rokeindustries.com</a></p>
    
    <p>¡Gracias por ser parte de Roke Industries!</p>
    
    <p style="margin-top: 30px;">
        Saludos cordiales,<br>
        <strong>El equipo de facturación de Roke Industries</strong>
    </p>
@endsection
