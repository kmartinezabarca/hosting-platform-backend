@extends('emails.layout')

@section('title', 'Pago Procesado - Roke Industries')

@section('header_subtitle', '¡Tu pago ha sido procesado exitosamente!')

@section('content')
    <h2>Hola {{ $user->name }},</h2>
    
    <p>Te confirmamos que hemos recibido tu pago exitosamente. Tu cuenta ha sido actualizada y tus servicios continúan activos.</p>
    
    <div class="info-box">
        <h3>Detalles del Pago</h3>
        <p><strong>Monto:</strong> ${{ number_format($payment->amount ?? 0, 2) }}</p>
        <p><strong>Fecha:</strong> {{ ($payment->created_at ?? now())->format('d/m/Y H:i') }}</p>
        <p><strong>Método:</strong> {{ $payment->method ?? 'Tarjeta de crédito' }}</p>
        <p><strong>ID de transacción:</strong> {{ $payment->transaction_id ?? 'N/A' }}</p>
        <p><strong>Estado:</strong> <span style="color: #38a169; font-weight: 600;">Completado</span></p>
    </div>
    
    @if(isset($subscription))
    <h3>Información de Suscripción</h3>
    <div style="background-color: #f7fafc; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <p><strong>Plan:</strong> {{ $subscription->plan_name ?? 'Plan Profesional' }}</p>
        <p><strong>Período:</strong> {{ $subscription->billing_cycle ?? 'Mensual' }}</p>
        <p><strong>Próximo pago:</strong> {{ ($subscription->next_billing_date ?? now()->addMonth())->format('d/m/Y') }}</p>
        <p><strong>Estado:</strong> <span style="color: #38a169; font-weight: 600;">Activo</span></p>
    </div>
    @endif
    
    @if(isset($services) && count($services) > 0)
    <h3>Servicios Activos</h3>
    <div style="background-color: #f7fafc; padding: 20px; border-radius: 8px; margin: 20px 0;">
        @foreach($services as $service)
        <div style="border-bottom: 1px solid #e2e8f0; padding: 10px 0;">
            <h4 style="color: #2d3748; margin-bottom: 5px;">{{ $service['name'] ?? 'Servicio' }}</h4>
            <p style="color: #718096; font-size: 14px; margin: 0;">Estado: <span style="color: #38a169;">Activo</span></p>
            @if(isset($service['expires_at']))
            <p style="color: #718096; font-size: 14px; margin: 0;">Válido hasta: {{ $service['expires_at'] }}</p>
            @endif
        </div>
        @endforeach
    </div>
    @endif
    
    <div style="text-align: center;">
        <a href="{{ $invoiceUrl ?? url('/dashboard/invoices') }}" class="button">Ver Factura</a>
    </div>
    
    <div class="divider"></div>
    
    <h3>Información Importante</h3>
    <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
        <li style="margin-bottom: 8px;">Tu factura ha sido generada automáticamente</li>
        <li style="margin-bottom: 8px;">Puedes descargar todas tus facturas desde el panel de control</li>
        <li style="margin-bottom: 8px;">Los servicios permanecen activos sin interrupción</li>
        @if(isset($subscription))
        <li style="margin-bottom: 8px;">El próximo pago se procesará automáticamente el {{ ($subscription->next_billing_date ?? now()->addMonth())->format('d/m/Y') }}</li>
        @endif
    </ul>
    
    @if(isset($isRecurring) && $isRecurring)
    <div class="info-box">
        <h3>Pago Automático Configurado</h3>
        <p>Tu suscripción se renovará automáticamente. Si deseas cancelar o modificar tu suscripción, puedes hacerlo desde tu panel de control en cualquier momento.</p>
    </div>
    @endif
    
    <p>Si tienes alguna pregunta sobre este pago o necesitas una copia de tu factura, no dudes en contactarnos en <a href="mailto:soporte@rokeindustries.com" style="color: #667eea;">soporte@rokeindustries.com</a></p>
    
    <p>¡Gracias por tu confianza en Roke Industries!</p>
    
    <p style="margin-top: 30px;">
        Saludos cordiales,<br>
        <strong>El equipo de facturación de Roke Industries</strong>
    </p>
@endsection

