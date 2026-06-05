<?php

namespace App\Domains\Platform\Events;

use App\Domains\Platform\Models\Quotation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotationViewed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Quotation $quotation,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
    ) {}
}
