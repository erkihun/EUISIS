<?php

namespace App\Services\Nfc;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\ExternalApplication;
use App\Models\NfcCredential;
use App\Models\NfcVerificationLog;
use App\Models\ServiceTerminal;
use App\Models\User;
use Illuminate\Http\Request;

final class NfcAudit
{
    /**
     * @param  array<string, scalar|null>  $metadata  Non-sensitive context only.
     *                                                Never proofs, nonces or keys.
     */
    public function record(string $event, bool $allowed, ?string $reason = null, ?NfcCredential $credential = null, ?ServiceTerminal $terminal = null, ?Request $request = null, ?User $actor = null, array $metadata = []): void
    {
        NfcVerificationLog::create([
            'nfc_credential_id' => $credential?->id,
            'terminal_id' => $terminal?->id,
            'external_application_id' => $request?->user() instanceof ExternalApplication ? $request->user()->id : null,
            'event_type' => $event, 'result' => $allowed ? 'allowed' : 'blocked',
            'reason_code' => $reason, 'ip_address' => $request?->ip(), 'occurred_at' => now(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
        app(WriteAuditLogAction::class)->execute(
            AuditEventType::NfcEvent, $actor, $credential ?? $terminal,
            newValues: ['event' => $event, 'allowed' => $allowed, 'reason_code' => $reason] + $metadata,
            request: $request,
        );
    }
}
