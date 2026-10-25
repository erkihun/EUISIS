<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ManageDigitalCredentials;
use App\Services\CredentialKeys;
use Illuminate\Console\Command;

/**
 * After a new credential key is put first in CORE_CREDENTIAL_KEYS (ADR 0010):
 * moves every active credential onto it, so printed QR codes keep verifying
 * and the old key can leave the configuration when its grace period ends.
 */
class RekeyCredentials extends Command
{
    protected $signature = 'core:credentials-rekey';

    protected $description = 'Re-hash active card credentials under the current credential key';

    public function handle(ManageDigitalCredentials $credentials, CredentialKeys $keys): int
    {
        $moved = $credentials->rekey();
        $this->info("{$moved} credential(s) moved to key version {$keys->current()}.");

        return self::SUCCESS;
    }
}
