<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\ActivationEvent;
use App\Models\RokePet\AppAdmin;
use App\Models\RokePet\Owner;
use App\Models\RokePet\OwnerSubscription;
use App\Models\RokePet\Pet;
use App\Models\RokePet\SentReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $isAdmin = AppAdmin::where('user_id', $request->user()->uuid)->exists();
        return response()->json(['isAdmin' => $isAdmin]);
    }

    public function overview(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $since30 = now()->subDays(30);

        $owners        = Owner::orderBy('created_at', 'desc')->limit(20)->get();
        $allPets       = Pet::select('id', 'owner_id', 'scanned_count')->get();
        $subscriptions = OwnerSubscription::orderBy('updated_at', 'desc')->get();
        $events        = ActivationEvent::orderBy('occurred_at', 'desc')->limit(20)->get();
        $sentCount     = \App\Models\RokePet\SentReminder::where('sent_at', '>=', $since30)->count();

        $scansLast30 = ActivationEvent::where('event_type', 'pet_scan_recorded')
            ->where('occurred_at', '>=', $since30)
            ->count();

        $customers = $owners->map(function ($owner) use ($allPets, $subscriptions) {
            $pets = $allPets->where('owner_id', $owner->id);
            $sub  = $subscriptions->firstWhere('owner_id', $owner->id);

            return [
                'ownerId'           => $owner->id,
                'displayName'       => $owner->display_name ?? 'Sin nombre',
                'email'             => $owner->email ?? '',
                'phone'             => $owner->phone ?? '',
                'petsCount'         => $pets->count(),
                'scansCount'        => $pets->sum('scanned_count'),
                'subscriptionStatus'=> $sub?->status ?? 'trialing',
                'trialEndsAt'       => $sub?->trial_ends_at,
                'currentPeriodEnd'  => $sub?->current_period_end,
                'updatedAt'         => $sub?->updated_at ?? $owner->updated_at,
            ];
        });

        return response()->json([
            'totals' => [
                'owners'              => Owner::count(),
                'pets'                => $allPets->count(),
                'activeSubscriptions' => $subscriptions->whereIn('status', ['active'])->count(),
                'scansLast30Days'     => $scansLast30,
                'reminderEmailsSent'  => $sentCount,
            ],
            'customers'     => $customers,
            'recentEvents'  => $events->map(fn($e) => [
                'id'         => $e->id,
                'ownerId'    => $e->owner_id,
                'petId'      => $e->pet_id,
                'eventType'  => $e->event_type,
                'source'     => $e->source,
                'occurredAt' => $e->occurred_at,
                'metadata'   => $e->metadata ?? [],
            ]),
        ]);
    }

    private function requireAdmin(Request $request): void
    {
        if (!AppAdmin::where('user_id', $request->user()->uuid)->exists()) {
            abort(403, 'Acceso denegado');
        }
    }
}
