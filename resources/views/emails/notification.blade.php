@extends('emails.layout')

@section('title', ($title ?? 'Notificación') . ' - Roke Industries')
@section('header_subtitle', $subtitle ?? 'Información importante de tu cuenta')

@section('content')
    @php
        $recipient = $user ?? $notifiable ?? null;
        $customerName = $recipient
            ? (trim($recipient->full_name ?? '')
                ?: trim(($recipient->first_name ?? '') . ' ' . ($recipient->last_name ?? ''))
                ?: ($recipient->email ?? ''))
            : '';
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $actionUrl = $actionUrl ?? null;

        if ($actionUrl && str_starts_with($actionUrl, '/')) {
            $actionUrl = $frontendUrl . $actionUrl;
        }
    @endphp

    @if($customerName)
        <h2>Hola {{ $customerName }},</h2>
    @else
        <h2>Hola,</h2>
    @endif

    @if(!empty($intro))
        <p>{{ $intro }}</p>
    @endif

    @if(!empty($bodyLines))
        @foreach($bodyLines as $line)
            <p>{!! nl2br(e($line)) !!}</p>
        @endforeach
    @elseif(!empty($message))
        <p>{!! nl2br(e($message)) !!}</p>
    @endif

    @if(!empty($details))
        <div class="info-box">
            <h3>{{ $detailsTitle ?? 'Detalles' }}</h3>
            @foreach($details as $label => $value)
                @if($value !== null && $value !== '')
                    @php
                        $displayValue = is_scalar($value)
                            ? $value
                            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    @endphp
                    <p><strong>{{ $label }}:</strong> {{ $displayValue }}</p>
                @endif
            @endforeach
        </div>
    @endif

    @if(!empty($notice))
        <div class="info-box" style="border-left-color: #d69e2e;">
            <h3>{{ $noticeTitle ?? 'Importante' }}</h3>
            <p>{!! nl2br(e($notice)) !!}</p>
        </div>
    @endif

    @if(!empty($actionUrl))
        <div style="text-align: center;">
            <a href="{{ $actionUrl }}" class="button">{{ $actionText ?? 'Ver detalles' }}</a>
        </div>
    @endif

    <div class="divider"></div>

    @if(!empty($footerNote))
        <p>{{ $footerNote }}</p>
    @else
        <p>Si necesitas ayuda adicional, responde este correo o contacta a soporte en <a href="mailto:soporte@rokeindustries.com" style="color: #667eea;">soporte@rokeindustries.com</a>.</p>
    @endif

    <p style="margin-top: 30px;">
        Saludos cordiales,<br>
        <strong>El equipo de Roke Industries</strong>
    </p>
@endsection
