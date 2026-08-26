<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Security\DefaultPasswordPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function __construct(private readonly DefaultPasswordPolicyService $defaultPasswordPolicy) {}

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', $this->defaultPasswordPolicy->rule(), 'confirmed'],
        ]);

        $user = $request->user();

        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('auth.password_must_differ'),
            ]);
        }

        if ($this->defaultPasswordPolicy->matches($validated['password'])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password_cannot_be_default'),
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        // Record the holder-chosen credential and its lifecycle timestamp.
        $user->markPasswordChanged();

        return back();
    }
}
