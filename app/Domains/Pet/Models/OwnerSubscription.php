<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class OwnerSubscription extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'owner_subscriptions';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'owner_id', 'plan_code', 'status', 'cancel_at_period_end', 'provider', 'checkout_url', 'billing_email',
        'trial_ends_at', 'current_period_end', 'support_notes',
        'stripe_customer_id', 'stripe_subscription_id', 'stripe_checkout_session_id',
        'stripe_price_id', 'last_invoice_id', 'canceled_at',
        'payment_failed_at', 'grace_period_ends_at', 'last_payment_error', 'expiry_notified_at',
    ];

    protected $casts = [
        'cancel_at_period_end' => 'boolean',
        'trial_ends_at'        => 'datetime',
        'current_period_end'   => 'datetime',
        'canceled_at'          => 'datetime',
        'payment_failed_at'    => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'expiry_notified_at'   => 'datetime',
    ];

    /** Días de gracia tras un cobro fallido antes de degradar al plan gratuito. */
    public const GRACE_PERIOD_DAYS = 5;

    /** Slug del plan gratuito al que se degrada la cuenta tras la morosidad. */
    public const FREE_PLAN_CODE = 'free';

    protected $attributes = [
        'plan_code' => 'starter',
        'status'    => 'trialing',
        'provider'  => 'stripe_payment_link',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PetPlan::class, 'plan_code', 'slug');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing']);
    }

    public function hasUsableEntitlement(): bool
    {
        if ($this->status === 'active') {
            return true;
        }

        if ($this->status === 'trialing') {
            return $this->trial_ends_at === null || $this->trial_ends_at->isFuture();
        }

        return $this->inGracePeriod();
    }

    public function scopeForOwner(Builder $query, string $ownerId): Builder
    {
        return $query->where('owner_id', $ownerId);
    }

    public static function currentForOwner(string $ownerId): ?self
    {
        return static::query()
            ->with('plan')
            ->forOwner($ownerId)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'trialing' THEN 1 WHEN 'past_due' THEN 2 WHEN 'incomplete' THEN 3 WHEN 'canceled' THEN 4 ELSE 5 END")
            ->latest('updated_at')
            ->first();
    }

    /**
     * Límite de mascotas del plan actual. null = ilimitado (p. ej. Pro).
     * Si no hay plan reconocido, aplica el límite más restrictivo (1, como free).
     */
    public function petLimit(): ?int
    {
        if (! $this->hasUsableEntitlement()) {
            return 1;
        }

        $plan = $this->plan;
        if (!$plan) {
            return 1;
        }
        return $plan->max_pets; // null = ilimitado
    }

    /**
     * ¿El plan actual incluye la feature indicada? Lee pet_plans.features (cada
     * feature tiene un `key` estable e `included`). Si el plan o la feature no
     * existen, se considera NO incluida.
     */
    public function hasFeature(string $key): bool
    {
        if (! $this->hasUsableEntitlement()) {
            return false;
        }

        $requestedKey = $this->canonicalFeatureKey($key);
        if (! $requestedKey) {
            return false;
        }

        $plan = $this->plan;
        foreach ((array) ($plan?->features ?? []) as $feature) {
            $featureKey = null;
            $included = null;

            if (is_string($feature)) {
                $featureKey = $this->inferFeatureKey($feature);
            } elseif (is_array($feature)) {
                $featureKey = $this->canonicalFeatureKey((string) ($feature['key'] ?? ''))
                    ?? $this->inferFeatureKey((string) ($feature['label'] ?? ''));
                $included = array_key_exists('included', $feature) ? (bool) $feature['included'] : null;
            } else {
                continue;
            }

            if ($featureKey === $requestedKey) {
                return $included ?? $this->fallbackIncludesFeature($requestedKey);
            }
        }

        return $this->fallbackIncludesFeature($requestedKey);
    }

    private function inferFeatureKey(string $label): ?string
    {
        $slug = Str::slug($label);
        $known = [
            'enlaces-veterinarios-temporales' => 'vet_links',
            'enlaces-veterinarios'            => 'vet_links',
            'links-veterinarios-ilimitados'   => 'vet_links',
            'links-veterinarios'              => 'vet_links',
            'historial-de-peso-con-graficas'  => 'weight_tracking',
            'analitica-de-escaneos'           => 'scan_analytics',
            'analitica-avanzada-de-escaneos'  => 'scan_analytics',
            'historial-de-escaneos'           => 'scan_analytics',
            'recordatorios-push-en-la-app'    => 'push_notifications',
            'recordatorios-push'              => 'push_notifications',
            'recordatorios-push-email'        => 'push_notifications',
            'recordatorios-email-push'        => 'push_notifications',
            'historial-medico-completo'       => 'medical_history_full',
            'cartilla-e-historial-medico'     => 'medical_history_full',
        ];

        if (isset($known[$slug])) {
            return $known[$slug];
        }

        if (Str::contains($slug, ['veterinario', 'vet-link', 'vet-links'])) {
            return 'vet_links';
        }
        if (Str::contains($slug, 'peso')) {
            return 'weight_tracking';
        }
        if (Str::contains($slug, ['escaneo', 'scan', 'analitica'])) {
            return 'scan_analytics';
        }
        if (Str::contains($slug, 'push')) {
            return 'push_notifications';
        }
        if (Str::contains($slug, ['historial-medico', 'cartilla'])) {
            return 'medical_history_full';
        }

        return $this->canonicalFeatureKey($label);
    }

    private function canonicalFeatureKey(string $key): ?string
    {
        $slug = Str::slug($key);
        if ($slug === '') {
            return null;
        }

        $aliases = [
            'vet-links'            => 'vet_links',
            'vet-links-temporales' => 'vet_links',
            'push-reminders'       => 'push_notifications',
            'push-notifications'   => 'push_notifications',
            'weight-tracking'      => 'weight_tracking',
            'scan-analytics'       => 'scan_analytics',
            'medical-history-full' => 'medical_history_full',
        ];

        return $aliases[$slug] ?? str_replace('-', '_', $slug);
    }

    private function fallbackIncludesFeature(string $key): bool
    {
        $planCode = Str::slug((string) $this->plan_code);
        $matrix = [
            'free' => [
                'qr_nfc',
                'lost_mode',
                'email_reminders',
                'medical_history',
                'medical_history_full',
            ],
            'starter' => [
                'qr_nfc',
                'lost_mode',
                'email_reminders',
                'push_notifications',
                'medical_history',
                'medical_history_full',
                'vet_links',
                'weight_tracking',
            ],
            'pro' => [
                'qr_nfc',
                'lost_mode',
                'email_reminders',
                'push_notifications',
                'medical_history',
                'medical_history_full',
                'vet_links',
                'weight_tracking',
                'scan_analytics',
                'whatsapp_reminders',
                'priority_support',
            ],
        ];

        return in_array($key, $matrix[$planCode] ?? [], true);
    }

    /** ¿Está en periodo de gracia por un cobro fallido todavía no vencido? */
    public function inGracePeriod(): bool
    {
        return $this->status === 'past_due'
            && $this->grace_period_ends_at !== null
            && $this->grace_period_ends_at->isFuture();
    }
}
