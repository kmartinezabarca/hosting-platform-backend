<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\AppAdmin;
use App\Models\RokePet\ActivationEvent;
use App\Models\RokePet\Owner;
use App\Models\RokePet\OwnerSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'        => 'required|email|unique:users,email',
            'password'     => 'required|min:8',
            'display_name' => 'required|string|max:255',
        ]);

        $user = User::create([
            'name'     => $data['display_name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Crear perfil owner en roke_pet
        $owner = Owner::create([
            'id'           => $user->uuid,
            'display_name' => $data['display_name'],
            'email'        => $data['email'],
        ]);

        // Crear subscription en trial
        OwnerSubscription::create([
            'owner_id'      => $user->uuid,
            'billing_email' => $data['email'],
            'trial_ends_at' => now()->addDays(14),
        ]);

        ActivationEvent::create([
            'owner_id'   => $user->uuid,
            'event_type' => 'owner_registered',
            'source'     => 'system',
            'metadata'   => ['email' => $data['email']],
            'occurred_at'=> now(),
        ]);

        $token = $user->createToken('roke-pet')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'          => $user->id,
                'email'       => $user->email,
                'displayName' => $owner->display_name,
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

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        $owner   = Owner::find($user->uuid);
        $isAdmin = AppAdmin::where('user_id', $user->uuid)->exists();
        $token   = $user->createToken('roke-pet')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'          => $user->uuid,
                'email'       => $user->email,
                'displayName' => $owner?->display_name ?? $user->name,
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

    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function handleGoogleCallback(): RedirectResponse
    {
        $frontendUrl = config('services.rokepet.frontend_url', 'https://roke.pet');

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception) {
            return redirect("{$frontendUrl}/login?error=google_failed");
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if (!$user) {
            $user = User::create([
                'name'               => $googleUser->getName() ?? $googleUser->getNickname() ?? 'Usuario',
                'email'              => $googleUser->getEmail(),
                'password'           => Hash::make(Str::random(32)),
                'status'             => 'active',
                'email_verified_at'  => now(),
            ]);
        }

        // Crear owner en roke_pet si no existe
        Owner::firstOrCreate(
            ['id' => $user->uuid],
            [
                'display_name' => $googleUser->getName() ?? $user->name,
                'email'        => $user->email,
            ]
        );

        // Crear subscription trial si no existe
        OwnerSubscription::firstOrCreate(
            ['owner_id' => $user->uuid],
            [
                'billing_email' => $user->email,
                'trial_ends_at' => now()->addDays(config('services.rokepet.trial_days', 14)),
            ]
        );

        ActivationEvent::firstOrCreate(
            ['owner_id' => $user->uuid, 'event_type' => 'owner_registered'],
            [
                'source'      => 'google',
                'metadata'    => ['email' => $user->email],
                'occurred_at' => now(),
            ]
        );

        $isAdmin = AppAdmin::where('user_id', $user->uuid)->exists();
        $token   = $user->createToken('roke-pet-google')->plainTextToken;

        $userPayload = urlencode(json_encode([
            'id'          => $user->uuid,
            'email'       => $user->email,
            'displayName' => Owner::find($user->uuid)?->display_name ?? $user->name,
            'isAdmin'     => $isAdmin,
        ]));

        return redirect("{$frontendUrl}/auth/callback?token={$token}&user={$userPayload}");
    }

    public function me(Request $request): JsonResponse
    {
        $user    = $request->user();
        $owner   = Owner::find($user->uuid);
        $isAdmin = AppAdmin::where('user_id', $user->uuid)->exists();

        return response()->json([
            'id'          => $user->uuid,
            'email'       => $user->email,
            'displayName' => $owner?->display_name ?? $user->name,
            'isAdmin'     => $isAdmin,
        ]);
    }
}
