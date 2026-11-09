<?php

namespace App\Services\Nfc;

use App\Models\NfcCredential;
use App\Models\ServiceTerminal;

final class UnavailableSecureCardAdapter implements SecureCardAdapter
{
    public function supports(NfcCredential $credential): bool
    {
        return false;
    }

    public function verify(NfcCredential $credential, ServiceTerminal $terminal, string $nonce, string $contextHash, array $proof): bool
    {
        return false;
    }
}
