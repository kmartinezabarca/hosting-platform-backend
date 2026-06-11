<?php

namespace App\Domains\Pet\Services\Support;

/**
 * Enmascara datos sensibles ANTES de enviar cualquier texto a la IA.
 * Nunca se mandan a Anthropic correos, teléfonos, tarjetas ni tokens en claro.
 * Es un filtro best-effort; el guardrail real es no permitir acciones a la IA.
 */
class SensitiveDataMasker
{
    public function mask(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Tokens/llaves de proveedores (Stripe, JWT, Bearer, claves tipo sk-/pk-).
        $text = preg_replace('/\b(sk|pk|rk|tok|cus|sub|pi|seti|whsec)_[A-Za-z0-9]{6,}\b/', '[token]', $text);
        $text = preg_replace('/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/', '[token]', $text);
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._\-]{10,}\b/i', '[token]', $text);

        // Correos electrónicos.
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[correo]', $text);

        // Tarjetas: 13-19 dígitos (con espacios o guiones).
        $text = preg_replace('/\b(?:\d[ \-]?){13,19}\b/', '[numero]', $text);

        // Teléfonos: 7+ dígitos seguidos (con separadores y prefijo opcional).
        $text = preg_replace('/\+?\d[\d\s().\-]{6,}\d/', '[telefono]', $text);

        return $text;
    }
}
