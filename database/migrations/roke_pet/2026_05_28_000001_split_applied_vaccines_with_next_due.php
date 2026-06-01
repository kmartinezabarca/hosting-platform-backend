<?php

use App\Domains\Pet\Models\Vaccine;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill Opción B: separa el estado ambiguo "aplicada + next_due".
 *
 * Antes una misma fila podía estar `status='applied'` Y tener `next_due`, lo que
 * la hacía aparecer como "vacuna pendiente" en recordatorios/Home pero sin acción
 * de aplicar en el panel (ya estaba aplicada). Ahora, una fila = una dosis:
 *   - La fila aplicada conserva su historial pero se le limpia next_due.
 *   - Se crea una fila 'pending' independiente para esa próxima dosis (accionable).
 *
 * Idempotente: si ya existe una fila pendiente equivalente (mismo pet/name/fecha),
 * no se duplica.
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Vaccine::query()
            ->where('status', 'applied')
            ->whereNotNull('next_due')
            ->orderBy('id')
            ->chunkById(200, function ($vaccines) {
                foreach ($vaccines as $vaccine) {
                    $nextDue = $vaccine->next_due;

                    $alreadyScheduled = Vaccine::query()
                        ->where('pet_id', $vaccine->pet_id)
                        ->where('name', $vaccine->name)
                        ->where('next_due', $nextDue)
                        ->where('status', '!=', 'applied')
                        ->exists();

                    if (! $alreadyScheduled) {
                        Vaccine::create([
                            'pet_id'   => $vaccine->pet_id,
                            'name'     => $vaccine->name,
                            'name_en'  => $vaccine->name_en,
                            'next_due' => $nextDue,
                            'status'   => 'pending',
                        ]);
                    }

                    // La dosis ya aplicada deja de cargar un next_due accionable.
                    $vaccine->forceFill(['next_due' => null])->save();
                }
            });
    }

    /**
     * No se revierte automáticamente: re-fusionar fechas en la fila aplicada
     * reintroduciría el estado ambiguo que esta migración corrige.
     */
    public function down(): void
    {
        // intencionalmente vacío
    }
};
