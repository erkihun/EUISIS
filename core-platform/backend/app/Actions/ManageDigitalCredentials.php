<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\DigitalCredential;
use App\Models\IdCard;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CredentialKeys;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Card credentials (ADR 0010). A card has at most one active credential; a
 * new one supersedes the old, so a QR printed before stops verifying. A
 * credential follows its card: revoking, losing, damaging or replacing the
 * card revokes it. Rotating one means the card must be reprinted.
 */
final readonly class ManageDigitalCredentials
{
    public function __construct(private CredentialKeys $keys, private AuditWriter $audit, private OrganizationScope $scope) {}

    /** Issues a new credential for a card, superseding any active one. Called when a card is made, and by digital_credentials.issue holders. */
    public function issue(IdCard $card, ?User $actor, string $kind = 'qr'): DigitalCredential
    {
        return DB::transaction(function () use ($card, $actor, $kind): DigitalCredential {
            DigitalCredential::query()->where('id_card_id', $card->id)->where('status', DigitalCredential::ACTIVE)->lockForUpdate()
                ->update(['status' => DigitalCredential::SUPERSEDED, 'revoked_at' => now(), 'revoked_by' => $actor?->getKey(), 'revoke_reason' => 'superseded']);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

            return DigitalCredential::query()->create([
                'id_card_id' => $card->id, 'employee_id' => $card->employee_id, 'kind' => $kind,
                'token_hash' => $this->keys->hash($token), 'token_encrypted' => Crypt::encryptString($token), 'key_version' => $this->keys->current(),
                'status' => DigitalCredential::ACTIVE, 'issued_at' => now(), 'issued_by' => $actor?->getKey(), 'expires_at' => $card->expires_at,
            ]);
        });
    }

    /** A credential for a card that has none, by someone who may issue them (a card made before P3 that lost its legacy one). */
    public function issueFor(IdCard $card, User $actor): DigitalCredential
    {
        $this->authorize($card, $actor, 'digital_credentials.issue');
        if (DigitalCredential::query()->where('id_card_id', $card->id)->where('status', DigitalCredential::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['credential' => 'The card already has an active credential; rotate it instead.']);
        }
        $this->assertLive($card);
        $credential = $this->issue($card, $actor);
        $this->audit->write('credential.issued', $actor, $card, $this->organizationOf($card), null, ['credential_id' => $credential->id, 'key_version' => $credential->key_version]);

        return $credential;
    }

    /** A new token for the card; the printed QR stops verifying, so the card is marked for reprint. */
    public function rotate(IdCard $card, string $reason, User $actor): DigitalCredential
    {
        $this->authorize($card, $actor, 'digital_credentials.rotate');
        $this->requireReason($reason);
        $this->assertLive($card);

        return DB::transaction(function () use ($card, $reason, $actor): DigitalCredential {
            $card = IdCard::query()->lockForUpdate()->findOrFail($card->id);
            $credential = $this->issue($card, $actor);
            $reasons = (array) ($card->reprint_reasons ?? []);
            $reasons[] = ['reason' => 'credential_rotated', 'note' => $reason, 'at' => now()->toIso8601String()];
            $card->forceFill(['reprint_required' => true, 'reprint_reasons' => $reasons, 'reprint_required_at' => now(), 'qr_rotated_at' => now()])->save();
            $this->audit->write('credential.rotated', $actor, $card, $this->organizationOf($card), null, ['credential_id' => $credential->id, 'key_version' => $credential->key_version], $reason);

            return $credential;
        });
    }

    public function revoke(IdCard $card, string $reason, User $actor): void
    {
        $this->authorize($card, $actor, 'digital_credentials.revoke');
        $this->requireReason($reason);
        $count = $this->revokeForCard($card, $reason, $actor);
        if ($count === 0) {
            throw ValidationException::withMessages(['credential' => 'The card has no active credential.']);
        }
        $this->audit->write('credential.revoked', $actor, $card, $this->organizationOf($card), null, ['revoked' => $count], $reason);
    }

    /** Revokes whatever is active for the card (the card itself was revoked, lost, damaged or replaced). */
    public function revokeForCard(IdCard $card, string $reason, ?User $actor): int
    {
        return DigitalCredential::query()->where('id_card_id', $card->id)->where('status', DigitalCredential::ACTIVE)
            ->update(['status' => DigitalCredential::REVOKED, 'revoked_at' => now(), 'revoked_by' => $actor?->getKey(), 'revoke_reason' => mb_substr($reason, 0, 1000), 'updated_at' => now()]);
    }

    /** The active credential's token, for drawing the QR (a reprint shows the same QR). */
    public function tokenFor(IdCard $card): ?string
    {
        $encrypted = DigitalCredential::query()->where('id_card_id', $card->id)->where('status', DigitalCredential::ACTIVE)->value('token_encrypted');

        return $encrypted === null ? null : Crypt::decryptString((string) $encrypted);
    }

    /**
     * Moves every active credential onto the current key (after a key change),
     * so the old key can leave the configuration once its grace period ends.
     *
     * @return int how many were re-hashed
     */
    public function rekey(): int
    {
        $moved = 0;
        DigitalCredential::query()->where('status', DigitalCredential::ACTIVE)->where('key_version', '!=', $this->keys->current())
            ->orderBy('id')->lazyById(500)->each(function (DigitalCredential $credential) use (&$moved): void {
                $token = Crypt::decryptString((string) $credential->token_encrypted);
                $credential->forceFill(['token_hash' => $this->keys->hash($token), 'key_version' => $this->keys->current()])->save();
                $moved++;
            });

        return $moved;
    }

    private function authorize(IdCard $card, User $actor, string $permission): void
    {
        abort_unless($actor->is_active && $actor->can($permission), 403);
        if (! $this->scope->allows($actor, $this->organizationOf($card))) {
            throw ValidationException::withMessages(['card' => 'The card holder is outside your organizations.']);
        }
    }

    private function assertLive(IdCard $card): void
    {
        if (! $card->is_current || ! in_array($card->status, [...IdCard::LIVE, IdCard::SUSPENDED], true)) {
            throw ValidationException::withMessages(['credential' => 'Only a current, live card carries a credential.']);
        }
    }

    private function requireReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give the reason (at least 5 characters).']);
        }
    }

    private function organizationOf(IdCard $card): ?string
    {
        $id = $card->employee()->with('currentAssignment')->first()?->currentAssignment?->organization_id;

        return $id === null ? null : (string) $id;
    }
}
