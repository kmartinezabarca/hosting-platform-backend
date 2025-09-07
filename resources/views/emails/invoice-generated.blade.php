@extends('emails.layout')

@section('title', 'Nueva Factura Generada - Roke Industries')

@section('header_subtitle', 'Tu factura está lista para descargar')

@section('content')
    <h2>Hola {{ $user->name }},</h2>
    
    <p>Se ha generado una nueva factura para tu cuenta. Puedes revisarla y descargarla cuando gustes.</p>
    
    <div class="info-box">
        <h3>Detalles de la Factura</h3>
        <p><strong>Número:</strong> {{ $invoice->number ?? 'INV-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT) }}</p>
        <p><strong>Fecha de emisión:</strong> {{ ($invoice->created_at ?? now())->format('d/m/Y') }}</p>
        <p><strong>Fecha de vencimiento:</strong> {{ ($invoice->due_date ?? now()->addDays(30))->format('d/m/Y') }}</p>
        <p><strong>Monto total:</strong> ${{ number_format($invoice->total ?? 0, 2) }}</p>
        <p><strong>Estado:</strong> 
            @if(isset($invoice->status))
                @if($invoice->status === 'paid')
                    <span style="color: #38a169; font-weight: 600;">Pagada</span>
                @elseif($invoice->status === 'pending')
                    <span style="color: #d69e2e; font-weight: 600;">Pendiente</span>
                @else
                    <span style="color: #e53e3e; font-weight: 600;">{{ ucfirst($invoice->status) }}</span>
                @endif
            @else
                <span style="color: #38a169; font-weight: 600;">Pagada</span>
            @endif
        </p>
    </div>
    
    <h3>Resumen de Servicios Facturados</h3>
    <div style="background-color: #f7fafc; padding: 20px; border-radius: 8px; margin: 20px 0;">
        @if(isset($invoiceItems) && is_array($invoiceItems))
            @foreach($invoiceItems as $item)
            <div style="border-bottom: 1px solid #e2e8f0; padding: 15px 0; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="color: #2d3748; margin-bottom: 5px;">{{ $item['description'] ?? 'Servicio' }}</h4>
                    <p style="color: #718096; font-size: 14px; margin: 0;">
                        Período: {{ $item['period'] ?? 'N/A' }}
                    </p>
                    @if(isset($item['quantity']) && $item['quantity'] > 1)
                    <p style="color: #718096; font-size: 14px; margin: 0;">Cantidad: {{ $item['quantity'] }}</p>
                    @endif
                </div>
                <div style="text-align: right;">
                    <p style="font-weight: 600; color: #2d3748; margin: 0;">${{ number_format($item['amount'] ?? 0, 2) }}</p>
                </div>
            </div>
            @endforeach
        @else
            <div style="padding: 15px 0;">
                <h4 style="color: #2d3748; margin-bottom: 5px;">Servicios de Hosting</h4>
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
                <span>${{ number_format($invoice->subtotal, 2) }}</span>
            </div>
            @endif
            @if(isset($invoice->tax) && $invoice->tax > 0)
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Impuestos:</span>
                <span>${{ number_format($invoice->tax, 2) }}</span>
            </div>
            @endif
            <div style="display: flex; justify-content: space-between; border-top: 2px solid #667eea; padding-top: 10px; font-weight: 600; font-size: 18px;">
                <span>Total:</span>
                <span>${{ number_format($invoice->total ?? 0, 2) }}</span>
            </div>
        </div>
        @endif
    </div>
    
    <div style="text-align: center;">
        <a href="{{ $downloadUrl ?? url('/dashboard/invoices/' . ($invoice->id ?? '1') . '/download') }}" class="button">Descargar Factura PDF</a>
    </div>
    
    @if(isset($invoice->status) && $invoice->status === 'pending')
    <div class="info-box" style="border-left-color: #d69e2e;">
        <h3>⏰ Pago Pendiente</h3>
        <p>Esta factura aún está pendiente de pago. El pago se procesará automáticamente si tienes configurado un método de pago automático.</p>
        <p>Si necesitas actualizar tu método de pago, puedes hacerlo desde tu panel de control.</p>
    </div>
    @endif
    
    <div class="divider"></div>
    
    <h3>Información Adicional</h3>
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

