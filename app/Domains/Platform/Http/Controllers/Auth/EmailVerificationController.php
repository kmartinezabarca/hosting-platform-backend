<?php

namespace App\Domains\Platform\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function sendNotification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'El correo ya está verificado.',
                'data' => ['already_verified' => true],
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Correo de verificación enviado.',
            'data' => ['sent' => true],
        ]);
    }

    public function verify(Request $request, string $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403, 'El enlace de verificación no es válido.');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Correo verificado correctamente.',
                'data' => ['verified' => true],
            ]);
        }

        return redirect()->away(
            rtrim(config('app.frontend_url'), '/') . '/profile?email_verified=1'
        );
    }
}
