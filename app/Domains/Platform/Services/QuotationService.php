<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Enums\QuotationStatus;
use App\Domains\Platform\Events\QuotationAccepted;
use App\Domains\Platform\Events\QuotationRejected;
use App\Domains\Platform\Events\QuotationReopened;
use App\Domains\Platform\Events\QuotationViewed;
use App\Domains\Platform\Models\Quotation;
use App\Domains\Platform\Models\QuotationActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuotationService
{
    private ?string $ip        = null;
    private ?string $userAgent = null;

    // Fluent setter — call before any operation to capture request context
    public function withRequest(Request $request): static
    {
        $this->ip        = $request->ip();
        $this->userAgent = $request->userAgent();
        return $this;
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function create(array $data, User $actor): Quotation
    {
        $quotation = new Quotation([
            'title'            => $data['title'],
            'client_name'      => $data['client_name'],
            'client_email'     => $data['client_email'],
            'client_company'   => $data['client_company']   ?? null,
            'client_phone'     => $data['client_phone']     ?? null,
            'items'            => $data['items'],
            'discount_percent' => $data['discount_percent'] ?? 0,
            'tax_percent'      => $data['tax_percent']      ?? 16,
            'currency'         => $data['currency']         ?? 'MXN',
            'notes'            => $data['notes']            ?? null,
            'terms'            => $data['terms']            ?? null,
            'status'           => QuotationStatus::Draft,
            'revision_number'  => 1,
        ]);

        $quotation->recalculate();
        $quotation->save();

        $this->log($quotation, 'created', [], $quotation->toArray(), $actor);

        return $quotation->fresh();
    }

    public function update(Quotation $quotation, array $data, User $actor): Quotation
    {
        if (!$quotation->canBeModified()) {
            throw new \DomainException('Accepted quotations cannot be modified.');
        }

        $old = $this->auditableSnapshot($quotation);

        // Apply scalar fields
        $scalarFields = ['title', 'client_name', 'client_email', 'client_company',
                         'client_phone', 'currency', 'notes', 'terms',
                         'discount_percent', 'tax_percent'];

        foreach ($scalarFields as $field) {
            if (array_key_exists($field, $data)) {
                $quotation->$field = $data[$field];
            }
        }

        if (array_key_exists('items', $data)) {
            $quotation->items = $data['items'];
        }

        if (array_key_exists('items', $data)
            || array_key_exists('discount_percent', $data)
            || array_key_exists('tax_percent', $data)) {
            $quotation->recalculate();
        }

        $quotation->save();

        $this->log($quotation, 'updated', $old, $this->auditableSnapshot($quotation), $actor);

        return $quotation->fresh();
    }

    public function delete(Quotation $quotation, User $actor): void
    {
        if (!$quotation->canBeDeleted()) {
            throw new \DomainException('Accepted quotations cannot be deleted.');
        }

        $this->log($quotation, 'deleted', $this->auditableSnapshot($quotation), [], $actor);

        $quotation->delete(); // soft delete
    }

    // ── Status transitions ────────────────────────────────────────────────────

    public function send(Quotation $quotation, User $actor): Quotation
    {
        if (!$quotation->canBeSent()) {
            throw new \DomainException(
                "Cannot send a quotation with status '{$quotation->status->label()}'."
            );
        }

        $token     = Str::random(48);
        $publicUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
                     . '/cotizacion/' . $token;

        $quotation->update([
            'public_token' => $token,
            'public_url'   => $publicUrl,
            'expires_at'   => now()->addHours(72),
            'status'       => QuotationStatus::Sent,
            'sent_at'      => now(),
        ]);

        $this->log($quotation, 'sent', [], ['public_url' => $publicUrl, 'expires_at' => $quotation->expires_at], $actor);

        return $quotation->fresh();
    }

    public function accept(Quotation $quotation, User $actor): Quotation
    {
        if (!$quotation->canBeAccepted()) {
            throw new \DomainException(
                "Cannot accept a quotation with status '{$quotation->status->label()}'."
            );
        }

        $quotation->update([
            'status'      => QuotationStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $this->log($quotation, 'accepted', [], ['accepted_at' => $quotation->accepted_at], $actor);

        event(new QuotationAccepted($quotation->fresh(), $actor));

        return $quotation->fresh();
    }

    public function reject(Quotation $quotation, User $actor, ?string $reason = null): Quotation
    {
        if (!$quotation->canBeRejected()) {
            throw new \DomainException(
                "Cannot reject a quotation with status '{$quotation->status->label()}'."
            );
        }

        $quotation->update([
            'status'      => QuotationStatus::Rejected,
            'rejected_at' => now(),
        ]);

        $this->log($quotation, 'rejected', [], ['rejected_at' => $quotation->rejected_at], $actor, ['reason' => $reason]);

        event(new QuotationRejected($quotation->fresh(), $actor));

        return $quotation->fresh();
    }

    public function reopen(Quotation $quotation, User $actor, ?string $reason = null): Quotation
    {
        if (!$quotation->canBeReopened()) {
            throw new \DomainException(
                "Cannot reopen a quotation with status '{$quotation->status->label()}'."
            );
        }

        $quotation->update([
            'status'          => QuotationStatus::PendingRevision,
            'reopened_at'     => now(),
            'reopened_reason' => $reason,
        ]);

        $this->log($quotation, 'reopened', [], [
            'reopened_at'     => $quotation->reopened_at,
            'reopened_reason' => $reason,
        ], $actor, ['reason' => $reason]);

        event(new QuotationReopened($quotation->fresh(), $actor, $reason));

        return $quotation->fresh();
    }

    public function regenerateLink(Quotation $quotation, User $actor): Quotation
    {
        if (!$quotation->public_token) {
            throw new \DomainException('This quotation has not been sent yet. Use /send first.');
        }

        $token     = Str::random(48);
        $publicUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
                     . '/cotizacion/' . $token;

        $quotation->update([
            'public_token' => $token,
            'public_url'   => $publicUrl,
            'expires_at'   => now()->addHours(72),
        ]);

        $this->log($quotation, 'link_regenerated', [], ['public_url' => $publicUrl], $actor);

        return $quotation->fresh();
    }

    // ── Public (unauthenticated) ──────────────────────────────────────────────

    public function markViewed(Quotation $quotation): Quotation
    {
        if ($quotation->status === QuotationStatus::Sent) {
            $quotation->update(['status' => QuotationStatus::Viewed]);
        }

        $this->log($quotation, 'viewed', [], [], null, [
            'status_was' => $quotation->getOriginal('status'),
        ]);

        event(new QuotationViewed($quotation->fresh(), $this->ip, $this->userAgent));

        return $quotation->fresh();
    }

    // ── Versioning ────────────────────────────────────────────────────────────

    public function createRevision(Quotation $original, User $actor): Quotation
    {
        $revision = $original->replicate(['uuid', 'public_token', 'public_url',
                                          'sent_at', 'accepted_at', 'rejected_at',
                                          'reopened_at', 'reopened_reason']);

        $revision->uuid            = (string) \Illuminate\Support\Str::uuid();
        $revision->status          = QuotationStatus::Draft;
        $revision->revision_number = $original->revision_number + 1;
        $revision->parent_uuid     = $original->uuid;
        $revision->public_token    = null;
        $revision->public_url      = null;
        $revision->sent_at         = null;
        $revision->accepted_at     = null;
        $revision->rejected_at     = null;
        $revision->reopened_at     = null;
        $revision->reopened_reason = null;
        $revision->save();

        $this->log($revision, 'created', [], $revision->toArray(), $actor, [
            'parent_uuid' => $original->uuid,
            'type'        => 'revision',
        ]);

        return $revision->fresh();
    }

    // ── Private: audit logger ─────────────────────────────────────────────────

    private function log(
        Quotation $quotation,
        string $action,
        array $old,
        array $new,
        ?User $actor,
        array $metadata = []
    ): void {
        QuotationActivity::create([
            'quotation_id' => $quotation->id,
            'user_id'      => $actor?->id,
            'action'       => $action,
            'old_values'   => $old ?: null,
            'new_values'   => $new ?: null,
            'metadata'     => $metadata ?: null,
            'ip_address'   => $this->ip,
            'user_agent'   => $this->userAgent,
            'created_at'   => now(),
        ]);
    }

    private function auditableSnapshot(Quotation $quotation): array
    {
        return $quotation->only([
            'title', 'client_name', 'client_email', 'client_company', 'client_phone',
            'items', 'subtotal', 'discount_percent', 'discount_amount',
            'tax_percent', 'tax_amount', 'total', 'currency',
            'notes', 'terms', 'status',
        ]);
    }
}
