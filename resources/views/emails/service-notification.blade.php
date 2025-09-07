@extends('emails.layout')

@section('title', 'Notificación de Servicio - Roke Industries')

@section('header_subtitle', 'Información importante sobre tus servicios')

@section('content')
    <h2>Hola {{ $user->name }},</h2>
    
    <p>Te escribimos para informarte sobre una actualización importante relacionada con tus servicios en Roke Industries.</p>
    
    @if(isset($notificationType))
        @if($notificationType === 'maintenance')
            <div class="info-box" style="border-left-color: #d69e2e;">
                <h3>🔧 Mantenimiento Programado</h3>
                <p><strong>Fecha:</strong> {{ $maintenanceDate ?? 'Por definir' }}</p>
                <p><strong>Duración estimada:</strong> {{ $maintenanceDuration ?? '2 horas' }}</p>
                <p><strong>Servicios afectados:</strong> {{ $affectedServices ?? 'Todos los servicios de hosting' }}</p>
            </div>
            
            <p>Realizaremos un mantenimiento programado en nuestros servidores para mejorar el rendimiento y la seguridad de nuestros servicios.</p>
            
            <h3>¿Qué esperar durante el mantenimiento?</h3>
            <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
                <li style="margin-bottom: 8px;">Posibles interrupciones breves en el servicio</li>
                <li style="margin-bottom: 8px;">Mejoras en el rendimiento general</li>
                <li style="margin-bottom: 8px;">Actualizaciones de seguridad importantes</li>
                <li style="margin-bottom: 8px;">Optimizaciones del sistema</li>
            </ul>
            
        @elseif($notificationType === 'outage')
            <div class="info-box" style="border-left-color: #e53e3e;">
                <h3>⚠️ Interrupción del Servicio</h3>
                <p><strong>Estado:</strong> {{ $outageStatus ?? 'Investigando' }}</p>
                <p><strong>Inicio:</strong> {{ $outageStart ?? 'Hace unos minutos' }}</p>
                <p><strong>Servicios afectados:</strong> {{ $affectedServices ?? 'Hosting web' }}</p>
            </div>
            
            <p>Hemos detectado una interrupción en algunos de nuestros servicios. Nuestro equipo técnico está trabajando para resolver el problema lo antes posible.</p>
            
            <h3>Acciones que estamos tomando:</h3>
            <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
                <li style="margin-bottom: 8px;">Investigación activa del problema</li>
                <li style="margin-bottom: 8px;">Implementación de soluciones temporales</li>
                <li style="margin-bottom: 8px;">Monitoreo continuo del sistema</li>
                <li style="margin-bottom: 8px;">Actualizaciones regulares sobre el progreso</li>
            </ul>
            
        @elseif($notificationType === 'upgrade')
            <div class="info-box" style="border-left-color: #38a169;">
                <h3>🚀 Actualización de Servicios</h3>
                <p><strong>Fecha de implementación:</strong> {{ $upgradeDate ?? 'Próximamente' }}</p>
                <p><strong>Servicios mejorados:</strong> {{ $upgradedServices ?? 'Todos los planes' }}</p>
            </div>
            
            <p>¡Tenemos excelentes noticias! Hemos actualizado nuestros servicios para ofrecerte una mejor experiencia.</p>
            
            <h3>Mejoras incluidas:</h3>
            <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
                <li style="margin-bottom: 8px;">{{ $improvement1 ?? 'Mayor velocidad de carga' }}</li>
                <li style="margin-bottom: 8px;">{{ $improvement2 ?? 'Mejor seguridad' }}</li>
                <li style="margin-bottom: 8px;">{{ $improvement3 ?? 'Panel de control actualizado' }}</li>
                <li style="margin-bottom: 8px;">{{ $improvement4 ?? 'Soporte técnico mejorado' }}</li>
            </ul>
            
        @elseif($notificationType === 'security')
            <div class="info-box" style="border-left-color: #e53e3e;">
                <h3>🔒 Alerta de Seguridad</h3>
                <p><strong>Nivel de prioridad:</strong> {{ $securityLevel ?? 'Alto' }}</p>
                <p><strong>Fecha de detección:</strong> {{ $detectionDate ?? now()->format('d/m/Y H:i') }}</p>
            </div>
            
            <p>Hemos detectado actividad sospechosa relacionada con tu cuenta y hemos tomado medidas preventivas para proteger tus datos.</p>
            
            <h3>Acciones recomendadas:</h3>
            <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
                <li style="margin-bottom: 8px;">Cambiar tu contraseña inmediatamente</li>
                <li style="margin-bottom: 8px;">Revisar la actividad reciente en tu cuenta</li>
                <li style="margin-bottom: 8px;">Habilitar autenticación de dos factores</li>
                <li style="margin-bottom: 8px;">Contactar a soporte si notas algo inusual</li>
            </ul>
            
        @endif
    @else
        <div class="info-box">
            <h3>Información General del Servicio</h3>
            <p>{{ $message ?? 'Actualización importante sobre tus servicios.' }}</p>
        </div>
    @endif
    
    @if(isset($actionRequired) && $actionRequired)
    <div style="text-align: center;">
        <a href="{{ $actionUrl ?? url('/dashboard') }}" class="button">{{ $actionText ?? 'Ir al Panel de Control' }}</a>
    </div>
    @endif
    
    <div class="divider"></div>
    
    <h3>¿Necesitas ayuda?</h3>
    <p>Si tienes alguna pregunta o inquietud sobre esta notificación, nuestro equipo de soporte está disponible 24/7 para ayudarte.</p>
    
    <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
        <li style="margin-bottom: 8px;"><strong>Email:</strong> <a href="mailto:soporte@rokeindustries.com" style="color: #667eea;">soporte@rokeindustries.com</a></li>
        <li style="margin-bottom: 8px;"><strong>Panel de soporte:</strong> <a href="{{ url('/support') }}" style="color: #667eea;">Crear ticket</a></li>
        <li style="margin-bottom: 8px;"><strong>Estado del servicio:</strong> <a href="{{ url('/status') }}" style="color: #667eea;">Ver estado actual</a></li>
    </ul>
    
    <p>Mantendremos esta información actualizada en nuestro panel de estado del servicio y te notificaremos sobre cualquier cambio importante.</p>
    
    <p>Gracias por tu paciencia y por confiar en Roke Industries.</p>
    
    <p style="margin-top: 30px;">
        Saludos cordiales,<br>
        <strong>El equipo técnico de Roke Industries</strong>
    </p>
@endsection

