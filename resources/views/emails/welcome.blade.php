@extends('emails.layout')

@section('title', '¡Bienvenido a Roke Industries!')

@section('header_subtitle', '¡Tu cuenta ha sido creada exitosamente!')

@section('content')
    <h2>¡Hola {{ $user->name }}!</h2>
    
    <p>¡Bienvenido a <strong>Roke Industries</strong>! Nos complace tenerte como parte de nuestra comunidad de hosting profesional.</p>
    
    <p>Tu cuenta ha sido creada exitosamente y ya puedes comenzar a disfrutar de todos nuestros servicios de hosting de alta calidad.</p>
    
    <div class="info-box">
        <h3>Detalles de tu cuenta:</h3>
        <p><strong>Nombre:</strong> {{ $user->name }}</p>
        <p><strong>Email:</strong> {{ $user->email }}</p>
        <p><strong>Fecha de registro:</strong> {{ $user->created_at->format('d/m/Y H:i') }}</p>
        @if(isset($user->plan))
        <p><strong>Plan:</strong> {{ $user->plan }}</p>
        @endif
    </div>
    
    <p>Para comenzar a usar tu cuenta, puedes acceder a nuestro panel de control haciendo clic en el siguiente botón:</p>
    
    <div style="text-align: center;">
        <a href="{{ $loginUrl ?? url('/login') }}" class="button">Acceder a mi Panel</a>
    </div>
    
    <div class="divider"></div>
    
    <h3>¿Qué puedes hacer ahora?</h3>
    <ul style="color: #4a5568; padding-left: 20px; margin-bottom: 20px;">
        <li style="margin-bottom: 8px;">Configurar tu primer sitio web</li>
        <li style="margin-bottom: 8px;">Explorar nuestras herramientas de gestión</li>
        <li style="margin-bottom: 8px;">Configurar tu dominio personalizado</li>
        <li style="margin-bottom: 8px;">Revisar nuestros recursos de ayuda</li>
    </ul>
    
    <p>Si tienes alguna pregunta o necesitas ayuda, no dudes en contactar a nuestro equipo de soporte en <a href="mailto:soporte@rokeindustries.com" style="color: #667eea;">soporte@rokeindustries.com</a></p>
    
    <p>¡Gracias por elegir Roke Industries para tus necesidades de hosting!</p>
    
    <p style="margin-top: 30px;">
        Saludos cordiales,<br>
        <strong>El equipo de Roke Industries</strong>
    </p>
@endsection

