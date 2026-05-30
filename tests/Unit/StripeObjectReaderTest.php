<?php

namespace Tests\Unit;

use App\Support\StripeObjectReader;
use PHPUnit\Framework\TestCase;

/**
 * Verifica que el lector defensivo resuelva campos tanto en formato legacy
 * (≤ 2025-02) como Basil (2025-03-31), que es donde se movieron
 * invoice.subscription y subscription.current_period_*.
 */
class StripeObjectReaderTest extends TestCase
{
    public function test_subscription_id_from_invoice_legacy(): void
    {
        $invoice = (object) ['subscription' => 'sub_legacy_123'];

        $this->assertSame('sub_legacy_123', StripeObjectReader::subscriptionIdFromInvoice($invoice));
    }

    public function test_subscription_id_from_invoice_basil_parent(): void
    {
        $invoice = (object) [
            'parent' => (object) [
                'subscription_details' => (object) ['subscription' => 'sub_basil_456'],
            ],
        ];

        $this->assertSame('sub_basil_456', StripeObjectReader::subscriptionIdFromInvoice($invoice));
    }

    public function test_subscription_id_from_invoice_basil_line_item(): void
    {
        $invoice = (object) [
            'lines' => (object) [
                'data' => [
                    (object) [
                        'parent' => (object) [
                            'subscription_item_details' => (object) ['subscription' => 'sub_line_789'],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame('sub_line_789', StripeObjectReader::subscriptionIdFromInvoice($invoice));
    }

    public function test_subscription_id_from_invoice_returns_null_when_absent(): void
    {
        $this->assertNull(StripeObjectReader::subscriptionIdFromInvoice((object) []));
    }

    public function test_subscription_period_end_legacy_root(): void
    {
        $sub = (object) ['current_period_end' => 1_700_000_000];

        $this->assertSame(1_700_000_000, StripeObjectReader::subscriptionPeriodEnd($sub)?->timestamp);
    }

    public function test_subscription_period_end_basil_item(): void
    {
        $sub = (object) [
            'items' => (object) [
                'data' => [(object) ['current_period_end' => 1_800_000_000]],
            ],
        ];

        $this->assertSame(1_800_000_000, StripeObjectReader::subscriptionPeriodEnd($sub)?->timestamp);
    }

    public function test_subscription_period_start_basil_item(): void
    {
        $sub = (object) [
            'items' => (object) [
                'data' => [(object) ['current_period_start' => 1_650_000_000]],
            ],
        ];

        $this->assertSame(1_650_000_000, StripeObjectReader::subscriptionPeriodStart($sub)?->timestamp);
    }

    public function test_period_end_from_invoice_line_item(): void
    {
        $invoice = (object) [
            'lines' => (object) [
                'data' => [(object) ['period' => (object) ['end' => 1_750_000_000]]],
            ],
        ];

        $this->assertSame(1_750_000_000, StripeObjectReader::periodEndFromInvoice($invoice)?->timestamp);
    }

    public function test_timestamp_returns_null_for_empty(): void
    {
        $this->assertNull(StripeObjectReader::timestamp(null));
        $this->assertNull(StripeObjectReader::timestamp(0));
    }
}
