<?php

namespace App\Http\Requests\Auth;

use App\Security\LoginThrottle;
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
        $throttle = app(LoginThrottle::class);
        $email = (string) $this->string('email');
        $throttle->ensureNotLocked($this, 'web', $email, 'email');

        /*
         * No remember-me cookie. The session idle timeout is the policy; a
         * remember cookie could only ever revive a session that policy ended
         * (docs/session-management.md). A stray `remember` field is ignored.
         *
         * A legacy bcrypt hash is replaced by Argon2id on success
         * (hashing.rehash_on_login).
         */
        if (! Auth::attempt($this->only('email', 'password'))) {
            $throttle->failed($this, 'web', $email);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $throttle->succeeded($this, 'web', $email);
    }
}
