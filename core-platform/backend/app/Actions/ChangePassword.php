<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * A person changes their own password: the current one must be given, the new
 * one follows Core settings › Security (length, letters in both cases and
 * digits, none of the account's name or e-mail, none of the last passwords),
 * and every other sign-in of the account ends. The one-time password an
 * administrator handed over is replaced this way at the first sign-in.
 */
final readonly class ChangePassword
{
    public function __construct(private CoreSettings $settings, private AuditWriter $audit) {}

    public function execute(User $user, string $current, string $new, ?int $keepTokenId = null): void
    {
        DB::transaction(function () use ($user, $current, $new, $keepTokenId): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! Hash::check($current, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is not correct.']);
            }

            Validator::make(['password' => $new], ['password' => ['required', 'string', 'max:128',
                Password::min($this->settings->int('security.password_min_length'))->letters()->mixedCase()->numbers()]])->validate();

            $lowered = mb_strtolower($new);
            $fragments = array_filter(
                [...preg_split('/[\s.\-_@]+/u', mb_strtolower($user->name)) ?: [], mb_strtolower(strstr($user->email, '@', true) ?: '')],
                fn (string $part): bool => mb_strlen($part) >= 3,
            );
            foreach ($fragments as $fragment) {
                if (str_contains($lowered, $fragment)) {
                    throw ValidationException::withMessages(['password' => 'The password may not contain your name or e-mail.']);
                }
            }

            $history = $this->settings->int('security.password_history_count');
            $earlier = $history === 0 ? collect() : DB::table('password_histories')->where('user_id', $user->id)
                ->orderByDesc('created_at')->orderByDesc('id')->limit($history)->pluck('password');
            foreach ([$user->password, ...$earlier] as $hash) {
                if (Hash::check($new, (string) $hash)) {
                    throw ValidationException::withMessages(['password' => 'Choose a password you have not used recently.']);
                }
            }

            DB::table('password_histories')->insert(['user_id' => $user->id, 'password' => $user->password, 'created_at' => now()]);
            $user->forceFill(['password' => $new, 'must_change_password' => false, 'password_changed_at' => now()])->save();
            $user->tokens()->when($keepTokenId !== null, fn ($q) => $q->whereKeyNot($keepTokenId))->delete();
            $this->audit->writeFor('user.password_changed', $user, 'User', (string) $user->id, null);
        });
    }
}
