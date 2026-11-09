<?php

namespace App\Services\Nfc;

use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\IdCard;
use App\Models\NfcCredential;
use App\Models\ServiceTerminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NfcVerificationService
{
    /** Run inside a DB transaction. Locks survive through service recording. */
    public function verify(array $payload, Request $request, string $purpose = 'verify'): array
    {
        $terminal = ServiceTerminal::where('terminal_code', $payload['terminal_id'])->lockForUpdate()->first();
        if (! $terminal || $terminal->status !== 'active' || $terminal->external_application_id !== $request->user()->id
            || ($purpose !== 'verify' && $terminal->service_type !== ($payload['service_type'] ?? null))) {
            return $this->denied('TERMINAL_NOT_ALLOWED', $request);
        }
        $candidate = NfcCredential::where('credential_id', $payload['credential'])->first();
        if (! $candidate) {
            return $this->denied('NFC_CREDENTIAL_NOT_FOUND', $request, terminal: $terminal);
        }
        $card = IdCard::whereKey($candidate->id_card_id)->lockForUpdate()->first();
        $credential = NfcCredential::whereKey($candidate->id)->lockForUpdate()->first();
        if (! $credential) {
            return $this->denied('NFC_CREDENTIAL_NOT_FOUND', $request, terminal: $terminal);
        }
        $reason = $credential->status !== 'active' ? 'NFC_CREDENTIAL_'.strtoupper($credential->status === 'pending' ? 'inactive' : $credential->status) : null;
        $reason ??= $credential->expires_at?->isPast() ? 'NFC_CREDENTIAL_EXPIRED' : null;
        $reason ??= $credential->revoked_at ? 'NFC_CREDENTIAL_REVOKED' : null;
        if (! $reason) {
            $reason = match (true) {
                ! $card => 'CARD_INACTIVE',
                $card->status === CardStatus::Lost => 'CARD_LOST',
                $card->status === CardStatus::Replaced || ! $card->is_current => 'CARD_REPLACED',
                $card->status === CardStatus::Revoked || $card->revoked_at !== null => 'CARD_REVOKED',
                $card->expires_at?->isPast() || $card->status === CardStatus::Expired => 'CARD_EXPIRED',
                $card->status !== CardStatus::Active => 'CARD_INACTIVE',
                default => null,
            };
        }
        if (! $reason) {
            $employee = $card->employee()->select('id', 'status', 'current_assignment_id')->lockForUpdate()->first();
            $card->setRelation('employee', $employee);
            $reason = $employee?->status !== EmployeeStatus::Active ? 'EMPLOYEE_INACTIVE' : null;
            if (! $reason && $terminal->organization_id && $employee->currentAssignment?->organization_id !== $terminal->organization_id) {
                $reason = 'TERMINAL_NOT_ALLOWED';
            }
        }
        if ($reason) {
            return $this->denied($reason, $request, $credential, $terminal);
        }

        $assurance = 'reference';
        if ($credential->credential_type !== 'ndef_reference') {
            $adapter = app(config('nfc.adapter'));
            if (! $adapter instanceof SecureCardAdapter || ! $adapter->supports($credential)) {
                return $this->denied('INVALID_CRYPTOGRAPHIC_PROOF', $request, $credential, $terminal);
            }
            $context = app(NfcPayloadParser::class)->context($payload, $purpose);
            $nonce = (string) ($payload['challenge'] ?? '');
            $challenge = DB::table('nfc_challenges')->where('nonce_hash', hash('sha256', $nonce))->lockForUpdate()->first();
            if ($challenge?->consumed_at) {
                return $this->denied('REPLAY_DETECTED', $request, $credential, $terminal);
            }
            if (! $challenge || $challenge->nfc_credential_id !== $credential->id || $challenge->terminal_id !== $terminal->id
                || $challenge->context_hash !== $context || now()->gte($challenge->expires_at)) {
                return $this->denied('INVALID_CRYPTOGRAPHIC_PROOF', $request, $credential, $terminal);
            }
            // Consume even failed attempts; never let a proof become a reusable oracle.
            DB::table('nfc_challenges')->where('nonce_hash', $challenge->nonce_hash)->update(['consumed_at' => now()]);
            try {
                $valid = $adapter->verify($credential, $terminal, $nonce, $context, $payload['proof'] ?? []);
            } catch (\Throwable) {
                $valid = false; // Adapter exceptions can contain secrets; do not log their text.
            }
            if (! $valid) {
                return $this->denied('INVALID_CRYPTOGRAPHIC_PROOF', $request, $credential, $terminal);
            }
            $assurance = 'cryptographic';
        }
        if ($purpose === 'record' && $assurance !== 'cryptographic') {
            return $this->denied('CRYPTOGRAPHIC_PROOF_REQUIRED', $request, $credential, $terminal);
        }
        $credential->update(['last_used_at' => now()]);
        $terminal->update(['last_seen_at' => now()]);
        app(NfcAudit::class)->record('verification', true, credential: $credential, terminal: $terminal, request: $request);

        return ['valid' => true, 'eligible' => null, 'reason_code' => null, 'assurance' => $assurance, 'card' => $card, 'credential_model' => $credential, 'terminal' => $terminal];
    }

    public function challenge(array $payload, Request $request): array
    {
        $terminal = ServiceTerminal::where('terminal_code', $payload['terminal_id'])->first();
        if (! $terminal || $terminal->status !== 'active' || $terminal->external_application_id !== $request->user()->id) {
            return $this->denied('TERMINAL_NOT_ALLOWED', $request);
        }
        $credential = NfcCredential::where('credential_id', $payload['credential'])->where('status', 'active')->first();
        if (! $credential || ! app(config('nfc.adapter'))->supports($credential)) {
            return $this->denied('INVALID_CRYPTOGRAPHIC_PROOF', $request, $credential, $terminal);
        }
        $nonce = bin2hex(random_bytes(32));
        $context = app(NfcPayloadParser::class)->context($payload, $payload['purpose'] ?? 'verify');
        $expires = now()->addSeconds(max(10, min(120, (int) config('nfc.challenge_ttl_seconds'))));
        DB::table('nfc_challenges')->insert(['nonce_hash' => hash('sha256', $nonce), 'nfc_credential_id' => $credential->id,
            'terminal_id' => $terminal->id, 'context_hash' => $context, 'expires_at' => $expires]);

        return ['challenge' => $nonce, 'context_hash' => $context, 'expires_at' => $expires->toIso8601String()];
    }

    private function denied(string $reason, Request $request, ?NfcCredential $credential = null, ?ServiceTerminal $terminal = null): array
    {
        app(NfcAudit::class)->record($reason === 'REPLAY_DETECTED' ? 'replay_attempt' : 'verification', false, $reason, $credential, $terminal, $request);

        return ['valid' => false, 'eligible' => false, 'reason_code' => $reason];
    }
}
