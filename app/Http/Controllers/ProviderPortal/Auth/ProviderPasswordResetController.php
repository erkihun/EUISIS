<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Auth;

use App\Actions\Audit\WriteAuditLogAction;
use App\Contracts\SmsGateway;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\ProviderPasswordResetCode;
use App\Models\ProviderUser;
use App\Notifications\ProviderPasswordResetCodeNotification;
use App\Security\LoginThrottle;
use App\Security\Passwords\PasswordLifecycle;
use App\Security\Passwords\PasswordPolicy;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Self-service password reset for provider-portal accounts.
 *
 *  1. The user gives the email or phone number on their account; a six-digit
 *     code goes to that address or number. The answer is the same whether or
 *     not an account matched, so the form cannot discover accounts.
 *  2. The code (hashed, expires, limited attempts) plus a new password
 *     replaces the password. As in NewPasswordController, only
 *     account-independent password rules run before the code is verified;
 *     personal-data and reuse checks run after, so they are never an oracle.
 */
class ProviderPasswordResetController extends Controller
{
    private const SESSION_KEY = 'provider_password_reset';

    private const CHANNEL_EMAIL = 'email';

    private const CHANNEL_SMS = 'sms';

    public function __construct(
        private readonly PasswordPolicy $policy,
        private readonly PasswordLifecycle $lifecycle,
        private readonly SmsGateway $sms,
        private readonly SystemSettingsService $settings,
        private readonly LoginThrottle $loginThrottle,
    ) {}

    public function create(Request $request): Response
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        return Inertia::render('ProviderPortal/Auth/ForgotPassword', [
            'pending' => is_array($pending) ? [
                'identifier' => $pending['identifier'],
                'channel' => $pending['channel'],
                'resend_in' => max(0, $pending['sent_at'] + ProviderPasswordResetCode::RESEND_SECONDS - now()->getTimestamp()),
            ] : null,
            'smsAvailable' => $this->sms->isConfigured(),
            'codeTtlMinutes' => ProviderPasswordResetCode::TTL_MINUTES,
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $identifier = trim((string) $validated['identifier']);
        [$channel, $account] = $this->resolve($identifier);

        if ($account !== null && $account->canLogin() && ! $this->sentRecently($account)) {
            $this->issueCode($request, $account, $channel);
        }

        $request->session()->put(self::SESSION_KEY, [
            'identifier' => $identifier,
            'channel' => $channel,
            'provider_user_id' => $account?->getKey(),
            'sent_at' => now()->getTimestamp(),
        ]);

        return redirect()->route('provider.portal.password.request');
    }

    public function reset(Request $request): RedirectResponse
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending)) {
            return redirect()->route('provider.portal.password.request')
                ->withErrors(['identifier' => __('provider-portal.reset_start_again')]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            // Account-independent rules only until the code proves possession.
            'password' => $this->policy->rules(),
        ]);

        $accountId = $pending['provider_user_id'] ?? null;

        if (! is_string($accountId)) {
            throw ValidationException::withMessages(['code' => __('provider-portal.reset_code_invalid')]);
        }

        $result = DB::transaction(function () use ($accountId, $validated, $pending, $request): ProviderUser|string {
            $account = ProviderUser::query()->with('provider')->lockForUpdate()->find($accountId);

            if ($account === null || ! $account->canLogin()) {
                return __('provider-portal.reset_code_invalid');
            }

            $code = ProviderPasswordResetCode::query()
                ->where('provider_user_id', $account->getKey())
                ->whereNull('used_at')
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            // One message for every failure, so a stranger cannot tell an
            // unknown account from a wrong, expired or exhausted code.
            if ($code === null || $code->isExpired() || ! $code->hasAttemptsLeft()) {
                return __('provider-portal.reset_code_invalid');
            }

            $code->increment('attempts');

            if (! Hash::check((string) $validated['code'], $code->otp_hash)) {
                // Returned, not thrown, so the attempt counter commits.
                return __('provider-portal.reset_code_invalid');
            }

            Validator::make(
                ['password' => (string) $validated['password'], 'password_confirmation' => (string) $request->input('password_confirmation')],
                ['password' => $this->policy->rules($account)],
            )->validate();

            ProviderPasswordResetCode::query()
                ->where('provider_user_id', $account->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $this->lifecycle->change(
                $account,
                (string) $validated['password'],
                AuditEventType::PasswordResetCompleted,
                PasswordLifecycle::KIND_RESET,
                reason: 'provider_password_reset_with_'.$pending['channel'].'_code',
            );

            return $account;
        });

        if (is_string($result)) {
            throw ValidationException::withMessages(['code' => $result]);
        }

        // The code proved the account holder: lift the sign-in lockout their
        // forgotten password likely caused (the per-IP limit stays).
        foreach (array_filter([$result->email, $result->username]) as $identifier) {
            $this->loginThrottle->succeeded($request, 'provider', $identifier);
        }

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('provider.portal.login')
            ->with('status', __('provider-portal.reset_complete'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('provider.portal.password.request');
    }

    /**
     * The channel the identifier names and the one account it matches, if any.
     *
     * @return array{0: string, 1: ?ProviderUser}
     */
    private function resolve(string $identifier): array
    {
        if (str_contains($identifier, '@')) {
            if (filter_var($identifier, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['identifier' => __('provider-portal.reset_invalid_identifier')]);
            }

            return [self::CHANNEL_EMAIL, ProviderUser::query()->with('provider')->where('email', $identifier)->first()];
        }

        $phone = $this->normalizePhone($identifier);

        if ($phone === null) {
            throw ValidationException::withMessages(['identifier' => __('provider-portal.reset_invalid_identifier')]);
        }

        // Says nothing about any account: SMS is off for everyone.
        if (! $this->sms->isConfigured()) {
            throw ValidationException::withMessages(['identifier' => __('provider-portal.reset_sms_unavailable')]);
        }

        return [self::CHANNEL_SMS, $this->findByPhone($phone)];
    }

    /**
     * Phone numbers are stored as typed ("0911 000 111", "+251911000111"...),
     * so they are compared normalized. Provider accounts are few; a number
     * shared by several accounts matches none (the user can use email).
     */
    private function findByPhone(string $phone): ?ProviderUser
    {
        $matches = ProviderUser::query()
            ->whereNotNull('phone_number')
            ->get(['id', 'phone_number'])
            ->filter(fn (ProviderUser $user): bool => $this->normalizePhone((string) $user->phone_number) === $phone);

        return $matches->count() === 1
            ? ProviderUser::query()->with('provider')->find($matches->first()->getKey())
            : null;
    }

    /** International form (+2519XXXXXXXX), or null when it is not a phone number. */
    private function normalizePhone(string $value): ?string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim($value)) ?? '';
        $countryCode = '+'.ltrim(trim((string) $this->settings->get('sms', 'sms_default_country_code', '+251')), '+');

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        } elseif (str_starts_with($phone, '0')) {
            $phone = $countryCode.substr($phone, 1);
        } elseif (str_starts_with($phone, ltrim($countryCode, '+'))) {
            $phone = '+'.$phone;
        } elseif (! str_starts_with($phone, '+')) {
            $phone = $countryCode.$phone;
        }

        return preg_match('/^\+\d{9,15}$/', $phone) === 1 ? $phone : null;
    }

    private function sentRecently(ProviderUser $account): bool
    {
        return ProviderPasswordResetCode::query()
            ->where('provider_user_id', $account->getKey())
            ->where('created_at', '>', now()->subSeconds(ProviderPasswordResetCode::RESEND_SECONDS))
            ->exists();
    }

    private function issueCode(Request $request, ProviderUser $account, string $channel): void
    {
        $plain = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $code = DB::transaction(function () use ($account, $channel, $plain, $request): ProviderPasswordResetCode {
            // A new code replaces any earlier one.
            ProviderPasswordResetCode::query()
                ->where('provider_user_id', $account->getKey())
                ->whereNull('used_at')
                ->update(['expires_at' => now()->subSecond()]);

            return ProviderPasswordResetCode::query()->create([
                'provider_user_id' => $account->getKey(),
                'channel' => $channel,
                'otp_hash' => Hash::make($plain),
                'expires_at' => now()->addMinutes(ProviderPasswordResetCode::TTL_MINUTES),
                'attempts' => 0,
                'ip_address' => $request->ip(),
            ]);
        });

        if (! $this->deliver($account, $channel, $plain)) {
            // The response stays the same (see the class comment); the code
            // just never becomes usable.
            $code->forceFill(['expires_at' => now()->subSecond()])->save();

            return;
        }

        try {
            app(WriteAuditLogAction::class)->execute(
                AuditEventType::PasswordResetRequested,
                null,
                $account,
                reason: 'provider_password_reset_code_'.$channel,
                request: $request,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deliver(ProviderUser $account, string $channel, string $plain): bool
    {
        $notification = new ProviderPasswordResetCodeNotification($plain);

        try {
            if ($channel === self::CHANNEL_EMAIL) {
                $account->notify($notification);

                return true;
            }

            return $this->sms->send((string) $account->phone_number, $notification->toSmsText());
        } catch (Throwable $exception) {
            Log::error('Provider password reset code delivery failed.', [
                'provider_user_id' => $account->getKey(),
                'channel' => $channel,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
