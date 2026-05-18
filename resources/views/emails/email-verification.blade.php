@extends('emails.layout')

@section('title', 'Verifica tu correo electrónico')
@section('header_subtitle', 'Confirma tu dirección de correo')

@section('content')
    @php
        $customerName = trim($user->full_name ?? '')
            ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
            ?: $user->email;
    @endphp

    <h2>Hola {{ $customerName }},</h2>

    <p>Gracias por usar <strong>Roke Industries</strong>. Para proteger tu cuenta y mantener tus servicios seguros, necesitamos confirmar que este correo te pertenece.</p>

    <p>Haz clic en el siguiente botón para verificar tu dirección de correo electrónico:</p>

    <div style="text-align: center;">
        <a href="{{ $verificationUrl }}" class="button">Verificar mi correo</a>
    </div>

    <div class="info-box">
        <h3>Importante</h3>
        <p>Este enlace de verificación es personal y puede expirar por seguridad.</p>
        <p>Si tú no solicitaste este correo, puedes ignorarlo sin hacer ningún cambio.</p>
    </div>

    <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
    <p style="word-break: break-all;">
        <a href="{{ $verificationUrl }}" style="color: #667eea;">{{ $verificationUrl }}</a>
    </p>

    <p style="margin-top: 30px;">
        Saludos,<br>
        <strong>El equipo de Roke Industries</strong>
    </p>
@endsection
