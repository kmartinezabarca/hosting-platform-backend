<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Mail\PetWelcomeMail;
use App\Domains\Pet\Models\AppAdmin;
use App\Domains\Pet\Models\ActivationEvent;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Models\OwnerSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'        => 'required|email|unique:users,email',
            'password'     => 'required|min:8',
            'display_name' => 'required|string|max:255',
        ]);

        $nameParts = explode(' ', $data['display_name'], 2);
        $user = User::create([
            'first_name' => $nameParts[0],
            'last_name'  => $nameParts[1] ?? '',
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'role'       => 'client',
            'status'     => 'active',
            'kind'       => 'pet',
        ]);

        Owner::create([
            'id'           => $user->uuid,
            'display_name' => $data['display_name'],
            'email'        => $data['email'],
        ]);

        OwnerSubscription::create([
            'owner_id'      => $user->uuid,
            'billing_email' => $data['email'],
            'trial_ends_at' => now()->addDays(config('services.rokepet.trial_days', 14)),
        ]);

        ActivationEvent::create([
            'owner_id'    => $user->uuid,
            'event_type'  => 'owner_registered',
            'source'      => 'system',
            'metadata'    => ['email' => $data['email']],
            'occurred_at' => now(),
        ]);

        $this->sendWelcomeEmail($data['email'], $data['display_name']);

        $token = $user->createToken('roke-pet')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'          => $user->uuid,
                'email'       => $user->email,
                'displayName' => $data['display_name'],
                'isAdmin'     => false,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || empty($user->password) || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        if (in_array($user->status, ['suspended', 'banned'])) {
            return response()->json(['error' => 'Cuenta suspendida. Contacta a soporte.'], 403);
        }

        // Upgrade kind if first time accessing Pet
        if ($user->kind === 'platform') {
            $user->update(['kind' => 'both']);
        }

        $user->update(['last_login_at' => now()]);

        $owner   = Owner::find($user->uuid);
        $isAdmin = AppAdmin::where('user_id', $user->uuid)->exists();
        $token   = $user->createToken('roke-pet')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'          => $user->uuid,
                'email'       => $user->email,
                'displayName' => $owner?->display_name ?? trim("{$user->first_name} {$user->last_name}"),
                'isAdmin'     => $isAdmin,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['ok' => true]);
    }

    // ── Google OAuth ─────────────────────────────────────────────────────────

    public function handleGoogleCallback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'      => 'required|email|max:255',
            'google_id'  => 'required|string',
            'first_name' => 'required|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'avatar_url' => 'nullable|url|max:2048',
        ]);

        // Find existing user by email OR google_id (account linking)
        $user = User::where('email', $data['email'])
                    ->orWhere('google_id', $data['google_id'])
                    ->first();

        if (!$user) {
            $user = User::create([
                'first_name'        => $data['first_name'],
                'last_name'         => $data['last_name'] ?? '',
                'email'             => $data['email'],
                'google_id'         => $data['google_id'],
                'avatar_url'        => $data['avatar_url'] ?? null,
                'password'          => null,
                'role'              => 'client',
                'status'            => 'active',
                'kind'              => 'pet',
                'email_verified_at' => now(),
            ]);
        } else {
            $updates = [
                'google_id'         => $data['google_id'],
                'last_login_at'     => now(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ];
            if (empty($user->avatar_url) && !empty($data['avatar_url'])) {
                $updates['avatar_url'] = $data['avatar_url'];
            }
            if ($user->kind === 'platform') {
                $updates['kind'] = 'both';
            }
            $user->update($updates);
            $user = $user->fresh();
        }

        if (in_array($user->status, ['suspended', 'banned'])) {
            return response()->json(['error' => 'Cuenta suspendida. Contacta a soporte.'], 403);
        }

        $displayName = trim("{$data['first_name']} {$data['last_name']}");

        $owner = Owner::firstOrCreate(
            ['id' => $user->uuid],
            ['display_name' => $displayName, 'email' => $user->email]
        );

        // Bienvenida solo en el primer registro (no en cada login con Google).
        if ($owner->wasRecentlyCreated) {
            $this->sendWelcomeEmail($user->email, $displayName);
        }

        OwnerSubscription::firstOrCreate(
            ['owner_id' => $user->uuid],
            [
                'billing_email' => $user->email,
                'trial_ends_at' => now()->addDays(config('services.rokepet.trial_days', 14)),
            ]
        );

        ActivationEvent::firstOrCreate(
            ['owner_id' => $user->uuid, 'event_type' => 'owner_registered'],
            ['source' => 'google', 'metadata' => ['email' => $user->email], 'occurred_at' => now()]
        );

        $isAdmin = AppAdmin::where('user_id', $user->uuid)->exists();
        $token   = $user->createToken('roke-pet-google')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'          => $user->uuid,
                'email'       => $user->email,
                'displayName' => Owner::find($user->uuid)?->display_name ?? $displayName,
                'isAdmin'     => $isAdmin,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user    = $request->user();
        $owner   = Owner::find($user->uuid);
        $isAdmin = AppAdmin::where('user_id', $user->uuid)->exists();

        return response()->json([
            'id'          => $user->uuid,
            'email'       => $user->email,
            'displayName' => $owner?->display_name ?? trim("{$user->first_name} {$user->last_name}"),
            'isAdmin'     => $isAdmin,
        ]);
    }

    /**
     * Envía el correo de bienvenida (best-effort). Se encola si hay worker; con
     * QUEUE_CONNECTION=sync corre inline. Nunca rompe el registro si el mail falla.
     */
    private function sendWelcomeEmail(string $email, ?string $name): void
    {
        try {
            Mail::to($email)->queue(new PetWelcomeMail($name));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Welcome email falló: ' . $e->getMessage());
        }
    }
}
