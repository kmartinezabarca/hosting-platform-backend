<?php

namespace App\Domains\Platform\Compute\Http\Controllers\V2;

use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Compute\Plans\PlanLimits;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Miembros de un equipo (mes 3, "team plans"). Gestionar miembros requiere rol
 * admin+ (policy manageMembers). Reglas duras:
 *
 * - El equipo personal no admite miembros.
 * - El owner es intocable: no se invita como owner, ni se cambia su rol, ni se
 *   le expulsa (el owner se gestiona por transferencia de equipo, no aquí).
 * - Roles asignables: admin/developer/billing/viewer (owner queda fuera).
 * - El cupo de miembros lo fija el plan del equipo (PlanLimits).
 */
class TeamMemberController extends Controller
{
    /** Roles que se pueden asignar vía esta API (owner se excluye a propósito). */
    private const ASSIGNABLE_ROLES = ['admin', 'developer', 'billing', 'viewer'];

    /**
     * GET /api/v2/teams/{team}/members
     */
    public function index(Request $request, Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        return response()->json([
            'success' => true,
            'data'    => $team->members()->orderBy('team_members.created_at')->get()
                ->map(fn (User $m) => $this->transform($team, $m)),
        ]);
    }

    /**
     * POST /api/v2/teams/{team}/members — agrega un usuario existente por correo.
     */
    public function store(Request $request, Team $team, PlanLimits $planLimits): JsonResponse
    {
        $this->authorize('manageMembers', $team);

        abort_if($team->is_personal, 422, 'El equipo personal no admite miembros.');

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role'  => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        if ($error = $planLimits->checkCanAddMember($team)) {
            abort(422, $error);
        }

        $user = User::where('email', $validated['email'])->first();
        abort_unless($user, 422, 'No existe un usuario registrado con ese correo.');

        if ($team->members()->where('users.id', $user->id)->exists()) {
            abort(422, 'Ese usuario ya es miembro del equipo.');
        }

        $team->members()->attach($user->id, ['role' => $validated['role']]);

        return response()->json([
            'success' => true,
            'data'    => $this->transform($team, $this->memberWithPivot($team, $user->id)),
        ], 201);
    }

    /**
     * PATCH /api/v2/teams/{team}/members/{member} — cambia el rol de un miembro.
     */
    public function update(Request $request, Team $team, User $member): JsonResponse
    {
        $this->authorize('manageMembers', $team);

        $validated = $request->validate([
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        abort_if($this->isOwner($team, $member), 422, 'No puedes cambiar el rol del owner del equipo.');
        abort_unless($team->members()->where('users.id', $member->id)->exists(), 404, 'Ese usuario no es miembro del equipo.');

        $team->members()->updateExistingPivot($member->id, ['role' => $validated['role']]);

        return response()->json([
            'success' => true,
            'data'    => $this->transform($team, $this->memberWithPivot($team, $member->id)),
        ]);
    }

    /**
     * DELETE /api/v2/teams/{team}/members/{member}
     */
    public function destroy(Request $request, Team $team, User $member): JsonResponse
    {
        $this->authorize('manageMembers', $team);

        abort_if($this->isOwner($team, $member), 422, 'No puedes quitar al owner del equipo.');
        abort_unless($team->members()->where('users.id', $member->id)->exists(), 404, 'Ese usuario no es miembro del equipo.');

        $team->members()->detach($member->id);

        return response()->json(['success' => true]);
    }

    private function isOwner(Team $team, User $user): bool
    {
        return (int) $team->owner_user_id === (int) $user->id;
    }

    /** Recarga el miembro a través de la relación para traer el pivot (rol + fecha). */
    private function memberWithPivot(Team $team, int $userId): User
    {
        return $team->members()->where('users.id', $userId)->firstOrFail();
    }

    private function transform(Team $team, User $member): array
    {
        $role = $this->isOwner($team, $member)
            ? 'owner'
            : $member->pivot?->role ?? $team->roleFor($member)?->value;

        return [
            'uuid'      => $member->uuid,
            'name'      => trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? '')) ?: $member->email,
            'email'     => $member->email,
            'role'      => $role,
            'is_owner'  => $this->isOwner($team, $member),
            'joined_at' => $member->pivot?->created_at,
        ];
    }
}
