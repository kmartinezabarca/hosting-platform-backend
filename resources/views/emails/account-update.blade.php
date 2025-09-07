@extends('emails.layout')

@section('title', 'Actualización de Cuenta - Roke Industries')

@section('header_subtitle', 'Tu cuenta ha sido actualizada')

@section('content')
    <h2>Hola {{ $user->name }},</h2>
    
    <p>Te confirmamos que se han realizado cambios importantes en tu cuenta de Roke Industries.</p>
    
    @if(isset($updateType))
        @if($updateType === 'profile')
            <div class="info-box">
                <h3>👤 Actualización de Perfil</h3>
                <p>Se han actualizado los datos de tu perfil personal.</p>
            </div>
            
            <h3>Cambios realizados:</h3>
            <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
                @if(isset($changes['name']))
                <li style="margin-bottom: 8px;"><strong>Nombre:</strong> {{ $changes['name'] }}</li>
                @endif
                @if(isset($changes['email']))
                <li style="margin-bottom: 8px;"><strong>Email:</strong> {{ $changes['email'] }}</li>
                @endif
                @if(isset($changes['phone']))
                <li style="margin-bottom: 8px;"><strong>Teléfono:</strong> {{ $changes['phone'] }}</li>
                @endif
                @if(isset($changes['company']))
                <li style="margin-bottom: 8px;"><strong>Empresa:</strong> {{ $changes['company'] }}</li>
                @endif
            </ul>
            
        @elseif($updateType === 'plan')
            <div class="info-box" style="border-left-color: #38a169;">
                <h3>📈 Cambio de Plan</h3>
                <p><strong>Plan anterior:</strong> {{ $oldPlan ?? 'Plan Básico' }}</p>
                <p><strong>Nuevo plan:</strong> {{ $newPlan ?? 'Plan Profesional' }}</p>
                <p><strong>Fecha de cambio:</strong> {{ $changeDate ?? now()->format('d/m/Y H:i') }}</p>
            </div>
            
            <h3>Beneficios de tu nuevo plan:</h3>
            <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
                @if(isset($newFeatures) && is_array($newFeatures))
                    @foreach($newFeatures as $feature)
                    <li style="margin-bottom: 8px;">{{ $feature }}</li>
                    @endforeach
                @else
                    <li style="margin-bottom: 8px;">Mayor espacio de almacenamiento</li>
                    <li style="margin-bottom: 8px;">Ancho de banda ilimitado</li>
                    <li style="margin-bottom: 8px;">Soporte prioritario</li>
                    <li style="margin-bottom: 8px;">Backups automáticos diarios</li>
                @endif
            </ul>
            
        @elseif($updateType === 'security')
            <div class="info-box" style="border-left-color: #d69e2e;">
                <h3>🔐 Actualización de Seguridad</h3>
                <p>Se han actualizado las configuraciones de seguridad de tu cuenta.</p>
            </div>
            
            <h3>Cambios de seguridad:</h3>
            <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
                @if(isset($securityChanges['password']))
                <li style="margin-bottom: 8px;">✅ Contraseña actualizada</li>
                @endif
                @if(isset($securityChanges['2fa']))
                <li style="margin-bottom: 8px;">✅ Autenticación de dos factores {{ $securityChanges['2fa'] ? 'habilitada' : 'deshabilitada' }}</li>
                @endif
                @if(isset($securityChanges['api_keys']))
                <li style="margin-bottom: 8px;">✅ Claves API regeneradas</li>
                @endif
                @if(isset($securityChanges['sessions']))
                <li style="margin-bottom: 8px;">✅ Sesiones activas cerradas</li>
                @endif
            </ul>
            
        @elseif($updateType === 'billing')
            <div class="info-box">
                <h3>💳 Información de Facturación</h3>
                <p>Se ha actualizado la información de facturación de tu cuenta.</p>
            </div>
            
            <h3>Cambios realizados:</h3>
            <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
                @if(isset($billingChanges['payment_method']))
                <li style="margin-bottom: 8px;"><strong>Método de pago:</strong> {{ $billingChanges['payment_method'] }}</li>
                @endif
                @if(isset($billingChanges['billing_address']))
                <li style="margin-bottom: 8px;">✅ Dirección de facturación actualizada</li>
                @endif
                @if(isset($billingChanges['tax_info']))
                <li style="margin-bottom: 8px;">✅ Información fiscal actualizada</li>
                @endif
            </ul>
            
        @endif
    @else
        <div class="info-box">
            <h3>Actualización General</h3>
            <p>{{ $message ?? 'Se han realizado cambios en tu cuenta.' }}</p>
        </div>
    @endif
    
    <div class="info-box">
        <h3>Detalles de la Actualización</h3>
        <p><strong>Fecha y hora:</strong> {{ $updateDate ?? now()->format('d/m/Y H:i') }}</p>
        <p><strong>IP de origen:</strong> {{ $ipAddress ?? 'No disponible' }}</p>
        <p><strong>Dispositivo:</strong> {{ $userAgent ?? 'No disponible' }}</p>
    </div>
    
    @if(isset($requiresAction) && $requiresAction)
    <div style="text-align: center;">
        <a href="{{ $actionUrl ?? url('/dashboard/account') }}" class="button">{{ $actionText ?? 'Revisar Cambios' }}</a>
    </div>
    @endif
    
    <div class="divider"></div>
    
    <h3>¿No realizaste estos cambios?</h3>
    <p style="color: #e53e3e; font-weight: 600;">Si no autorizaste estos cambios, es importante que tomes acción inmediatamente:</p>
    
    <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
        <li style="margin-bottom: 8px;">Cambia tu contraseña inmediatamente</li>
        <li style="margin-bottom: 8px;">Revisa la actividad reciente en tu cuenta</li>
        <li style="margin-bottom: 8px;">Contacta a nuestro equipo de soporte</li>
        <li style="margin-bottom: 8px;">Considera habilitar la autenticación de dos factores</li>
    </ul>
    
    <div style="text-align: center; margin: 20px 0;">
        <a href="mailto:soporte@rokeindustries.com?subject=Actividad no autorizada en mi cuenta" style="background-color: #e53e3e; color: white !important; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 600;">Reportar Actividad Sospechosa</a>
    </div>
    
    <p>Para cualquier pregunta sobre estos cambios o si necesitas ayuda con tu cuenta, no dudes en contactarnos en <a href="mailto:soporte@rokeindustries.com" style="color: #667eea;">soporte@rokeindustries.com</a></p>
    
    <p>Gracias por mantener tu cuenta segura y actualizada.</p>
    
    <p style="margin-top: 30px;">
        Saludos cordiales,<br>
        <strong>El equipo de cuentas de Roke Industries</strong>
    </p>
@endsection

