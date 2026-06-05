<?php

namespace App\Domains\Platform\Events;

use App\Domains\Platform\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotationReopened
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Quotation $quotation,
        public readonly User $actor,
        public readonly ?string $reason,
    ) {}
}
