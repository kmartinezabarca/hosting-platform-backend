<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\MedicalRecord;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Models\Pet;
use App\Domains\Pet\Models\Vaccine;
use App\Domains\Pet\Models\VetLink;
use App\Domains\Pet\Models\WeightHistory;
use App\Domains\Pet\Services\PushNotificationService;
use App\Domains\Pet\Support\GatesPlanFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class VetLinkController extends Controller
{
    use GatesPlanFeatures;

    public function index(Request $request, string $petId): JsonResponse
    {
        $pet   = Pet::where('id', $petId)->where('owner_id', $request->user()->uuid)->firstOrFail();
        $links = VetLink::where('pet_id', $pet->id)->orderByDesc('created_at')
            ->get()->map(fn($l) => $this->formatLink($l));
        return response()->json($links);
    }

    public function revoke(Request $request, string $petId, string $linkId): JsonResponse
    {
        $pet  = Pet::where('id', $petId)->where('owner_id', $request->user()->uuid)->firstOrFail();
        $link = VetLink::where('id', $linkId)->where('pet_id', $pet->id)->firstOrFail();
        $link->update(['expires_at' => now()->subSecond()]);
        return response()->json(['ok' => true]);
    }

    public function generate(Request $request, string $petId): JsonResponse
    {
        if ($r = $this->requirePlanFeature($request->user()->uuid, 'vet_links')) return $r;

        $pet  = Pet::where('id', $petId)->where('owner_id', $request->user()->uuid)->firstOrFail();
        $data = $request->validate([
            'expiresInHours'  => 'sometimes|integer|min:1|max:720',
            'allowAddRecords' => 'sometimes|boolean',
            // PIN opcional: 4 a 6 dígitos. Se guarda HASHEADO, nunca en claro.
            'accessCode'      => 'sometimes|nullable|string|regex:/^\d{4,6}$/',
        ]);

        $link = VetLink::create([
            'pet_id'            => $pet->id,
            'owner_id'          => $request->user()->uuid,
            'token'             => Str::random(32),
            'expires_at'        => now()->addHours($data['expiresInHours'] ?? 72),
            'allow_add_records' => $data['allowAddRecords'] ?? true,
            'access_code'       => ! empty($data['accessCode']) ? Hash::make($data['accessCode']) : null,
        ]);

        return response()->json($this->formatLink($link), 201);
    }

    public function portal(string $token, Request $request): JsonResponse
    {
        $link = VetLink::where('token', $token)->firstOrFail();

        if ($link->isExpired()) {
            return $this->vetJson(['error' => 'Link expirado'], 410);
        }

        if ($resp = $this->gateCode($link, $request)) {
            return $resp;
        }

        $pet   = $link->pet()->with(['vaccines', 'medicalRecords'])->firstOrFail();
        $owner = Owner::find($pet->owner_id);

        // Aviso al dueño cuando se ABRE su expediente (sin spamear: una vez por
        // "sesión" de ~6 h). Permite detectar accesos no esperados y revocar.
        $shouldNotify = is_null($link->last_viewed_at) || $link->last_viewed_at->lt(now()->subHours(6));
        $link->forceFill([
            'view_count'     => $link->view_count + 1,
            'last_viewed_at' => now(),
        ])->save();

        if ($shouldNotify) {
            $this->notifyOwnerOpened($link, $pet);
        }

        return $this->vetJson([
            'link' => [
                'id'             => $link->id,
                'petId'          => $link->pet_id,
                'token'          => $link->token,
                'expiresAt'      => $link->expires_at,
                'createdAt'      => $link->created_at,
                'createdBy'      => $link->owner_id,
                'allowAddRecords'=> $link->allow_add_records,
                'viewCount'      => $link->view_count,
            ],
            'pet' => (new PetController())->format($pet, $owner),
        ]);
    }

    public function weight(string $token, Request $request): JsonResponse
    {
        $link = VetLink::where('token', $token)->firstOrFail();

        if ($link->isExpired()) {
            return $this->vetJson(['error' => 'Link expirado'], 410);
        }

        if ($resp = $this->gateCode($link, $request)) {
            return $resp;
        }

        $entries = WeightHistory::where('pet_id', $link->pet_id)
            ->orderBy('recorded_at', 'desc')
            ->get();

        return $this->vetJson($entries->map(fn($entry) => $this->formatWeight($entry))->values());
    }

    public function addRecord(Request $request, string $token): JsonResponse
    {
        $link = VetLink::where('token', $token)->where('allow_add_records', true)->firstOrFail();
        if ($link->isExpired()) return $this->vetJson(['error' => 'Link expirado'], 410);
        if ($resp = $this->gateCode($link, $request)) return $resp;

        $data = $request->validate([
            'date'          => 'required|date',
            'followUpDate'  => 'nullable|date',
            'type'          => 'required|in:checkup,surgery,treatment,deworming,illness',
            'description'   => 'nullable|string',
            'descriptionEn' => 'nullable|string',
            'vet'           => 'nullable|string|max:255',
            'clinic'        => 'nullable|string|max:255',
            'notes'         => 'nullable|string',
        ]);

        $record = MedicalRecord::create([
            'pet_id'         => $link->pet_id,
            'date'           => $data['date'],
            'follow_up_date' => $data['followUpDate'] ?? null,
            'type'           => $data['type'],
            'description'    => $data['description'] ?? null,
            'description_en' => $data['descriptionEn'] ?? null,
            'vet'            => $data['vet'] ?? null,
            'clinic'         => $data['clinic'] ?? null,
            'notes'          => $data['notes'] ?? null,
        ]);

        $pet = Pet::find($link->pet_id);
        dispatch_after_response(function () use ($link, $pet, $data) {
            $vetName = $data['vet'] ?? null;
            $base    = $pet ? "El veterinario añadió un registro para {$pet->name}" : 'El veterinario añadió un registro médico';
            (new PushNotificationService())->sendToOwner(
                $link->owner_id,
                'Nuevo registro médico',
                $base . ($vetName ? " — {$vetName}" : ''),
                ['url' => '/dashboard/' . $link->pet_id],
            );
        });

        return $this->vetJson(MedicalRecordController::formatRecord($record), 201);
    }

    public function addVaccine(Request $request, string $token): JsonResponse
    {
        $link = VetLink::where('token', $token)->where('allow_add_records', true)->firstOrFail();
        if ($link->isExpired()) return $this->vetJson(['error' => 'Link expirado'], 410);
        if ($resp = $this->gateCode($link, $request)) return $resp;

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'nameEn'      => 'nullable|string',
            'date'        => 'nullable|date',
            'nextDue'     => 'nullable|date',
            'appliedBy'   => 'nullable|string',
            'batchNumber' => 'nullable|string',
            'status'      => 'required|in:applied,pending,overdue',
        ]);

        // Invariante Opción B: si el vet registra una vacuna aplicada con próxima
        // dosis, se crea la fila aplicada (sin next_due) + una fila 'pending' aparte.
        $vaccine = VaccineController::createWithSchedule($link->pet_id, [
            'name'         => $data['name'],
            'name_en'      => $data['nameEn'] ?? null,
            'date'         => $data['date'] ?? null,
            'next_due'     => $data['nextDue'] ?? null,
            'applied_by'   => $data['appliedBy'] ?? null,
            'batch_number' => $data['batchNumber'] ?? null,
            'status'       => $data['status'],
        ]);

        $pet = Pet::find($link->pet_id);
        dispatch_after_response(function () use ($link, $pet, $data) {
            $appliedBy = $data['appliedBy'] ?? null;
            $base      = $pet ? "Se registró la vacuna {$data['name']} para {$pet->name}" : "Se registró la vacuna {$data['name']}";
            (new PushNotificationService())->sendToOwner(
                $link->owner_id,
                'Vacuna registrada',
                $base . ($appliedBy ? " — {$appliedBy}" : ''),
                ['url' => '/dashboard/' . $link->pet_id],
            );
        });

        return $this->vetJson(VaccineController::formatVaccine($vaccine), 201);
    }

    private function formatLink(VetLink $link): array
    {
        return [
            'id'             => $link->id,
            'petId'          => $link->pet_id,
            'token'          => $link->token,
            'expiresAt'      => $link->expires_at,
            'allowAddRecords'=> $link->allow_add_records,
            'viewCount'      => $link->view_count,
            'expired'        => $link->isExpired(),
            'hasAccessCode'  => $link->hasAccessCode(),
            'lastViewedAt'   => $link->last_viewed_at,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Seguridad del portal veterinario
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Respuesta JSON con headers de hardening: no se filtra el token por Referer
     * y no se indexa el portal en buscadores.
     */
    private function vetJson(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders([
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag'    => 'noindex, nofollow',
            'Cache-Control'   => 'no-store',
        ]);
    }

    /**
     * Verifica el PIN del link (si tiene). Devuelve null si el acceso es válido,
     * o una respuesta 401/423 que el caller debe retornar tal cual.
     *
     * El PIN es un segundo factor: un link filtrado SIN el código no abre nada.
     * Protección anti fuerza bruta: tras MAX_CODE_ATTEMPTS fallos el link se
     * auto-revoca y se avisa al dueño.
     */
    private function gateCode(VetLink $link, Request $request): ?JsonResponse
    {
        if (! $link->hasAccessCode()) {
            return null;
        }

        // Solo por header (nunca query string, para que no quede en logs/historial).
        $code = $request->header('X-Vet-Code');

        if ($link->checkCode($code)) {
            if ($link->code_attempts > 0) {
                $link->forceFill(['code_attempts' => 0])->save();
            }
            return null;
        }

        // Código ausente: pedirlo sin contar como intento fallido.
        if (! is_string($code) || $code === '') {
            return $this->vetJson([
                'requiresCode' => true,
                'message'      => 'Este expediente requiere un código de acceso.',
            ], 401);
        }

        // Código incorrecto: contar intento y, si se supera el tope, revocar.
        $attempts = $link->code_attempts + 1;
        $locked   = $attempts >= VetLink::MAX_CODE_ATTEMPTS;
        $link->forceFill([
            'code_attempts' => $attempts,
            'expires_at'    => $locked ? now()->subSecond() : $link->expires_at,
        ])->save();

        if ($locked) {
            $this->notifyOwnerLocked($link);
            return $this->vetJson([
                'requiresCode' => true,
                'locked'       => true,
                'message'      => 'Demasiados intentos. El link fue bloqueado por seguridad.',
            ], 423);
        }

        return $this->vetJson([
            'requiresCode' => true,
            'message'      => 'Código incorrecto.',
            'attemptsLeft' => max(0, VetLink::MAX_CODE_ATTEMPTS - $attempts),
        ], 401);
    }

    /** Avisa al dueño (push + inbox) que se abrió el expediente de su mascota. */
    private function notifyOwnerOpened(VetLink $link, Pet $pet): void
    {
        $title = '👩‍⚕️ Se abrió el expediente de ' . $pet->name;
        $body  = 'Alguien abrió el link veterinario de ' . $pet->name . '. Si no fuiste tú o tu veterinario, revócalo desde la app.';

        try {
            (new PushNotificationService())->sendToOwner(
                $link->owner_id,
                $title,
                $body,
                ['type' => 'vet_link_opened', 'petId' => $link->pet_id],
            );
        } catch (\Throwable) {
            // best-effort
        }

        try {
            InboxNotification::createForOwner(
                ownerId:   $link->owner_id,
                title:     $title,
                body:      $body,
                notifType: 'vet_link_opened',
                url:       '/dashboard/' . $link->pet_id,
                tag:       'vet-open-' . $link->id,
            );
        } catch (\Throwable) {
            // no fatal
        }
    }

    /** Avisa al dueño que un link fue bloqueado por demasiados intentos de PIN. */
    private function notifyOwnerLocked(VetLink $link): void
    {
        $pet   = Pet::find($link->pet_id);
        $name  = $pet?->name ?? 'tu mascota';
        $title = '🔒 Link veterinario bloqueado';
        $body  = "Se bloqueó un link de {$name} por demasiados intentos de código. Genera uno nuevo si lo necesitas.";

        try {
            (new PushNotificationService())->sendToOwner(
                $link->owner_id,
                $title,
                $body,
                ['type' => 'vet_link_locked', 'petId' => $link->pet_id],
            );
        } catch (\Throwable) {
            // best-effort
        }

        try {
            InboxNotification::createForOwner(
                ownerId:   $link->owner_id,
                title:     $title,
                body:      $body,
                notifType: 'vet_link_locked',
                url:       '/dashboard/' . $link->pet_id,
                tag:       'vet-locked-' . $link->id,
            );
        } catch (\Throwable) {
            // no fatal
        }
    }

    private function formatWeight(WeightHistory $entry): array
    {
        return [
            'id'         => $entry->id,
            'petId'      => $entry->pet_id,
            'weight'     => (float) $entry->weight,
            'recordedAt' => $entry->recorded_at instanceof \Carbon\Carbon
                ? $entry->recorded_at->toDateString()
                : (string) $entry->recorded_at,
            'notes'      => $entry->notes ?? '',
            'createdAt'  => $entry->created_at,
        ];
    }
}
