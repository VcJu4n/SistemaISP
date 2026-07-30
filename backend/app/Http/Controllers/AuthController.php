<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PasswordHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $rateLimitKey = hash('sha256', strtolower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return $this->lockedResponse($rateLimitKey);
        }

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($rateLimitKey, 15 * 60);

            if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
                return $this->lockedResponse($rateLimitKey);
            }

            throw ValidationException::withMessages([
                'email' => ['Correo o contraseña incorrectos'],
            ]);
        }

        RateLimiter::clear($rateLimitKey);
        $user->tokens()->delete();

        return response()->json([
            'data' => [
                'user' => $user,
                'token' => $user->createToken(
                    $credentials['device_name'] ?? 'sistemaisp-web',
                    ['*'],
                    now()->addDay(),
                )->plainTextToken,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                'different:current_password',
                Password::min(8)->numbers(),
                'regex:/[A-Z]/',
            ],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual es incorrecta.'],
            ]);
        }

        $recentHashes = collect([$user->password])
            ->merge(
                PasswordHistory::query()
                    ->where('user_id', $user->id)
                    ->latest('id')
                    ->limit(2)
                    ->pluck('password'),
            );

        if ($recentHashes->contains(fn (string $hash) => Hash::check($data['password'], $hash))) {
            throw ValidationException::withMessages([
                'password' => ['No puedes reutilizar ninguna de tus últimas 3 contraseñas.'],
            ]);
        }

        DB::transaction(function () use ($user, $data): void {
            PasswordHistory::query()->create([
                'user_id' => $user->id,
                'password' => $user->password,
            ]);

            $user->update(['password' => $data['password']]);
            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'Contraseña actualizada. Inicia sesión nuevamente.',
        ]);
    }

    private function lockedResponse(string $rateLimitKey): JsonResponse
    {
        $seconds = RateLimiter::availableIn($rateLimitKey);
        $message = 'Demasiados intentos fallidos. Intenta nuevamente en 15 minutos.';

        return response()->json([
            'message' => $message,
            'errors' => ['email' => [$message]],
            'retry_after' => $seconds,
        ], 429)->header('Retry-After', (string) $seconds);
    }
}
