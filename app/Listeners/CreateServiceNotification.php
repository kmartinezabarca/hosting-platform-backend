<?php

namespace App\Listeners;

use App\Events\ServiceStatusChanged;
use App\Events\ServicePurchased;
use App\Events\ServiceReady;
use App\Events\ServiceMaintenanceScheduled;
use App\Events\ServiceMaintenanceCompleted;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ServiceNotification;

class CreateServiceNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle service status changed event.
     */
    public function handleStatusChanged(ServiceStatusChanged $event)
    {
        $this->createNotification($event->service->user, [
            'title' => 'Estado del Servicio Actualizado',
            'message' => $event->broadcastWith()['message'],
            'type' => 'service_status',
            'data' => $event->broadcastWith(),
        ]);

        // Notificar a administradores
        $this->notifyAdmins([
            'title' => 'Servicio Actualizado',
            'message' => "El servicio '{$event->service->name}' del usuario {$event->service->user->full_name} cambió de estado a {$event->newStatus}",
            'type' => 'admin_service_status',
            'data' => $event->broadcastWith(),
        ]);
    }

    /**
     * Handle service purchased event.
     */
    public function handlePurchased(ServicePurchased $event)
    {
        $this->createNotification($event->service->user, [
            'title' => 'Servicio Adquirido',
            'message' => $event->broadcastWith()['message'],
            'type' => 'service_purchased',
            'data' => $event->broadcastWith(),
        ]);

        // Notificar a administradores
        $this->notifyAdmins([
            'title' => 'Nueva Compra',
            'message' => "El usuario {$event->service->user->full_name} adquirió el servicio '{$event->service->name}'",
            'type' => 'admin_service_purchased',
            'data' => $event->broadcastWith(),
        ]);
    }

    /**
     * Handle service ready event.
     */
    public function handleReady(ServiceReady $event)
    {
        $this->createNotification($event->service->user, [
            'title' => 'Servicio Listo',
            'message' => $event->broadcastWith()['message'],
            'type' => 'service_ready',
            'data' => $event->broadcastWith(),
        ]);
    }

    /**
     * Handle maintenance scheduled event.
     */
    public function handleMaintenanceScheduled(ServiceMaintenanceScheduled $event)
    {
        $this->createNotification($event->service->user, [
            'title' => 'Mantenimiento Programado',
            'message' => $event->broadcastWith()['message'],
            'type' => 'service_maintenance_scheduled',
            'data' => $event->broadcastWith(),
        ]);
    }

    /**
     * Handle maintenance completed event.
     */
    public function handleMaintenanceCompleted(ServiceMaintenanceCompleted $event)
    {
        $this->createNotification($event->service->user, [
            'title' => 'Mantenimiento Completado',
            'message' => $event->broadcastWith()['message'],
            'type' => 'service_maintenance_completed',
            'data' => $event->broadcastWith(),
        ]);
    }

    /**
     * Create notification for a specific user.
     */
    private function createNotification(User $user, array $data)
    {
        $user->notify(new ServiceNotification($data));
    }

    /**
     * Notify all administrators.
     */
    private function notifyAdmins(array $data)
    {
        $admins = User::where('role', 'admin')
                     ->orWhere('role', 'super_admin')
                     ->get();

        foreach ($admins as $admin) {
            $admin->notify(new ServiceNotification($data));
        }
    }
}

