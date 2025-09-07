@extends('emails.layout')

@section('title', 'Restablecer Contraseña - Roke Industries')

@section('header_subtitle', 'Solicitud de restablecimiento de contraseña')

@section('content')
    <h2>Hola {{ $user->name }},</h2>
    
    <p>Hemos recibido una solicitud para restablecer la contraseña de tu cuenta en <strong>Roke Industries</strong>.</p>
    
    <p>Si solicitaste este cambio, puedes restablecer tu contraseña haciendo clic en el siguiente botón:</p>
    
    <div style="text-align: center;">
        <a href="{{ $resetUrl }}" class="button">Restablecer Contraseña</a>
    </div>
    
    <div class="info-box">
        <h3>Información de seguridad:</h3>
        <p><strong>Solicitud realizada:</strong> {{ now()->format('d/m/Y H:i') }}</p>
        <p><strong>IP de origen:</strong> {{ $ipAddress ?? 'No disponible' }}</p>
        <p><strong>Válido hasta:</strong> {{ now()->addMinutes(60)->format('d/m/Y H:i') }}</p>
    </div>
    
    <p><strong>Este enlace expirará en 60 minutos</strong> por razones de seguridad.</p>
    
    <div class="divider"></div>
    
    <p style="color: #e53e3e; font-weight: 600;">⚠️ Importante:</p>
    <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
        <li style="margin-bottom: 8px;">Si no solicitaste este cambio, puedes ignorar este correo</li>
        <li style="margin-bottom: 8px;">Tu contraseña actual permanecerá sin cambios</li>
        <li style="margin-bottom: 8px;">Nunca compartas este enlace con nadie</li>
        <li style="margin-bottom: 8px;">Si tienes dudas, contacta inmediatamente a soporte</li>
    </ul>
    
    <p>Si no puedes hacer clic en el botón, copia y pega el siguiente enlace en tu navegador:</p>
    <p style="word-break: break-all; background-color: #f7fafc; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 14px;">
        {{ $resetUrl }}
    </p>
    
    <p>Si tienes problemas o no solicitaste este cambio, contacta inmediatamente a nuestro equipo de soporte en <a href="mailto:soporte@rokeindustries.com" style="color: #667eea;">soporte@rokeindustries.com</a></p>
    
    <p style="margin-top: 30px;">
        Saludos cordiales,<br>
        <strong>El equipo de seguridad de Roke Industries</strong>
    </p>
@endsection

