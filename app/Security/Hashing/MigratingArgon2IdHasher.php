<?php

declare(strict_types=1);

namespace App\Security\Hashing;

use Illuminate\Hashing\Argon2IdHasher;

/**
 * Argon2id for every new hash, while existing bcrypt hashes keep working.
 *
 *   new hash            Argon2id (no input-length truncation)
 *   bcrypt hash         verified, then flagged for rehash; with
 *                       `hashing.rehash_on_login` the owner's next successful
 *                       sign-in replaces it with Argon2id
 *   anything else       rejected (never "verified" by some other algorithm)
 *
 * Only the two algorithms EUISIS has ever written are accepted, so the
 * algorithm check Laravel performs is kept, not switched off globally.
 */
class MigratingArgon2IdHasher extends Argon2IdHasher
{
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = [])
    {
        if (! is_string($hashedValue) || $hashedValue === '') {
            return false;
        }

        if ($this->isLegacyBcrypt($hashedValue)) {
            return password_verify((string) $value, $hashedValue);
        }

        if (! $this->isUsingCorrectAlgorithm($hashedValue)) {
            return false;
        }

        return parent::check($value, $hashedValue, $options);
    }

    public function needsRehash($hashedValue, array $options = [])
    {
        return $this->isLegacyBcrypt((string) $hashedValue) || parent::needsRehash($hashedValue, $options);
    }

    /** Existing bcrypt hashes may still be copied (e.g. into password history). */
    public function verifyConfiguration($value)
    {
        return $this->isLegacyBcrypt((string) $value) || parent::verifyConfiguration($value);
    }

    private function isLegacyBcrypt(string $hash): bool
    {
        return password_get_info($hash)['algoName'] === 'bcrypt';
    }
}
