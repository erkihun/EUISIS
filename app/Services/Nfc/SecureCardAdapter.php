<?php

namespace App\Services\Nfc;

use App\Models\NfcCredential;
use App\Models\ServiceTerminal;

interface SecureCardAdapter
{
    public function supports(NfcCredential $credential): bool;

    /** Verify real card possession, binding proof to nonce AND context hash AND terminal. */
    public function verify(NfcCredential $credential, ServiceTerminal $terminal, string $nonce, string $contextHash, array $proof): bool;
}
