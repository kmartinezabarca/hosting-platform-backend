<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DnsRecord extends Model
{
    use HasFactory, SoftDeletes;

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'uuid',
        'domain_id',
        'type',
        'name',
        'content',
        'ttl',
        'priority',
        'proxied',
        'cloudflare_id',
        'sync_status',
        'last_synced_at',
    ];

    // ── Casts ─────────────────────────────────────────────────────────────────

    protected $casts = [
        'proxied'        => 'boolean',
        'ttl'            => 'integer',
        'priority'       => 'integer',
        'last_synced_at' => 'datetime',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Route key ─────────────────────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function domain(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', strtoupper($type));
    }

    public function scopePending($query)
    {
        return $query->where('sync_status', 'pending');
    }

    public function scopeDirty($query)
    {
        return $query->where('sync_status', 'dirty');
    }

    public function scopeSynced($query)
    {
        return $query->where('sync_status', 'synced');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isSynced(): bool
    {
        return $this->sync_status === 'synced';
    }

    public function isPending(): bool
    {
        return $this->sync_status === 'pending';
    }

    /**
     * Marca el registro como sincronizado con Cloudflare.
     */
    public function markSynced(string $cloudflareId): void
    {
        $this->update([
            'sync_status'    => 'synced',
            'cloudflare_id'  => $cloudflareId,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Convierte el registro a formato para la API de Cloudflare.
     */
    public function toCloudflarePayload(): array
    {
        $payload = [
            'type'    => $this->type,
            'name'    => $this->name,
            'content' => $this->content,
            'ttl'     => $this->ttl,
            'proxied' => $this->proxied,
        ];

        if ($this->priority !== null) {
            $payload['priority'] = $this->priority;
        }

        return $payload;
    }

    /**
     * Crea un DnsRecord desde la respuesta de la API de Cloudflare.
     */
    public static function fromCloudflareResponse(int $domainId, array $record): static
    {
        return new static([
            'domain_id'     => $domainId,
            'type'          => $record['type'],
            'name'          => $record['name'],
            'content'       => $record['content'],
            'ttl'           => $record['ttl'] ?? 3600,
            'priority'      => $record['priority'] ?? null,
            'proxied'       => $record['proxied'] ?? false,
            'cloudflare_id' => $record['id'],
            'sync_status'   => 'synced',
            'last_synced_at' => now(),
        ]);
    }
}
