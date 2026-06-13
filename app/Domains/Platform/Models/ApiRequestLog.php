<?php

namespace App\Domains\Platform\Models;

use App\Models\User;
use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiRequestLog extends Model
{
    use HasFactory, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'request_id',
        'user_id',
        'method',
        'path',
        'path_hash',
        'full_url',
        'route_name',
        'route_action',
        'status_code',
        'successful',
        'duration_ms',
        'ip_address',
        'ip_chain',
        'user_agent',
        'host',
        'origin',
        'referer',
        'content_type',
        'accept',
        'request_headers',
        'query_params',
        'route_params',
        'request_body',
        'uploaded_files',
        'response_headers',
        'response_body',
        'request_truncated',
        'response_truncated',
        'error_class',
        'error_message',
        'error_trace',
    ];

    protected $casts = [
        'successful' => 'boolean',
        'duration_ms' => 'integer',
        'ip_chain' => 'array',
        'request_headers' => 'array',
        'query_params' => 'array',
        'route_params' => 'array',
        'request_body' => 'array',
        'uploaded_files' => 'array',
        'response_headers' => 'array',
        'response_body' => 'array',
        'request_truncated' => 'boolean',
        'response_truncated' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
