<?php

namespace App\Domains\Platform\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tool_calls',
        'tokens_in',
        'tokens_out',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'tokens_in'  => 'integer',
        'tokens_out' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
