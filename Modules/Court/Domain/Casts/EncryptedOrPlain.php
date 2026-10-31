<?php

namespace Modules\Court\Domain\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts on write, but tolerates values that were stored before the column
 * was encrypted.
 *
 * The plain `encrypted` cast throws a DecryptException on any legacy plaintext
 * value, which takes down every page that reads the model. Here an undecryptable
 * value is returned as-is, so existing rows keep working and are upgraded to
 * ciphertext the next time they are saved.
 */
class EncryptedOrPlain implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Legacy plaintext — hand back what is actually stored.
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }
}
