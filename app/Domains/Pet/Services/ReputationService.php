<?php

namespace App\Domains\Pet\Services;

use App\Domains\Pet\Models\AdoptionFollowup;
use App\Domains\Pet\Models\AdoptionListing;
use App\Domains\Pet\Models\AdoptionReview;
use App\Domains\Pet\Models\Owner;

/**
 * Recalcula los agregados de reputación denormalizados en `owners` a partir
 * de las reseñas, adopciones completadas y seguimientos. Se llama tras cada
 * reseña nueva, seguimiento entregado o adopción completada, de modo que el
 * ranking de candidatos lea valores ya calculados (sin agregaciones en vivo).
 */
class ReputationService
{
    /** Recalcula y persiste la reputación de un dueño. */
    public function recompute(string $ownerId): void
    {
        $owner = Owner::find($ownerId);
        if (!$owner) {
            return;
        }

        // Reseñas recibidas como adoptante (las ocultas por moderación no cuentan).
        $asAdopter = AdoptionReview::where('reviewee_owner_id', $ownerId)
            ->where('role', 'adopter')
            ->where('moderation_status', '!=', 'hidden');
        $adopterCount = (clone $asAdopter)->count();
        $adopterAvg   = $adopterCount > 0 ? round((clone $asAdopter)->avg('rating'), 2) : null;

        // Reseñas recibidas como rescatista.
        $asRescuer = AdoptionReview::where('reviewee_owner_id', $ownerId)
            ->where('role', 'rescuer')
            ->where('moderation_status', '!=', 'hidden');
        $rescuerCount = (clone $asRescuer)->count();
        $rescuerAvg   = $rescuerCount > 0 ? round((clone $asRescuer)->avg('rating'), 2) : null;

        // Adopciones completadas en las que este dueño fue el adoptante.
        $adoptions = AdoptionListing::where('adopted_by_owner_id', $ownerId)
            ->where('status', 'adopted')
            ->count();

        // Seguimientos: entregados / (entregados + vencidos sin entregar).
        // Un seguimiento aún no vencido NO penaliza (le damos tiempo al adoptante).
        $followupsSubmitted = AdoptionFollowup::where('adopter_owner_id', $ownerId)
            ->where('status', 'submitted')
            ->count();
        $followupsOverdue = AdoptionFollowup::where('adopter_owner_id', $ownerId)
            ->where('status', 'requested')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
        $followupsConsidered = $followupsSubmitted + $followupsOverdue;
        $followupsRatio = $followupsConsidered > 0
            ? round($followupsSubmitted / $followupsConsidered, 3)
            : null;

        $owner->forceFill([
            'adopter_rating_avg'      => $adopterAvg,
            'adopter_rating_count'    => $adopterCount,
            'adopter_adoptions_count' => $adoptions,
            'adopter_followups_ratio' => $followupsRatio,
            'rescuer_rating_avg'      => $rescuerAvg,
            'rescuer_rating_count'    => $rescuerCount,
        ])->save();
    }

    /**
     * Resumen de reputación listo para la API (badges / perfil público).
     *
     * @return array<string,mixed>
     */
    public function summaryFor(Owner $owner): array
    {
        return [
            'ownerId'           => $owner->id,
            'name'              => $owner->display_name ?? 'Dueño',
            'adopter' => [
                'ratingAvg'      => $owner->adopter_rating_avg,
                'ratingCount'    => $owner->adopter_rating_count ?? 0,
                'adoptionsCount' => $owner->adopter_adoptions_count ?? 0,
                'followupsRatio' => $owner->adopter_followups_ratio,
            ],
            'rescuer' => [
                'ratingAvg'   => $owner->rescuer_rating_avg,
                'ratingCount' => $owner->rescuer_rating_count ?? 0,
            ],
        ];
    }
}
