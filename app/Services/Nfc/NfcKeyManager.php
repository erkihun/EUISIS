<?php

namespace App\Services\Nfc;

interface NfcKeyManager
{
    /** Delegate cryptographic operations to a secret manager/HSM; never return master keys. */
    public function verify(string $keyReference, string $keyVersion, string $message, string $proof): bool;
}
