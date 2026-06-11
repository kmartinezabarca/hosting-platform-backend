<?php

namespace App\Domains\Pet\Services\Support;

/**
 * Resultado de un turno de IA (datos, sin persistir). Quien lo recibe
 * (GenerateAiReplyJob) crea y transmite el mensaje vía ChatService — un único
 * punto de creación/broadcasting.
 */
class AiTurnResult
{
    /**
     * @param  array<int, array{slug:string,title:string}>  $sources
     */
    public function __construct(
        public readonly ?string $reply,
        public readonly float $confidence,
        public readonly bool $shouldEscalate,
        public readonly ?string $escalationReason = null,
        public readonly array $sources = [],
    ) {}

    public static function escalate(string $reason): self
    {
        return new self(null, 0.0, true, $reason, []);
    }

    public function hasReply(): bool
    {
        return $this->reply !== null && trim($this->reply) !== '';
    }
}
