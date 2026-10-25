<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ChangePassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CoreSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for the Core frontend: e-mail and password give a personal token
 * (Sanctum, hashed at rest). Its lifetime, the wrong-password allowance per
 * e-mail and address, and the lockout come from Core settings › Security.
 */
class AuthController extends Controller
{
    public function __construct(private readonly CoreSettings $settings) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = 'core-login:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, $this->settings->int('security.max_login_attempts'))) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['email' => "Too many attempts. Try again in {$minutes} minute(s)."]);
        }

        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, $this->settings->int('security.lockout_minutes') * 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active Core account.']);
        }
        RateLimiter::clear($key);

        $token = $user->createToken('core-frontend', ['*'], now()->addMinutes($this->settings->int('security.session_timeout_minutes')));
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['token' => $token->plainTextToken, 'user' => $this->profile($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    /** The signed-in person's own password; this sign-in stays, every other one ends. */
    public function changePassword(Request $request, ChangePassword $change): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'confirmed']]);
        $user = $request->user();
        $change->execute($user, $data['current_password'], $data['password'], $user->currentAccessToken()?->getKey());

        return response()->json(['user' => $this->profile($user->fresh())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['signed_out' => true]);
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'scope_type' => $user->scope_type,
            'scope_organization_id' => $user->scope_organization_id,
            'can_change_master_data' => $user->canChangeMasterData(),
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }
}
