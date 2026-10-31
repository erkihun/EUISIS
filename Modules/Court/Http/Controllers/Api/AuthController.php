<?php

namespace Modules\Court\Http\Controllers\Api;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Support\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'guard' => 'required|string|in:admin,applicant,respondent',
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:255',
        ]);

        $throttle = LoginThrottle::for('api:'.$data['guard'], $data['email'], $request->ip());
        if ($throttle->tooManyAttempts()) {
            $seconds = $throttle->availableIn();

            throw ValidationException::withMessages([
                'email' => [trans('court::auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)])],
            ])->status(429);
        }

        $user = $this->findUserForGuard($data['guard'], $data['email']);

        if (!$user || !Hash::check($data['password'], $user->password)) {
            $throttle->hit();

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $throttle->clear();

        $this->assertActive($user);

        // The audit middleware runs after the response. Preserve the verified
        // API actor so a successful token login is not attributed to a guest.
        $request->attributes->set('audit_actor', $user);

        $token = $user->createToken(
            $data['device_name'] ?: ($request->userAgent() ?: 'api-client'),
            $this->abilitiesFor($user)
        );

        return response()->json([
            'ok' => true,
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $token->accessToken->abilities ?? [],
            'type' => $this->actorType($user),
            'user' => $this->transformUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'ok' => true,
            'type' => $this->actorType($user),
            'user' => $this->transformUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }

    private function findUserForGuard(string $guard, string $email): User|Applicant|Respondent|null
    {
        return match ($guard) {
            'admin' => User::where('email', $email)->first(),
            'applicant' => Applicant::where('email', $email)->first(),
            'respondent' => Respondent::where('email', $email)->first(),
        };
    }

    private function assertActive(User|Applicant|Respondent $user): void
    {
        if ($user instanceof User && $user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        if ($user instanceof Applicant && !$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your applicant account has been deactivated.'],
            ]);
        }
    }

    private function abilitiesFor(User|Applicant|Respondent $user): array
    {
        if ($user instanceof User) {
            return ['*'];
        }

        if ($user instanceof Applicant) {
            return ['cases:read', 'cases:create', 'profile'];
        }

        return ['cases:read', 'profile'];
    }

    private function actorType(User|Applicant|Respondent $user): string
    {
        return match (true) {
            $user instanceof Applicant => 'applicant',
            $user instanceof Respondent => 'respondent',
            default => 'admin',
        };
    }

    private function transformUser(User|Applicant|Respondent $user): array
    {
        if ($user instanceof Applicant) {
            return [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => (bool) $user->is_active,
                'email_verified' => (bool) $user->email_verified_at,
            ];
        }

        if ($user instanceof Respondent) {
            return [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'email_verified' => (bool) $user->email_verified_at,
            ];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'roles' => $user->roles()->pluck('name')->all(),
        ];
    }
}
