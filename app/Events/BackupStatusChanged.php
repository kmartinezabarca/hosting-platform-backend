<?php

namespace App\Events;

use App\Models\Backup;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BackupStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Backup $backup) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.backups'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'uuid'         => $this->backup->uuid,
            'type'         => $this->backup->type,
            'status'       => $this->backup->status,
            'name'         => $this->backup->name,
            'size_bytes'   => $this->backup->size_bytes,
            'error'        => $this->backup->error,
            'started_at'   => $this->backup->started_at?->toISOString(),
            'completed_at' => $this->backup->completed_at?->toISOString(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'backup.status.changed';
    }
}
