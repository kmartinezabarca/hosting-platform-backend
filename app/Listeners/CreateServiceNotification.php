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
use App\Notifications\ServiceNotification;

class CreateServiceNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handleStatusChanged(ServiceStatusChanged $event)
    {
        $this->notifyClient($event->service->user, [
            'title'   => 'Estado del Servicio Actualizado',
            'message' => $event->broadcastWith()['message'],
            'type'    => 'service_status',
            'data'    => $event->broadcastWith(),
        ]);

        $this->notifyAdmins([
            'title'   => 'Servicio Actualizado',
            'message' => "El servicio '{$event->service->name}' del usuario {$event->service->user->full_name} cambió de estado a {$event->newStatus}",
            'type'    => 'admin_service_status',
            'data'    => $event->broadcastWith(),
        ]);
    }

    public function handlePurchased(ServicePurchased $event)
    {
        $this->notifyClient($event->service->user, [
            'title'   => 'Servicio Adquirido',
            'message' => $event->broadcastWith()['message'],
            'type'    => 'service_purchased',
            'data'    => $event->broadcastWith(),
        ]);

        $this->notifyAdmins([
            'title'   => 'Nueva Compra',
            'message' => "El usuario {$event->service->user->full_name} adquirió el servicio '{$event->service->name}'",
            'type'    => 'admin_service_purchased',
            'data'    => $event->broadcastWith(),
        ]);
    }

    public function handleReady(ServiceReady $event)
    {
        $this->notifyClient($event->service->user, [
            'title'   => 'Servicio Listo',
            'message' => $event->broadcastWith()['message'],
            'type'    => 'service_ready',
            'data'    => $event->broadcastWith(),
        ]);
    }

    public function handleMaintenanceScheduled(ServiceMaintenanceScheduled $event)
    {
        $this->notifyClient($event->service->user, [
            'title'   => 'Mantenimiento Programado',
            'message' => $event->broadcastWith()['message'],
            'type'    => 'service_maintenance_scheduled',
            'data'    => $event->broadcastWith(),
        ]);
    }

    public function handleMaintenanceCompleted(ServiceMaintenanceCompleted $event)
    {
        $this->notifyClient($event->service->user, [
            'title'   => 'Mantenimiento Completado',
            'message' => $event->broadcastWith()['message'],
            'type'    => 'service_maintenance_completed',
            'data'    => $event->broadcastWith(),
        ]);
    }

    private function notifyClient(User $user, array $data): void
    {
        $user->notify(new ServiceNotification(array_merge($data, [
            'target'   => 'client',
            '_channel' => 'user.' . $user->uuid,
        ])));
    }

    private function notifyAdmins(array $data): void
    {
        User::where('role', 'admin')->orWhere('role', 'super_admin')->get()
            ->each(fn ($admin) => $admin->notify(new ServiceNotification(array_merge($data, [
                'target'   => 'admin',
                '_channel' => 'admin.notifications',
            ]))));
    }
}
