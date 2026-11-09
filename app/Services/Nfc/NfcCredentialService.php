<?php

namespace App\Services\Nfc;

use App\Enums\CardStatus;
use App\Models\IdCard;
use App\Models\NfcCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class NfcCredentialService
{
    public function provision(IdCard $card, User $actor, string $type = 'ndef_reference', ?string $keyVersion = null, ?string $keyReference = null): NfcCredential
    {
        return DB::transaction(function () use ($card, $actor, $type, $keyVersion, $keyReference) {
            $card = IdCard::query()->lockForUpdate()->findOrFail($card->id);
            $this->assertCard($card);
            if (! in_array($type, ['ndef_reference', 'secure_smart_card', 'mobile_credential'], true)) {
                throw ValidationException::withMessages(['nfc' => 'Unsupported credential type.']);
            }
            if (NfcCredential::where('id_card_id', $card->id)->whereIn('status', ['pending', 'active', 'suspended'])->exists()) {
                throw ValidationException::withMessages(['nfc' => 'Replace or revoke the existing NFC credential first.']);
            }
            $credential = NfcCredential::create([
                'id_card_id' => $card->id, 'credential_id' => 'nfc_'.bin2hex(random_bytes(32)),
                'credential_type' => $type, 'key_version' => $keyVersion, 'key_reference' => $keyReference,
                'status' => 'pending', 'issued_at' => now(), 'expires_at' => $card->expires_at, 'created_by' => $actor->id,
            ]);
            app(NfcAudit::class)->record('credential_provisioned', true, credential: $credential, actor: $actor);

            return $credential;
        });
    }

    public function transition(NfcCredential $credential, string $action, User $actor): NfcCredential
    {
        return DB::transaction(function () use ($credential, $action, $actor) {
            // Same lock order as verification and provisioning.
            $card = IdCard::query()->lockForUpdate()->findOrFail($credential->id_card_id);
            $credential = NfcCredential::query()->lockForUpdate()->findOrFail($credential->id);
            $allowed = match ($action) {
                'activate' => ['pending', 'suspended'],
                'suspend' => ['active'],
                'lost' => ['pending', 'active', 'suspended'],
                'revoke' => ['pending', 'active', 'suspended', 'lost'],
                'replace' => ['pending', 'active', 'suspended', 'lost', 'revoked', 'expired'],
                default => [],
            };
            if (! in_array($credential->status, $allowed, true)) {
                throw ValidationException::withMessages(['nfc' => 'Invalid NFC lifecycle transition.']);
            }
            if ($action === 'activate') {
                $this->assertCard($card);
                if ($credential->expires_at?->isPast()) {
                    throw ValidationException::withMessages(['nfc' => 'Credential expired. Replace it.']);
                }
                if ($credential->credential_type !== 'ndef_reference' && (blank($credential->key_version) || blank($credential->key_reference) || ! app(config('nfc.adapter'))->supports($credential))) {
                    throw ValidationException::withMessages(['nfc' => 'Secure hardware adapter is not configured for this credential.']);
                }
            }
            $credential->status = match ($action) {
                'activate' => 'active', 'suspend' => 'suspended', 'lost' => 'lost', 'revoke' => 'revoked', 'replace' => 'replaced'
            };
            if ($action === 'activate') {
                $credential->activated_at = now();
            }
            if (in_array($action, ['revoke', 'replace', 'lost'], true)) {
                $credential->revoked_at = now();
                $credential->revoked_by = $actor->id;
            }
            $credential->save();
            if ($action === 'replace') {
                $replacement = $this->provision($card, $actor, $credential->credential_type, $credential->key_version, $credential->key_reference);
                $credential->update(['replaced_by_id' => $replacement->id]);
            }
            app(NfcAudit::class)->record('credential_'.$credential->status, true, credential: $credential, actor: $actor);

            return $credential->refresh();
        });
    }

    /**
     * Resolve a credential reference read by an attended scanner.
     *
     * This is the reference-assurance path: a staff member has the physical
     * card in hand, exactly as with a QR scan, so the reference identifies the
     * card but proves nothing on its own. Unattended terminals must instead go
     * through the API, which demands a cryptographic proof before it will
     * record a transaction.
     *
     * @return array{card: IdCard|null, reason: string|null}
     */
    public function resolveForAttendedScan(string $reference): array
    {
        $credential = NfcCredential::query()
            ->with('idCard')
            ->where('credential_id', $reference)
            ->first();

        if ($credential === null) {
            return ['card' => null, 'reason' => 'nfc_credential_not_found'];
        }

        if ($credential->status !== 'active') {
            return ['card' => null, 'reason' => 'nfc_credential_'.$credential->status];
        }

        if ($credential->expires_at?->isPast()) {
            return ['card' => null, 'reason' => 'nfc_credential_expired'];
        }

        // Card, employee and service rules are deliberately NOT checked here:
        // the shared scan service owns them, so NFC and QR cannot drift.
        return ['card' => $credential->idCard, 'reason' => null];
    }

    /**
     * What the configured adapter can actually do right now.
     *
     * The UI uses this to label credential types honestly: without a bound
     * hardware adapter a secure card can be recorded as pending, but nothing
     * cryptographic has happened and the credential cannot be activated.
     *
     * @return array{adapter: string, secure_available: bool, types: list<array{value: string, available: bool}>}
     */
    public function hardwareCapability(): array
    {
        $adapter = app(config('nfc.adapter'));
        $secureAvailable = $adapter instanceof SecureCardAdapter && ! $adapter instanceof UnavailableSecureCardAdapter;

        return [
            'adapter' => $adapter::class,
            'secure_available' => $secureAvailable,
            'types' => [
                ['value' => 'ndef_reference', 'available' => true],
                ['value' => 'secure_smart_card', 'available' => $secureAvailable],
                ['value' => 'mobile_credential', 'available' => $secureAvailable],
            ],
        ];
    }

    private function assertCard(IdCard $card): void
    {
        if ($card->status !== CardStatus::Active || ! $card->is_current || $card->revoked_at || $card->expires_at?->isPast()) {
            throw ValidationException::withMessages(['nfc' => 'NFC provisioning and activation require a current active ID card.']);
        }
    }
}
