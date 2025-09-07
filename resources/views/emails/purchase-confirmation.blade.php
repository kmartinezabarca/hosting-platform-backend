@extends('emails.layout')

@section('title', 'Confirmación de Compra - Roke Industries')

@section('header_subtitle', '¡Tu compra ha sido procesada exitosamente!')

@section('content')
    <h2>¡Gracias por tu compra, {{ $user->name }}!</h2>
    
    <p>Tu pedido ha sido procesado exitosamente. A continuación encontrarás los detalles de tu compra:</p>
    
    <div class="info-box">
        <h3>Detalles del Pedido #{{ $order->id ?? 'N/A' }}</h3>
        <p><strong>Fecha:</strong> {{ ($order->created_at ?? now())->format('d/m/Y H:i') }}</p>
        <p><strong>Estado:</strong> <span style="color: #38a169; font-weight: 600;">Completado</span></p>
        <p><strong>Método de pago:</strong> {{ $paymentMethod ?? 'Tarjeta de crédito' }}</p>
        @if(isset($order->transaction_id))
        <p><strong>ID de transacción:</strong> {{ $order->transaction_id }}</p>
        @endif
    </div>
    
    <h3>Productos/Servicios Adquiridos:</h3>
    <div style="background-color: #f7fafc; padding: 20px; border-radius: 8px; margin: 20px 0;">
        @if(isset($items) && is_array($items))
            @foreach($items as $item)
            <div style="border-bottom: 1px solid #e2e8f0; padding: 15px 0; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="color: #2d3748; margin-bottom: 5px;">{{ $item['name'] ?? 'Producto' }}</h4>
                    <p style="color: #718096; font-size: 14px; margin: 0;">{{ $item['description'] ?? '' }}</p>
                    @if(isset($item['quantity']) && $item['quantity'] > 1)
                    <p style="color: #718096; font-size: 14px; margin: 0;">Cantidad: {{ $item['quantity'] }}</p>
                    @endif
                </div>
                <div style="text-align: right;">
                    <p style="font-weight: 600; color: #2d3748; margin: 0;">${{ number_format($item['price'] ?? 0, 2) }}</p>
                </div>
            </div>
            @endforeach
        @else
            <div style="padding: 15px 0;">
                <h4 style="color: #2d3748; margin-bottom: 5px;">{{ $serviceName ?? 'Servicio de Hosting' }}</h4>
                <p style="color: #718096; font-size: 14px; margin: 0;">{{ $serviceDescription ?? 'Plan de hosting profesional' }}</p>
            </div>
        @endif
        
        <div style="border-top: 2px solid #667eea; padding: 15px 0; margin-top: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="color: #2d3748; margin: 0;">Total:</h3>
                <h3 style="color: #2d3748; margin: 0;">${{ number_format($total ?? 0, 2) }}</h3>
            </div>
        </div>
    </div>
    
    <div style="text-align: center;">
        <a href="{{ $dashboardUrl ?? url('/dashboard') }}" class="button">Ver mi Panel de Control</a>
    </div>
    
    <div class="divider"></div>
    
    <h3>¿Qué sigue?</h3>
    <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
        <li style="margin-bottom: 8px;">Tu servicio será activado en los próximos minutos</li>
        <li style="margin-bottom: 8px;">Recibirás un correo con los detalles de configuración</li>
        <li style="margin-bottom: 8px;">Puedes acceder a tu panel de control para gestionar tus servicios</li>
        <li style="margin-bottom: 8px;">La factura será generada y enviada por separado</li>
    </ul>
    
    <div class="info-box">
        <h3>Información de Facturación:</h3>
        <p>Se generará una factura oficial que será enviada a tu correo electrónico en las próximas 24 horas.</p>
        <p>También podrás descargar todas tus facturas desde tu panel de control.</p>
    </div>
    
    <p>Si tienes alguna pregunta sobre tu compra o necesitas ayuda con la configuración, no dudes en contactar a nuestro equipo de soporte en <a href="mailto:soporte@rokeindustries.com" style="color: #667eea;">soporte@rokeindustries.com</a></p>
    
    <p>¡Gracias por confiar en Roke Industries!</p>
    
    <p style="margin-top: 30px;">
        Saludos cordiales,<br>
        <strong>El equipo de ventas de Roke Industries</strong>
    </p>
@endsection

