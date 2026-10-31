<?php

namespace Modules\Court\Http\Requests\Auth;

use Modules\Court\Application\Support\LoginThrottle;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->throttle()->hit();

            throw ValidationException::withMessages([
                'email' => trans('court::auth.failed'),
            ]);
        }

        $this->throttle()->clear();

        // An account the EUISIS staff check suspended (the employee left the court's units).
        if (Auth::guard('web')->user()?->euisis_suspended_at !== null) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => trans('court::euisis_staff.sign_in_suspended'),
            ]);
        }
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $throttle = $this->throttle();

        if (! $throttle->tooManyAttempts()) {
            return;
        }

        event(new Lockout($this));

        $seconds = $throttle->availableIn();

        throw ValidationException::withMessages([
            'email' => trans('court::auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Failed-attempt lockout for this sign-in: per client and per account.
     */
    public function throttle(): LoginThrottle
    {
        return LoginThrottle::for('staff', (string) $this->string('email'), $this->ip());
    }
}
