<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CardPrintBatch;
use App\Models\CardTemplateVersion;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every card status change after approval, as in EUISIS: print (pending print →
 * printed), issue (printed → issued), activate (issued → active, one active card
 * per active employee), report lost or damaged, suspend (the reversible block),
 * reinstate (back to active, under the activation rules) and revoke (final).
 * Each locks the card row, checks scope and is audited with its reason.
 */
final readonly class ChangeCardStatus
{
    /** transition => [allowed from, resulting status, timestamp column] */
    private const TRANSITIONS = [
        // A printed card is printed again only after its reprint was approved (ManagePrintBatches::requestReprint).
        'print' => [[IdCard::PENDING_PRINT, IdCard::PRINTED], IdCard::PRINTED, 'printed_at'],
        'issue' => [[IdCard::PRINTED], IdCard::ISSUED, 'issued_at'],
        'activate' => [[IdCard::ISSUED], IdCard::ACTIVE, 'activated_at'],
        'report_lost' => [[IdCard::ACTIVE, IdCard::ISSUED], IdCard::LOST, null],
        'report_damaged' => [[IdCard::ACTIVE, IdCard::ISSUED], IdCard::DAMAGED, null],
        'suspend' => [[IdCard::ACTIVE, IdCard::ISSUED], IdCard::SUSPENDED, null],
        'reinstate' => [[IdCard::SUSPENDED], IdCard::ACTIVE, null],
        'revoke' => [[IdCard::ACTIVE, IdCard::ISSUED, IdCard::SUSPENDED, IdCard::PRINTED, IdCard::PENDING_PRINT, IdCard::LOST, IdCard::DAMAGED], IdCard::REVOKED, 'revoked_at'],
    ];

    /** Changes that need a written reason (the routine production steps do not). */
    public const NEEDS_REASON = ['report_lost', 'report_damaged', 'suspend', 'reinstate', 'revoke'];

    /** The permission each step needs (ADR 0009): printing, issuing and revoking are different jobs. */
    public const PERMISSIONS = [
        'print' => 'id-cards.print', 'issue' => 'id-cards.issue', 'activate' => 'id-cards.activate',
        'report_lost' => 'id-cards.replace', 'report_damaged' => 'id-cards.replace',
        'suspend' => 'id-cards.suspend', 'reinstate' => 'id-cards.suspend', 'revoke' => 'id-cards.revoke',
    ];

    public function __construct(private OrganizationScope $scope, private AuditWriter $audit, private CoreSettings $settings, private ManageDigitalCredentials $credentials) {}

    /** @return list<string> */
    public static function transitions(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /** @return list<string> the statuses a step may start from */
    public static function from(string $transition): array
    {
        return self::TRANSITIONS[$transition][0] ?? [];
    }

    public function execute(IdCard $card, string $transition, ?string $reason, User $actor): IdCard
    {
        [$from, $to, $stamp] = self::TRANSITIONS[$transition] ?? throw ValidationException::withMessages(['transition' => 'Unknown card change.']);
        abort_unless($actor->is_active && $actor->can(self::PERMISSIONS[$transition]), 403, 'Your role may not take this step on a card.');

        return DB::transaction(function () use ($card, $transition, $reason, $actor, $from, $to, $stamp): IdCard {
            // Serialize all credential changes with requests and employee lifecycle changes.
            $employee = Employee::query()->lockForUpdate()->findOrFail($card->employee_id);
            $card = IdCard::query()->lockForUpdate()->findOrFail($card->id);
            $card->setRelation('employee', $employee->load('currentAssignment'));
            $organizationId = $card->employee?->currentAssignment?->organization_id;

            if (! $this->scope->allows($actor, $organizationId === null ? null : (string) $organizationId)) {
                throw ValidationException::withMessages(['card' => 'The card holder is outside your organizations.']);
            }
            if (! in_array($card->status, $from, true)) {
                throw ValidationException::withMessages(['card' => 'The card is '.str_replace('_', ' ', $card->status).', so it cannot be changed this way ('.str_replace('_', ' ', $transition).').']);
            }
            if (in_array($transition, ['activate', 'reinstate'], true)) {
                if (! $card->is_current || $card->revoked_at !== null
                    || ($card->expires_at !== null && ! $card->expires_at->isFuture())
                    || ($card->qr_status ?? 'active') !== 'active') {
                    throw ValidationException::withMessages(['card' => 'Only a current, unexpired, unrevoked credential with an active QR can be activated.']);
                }
                $assignment = $employee->currentAssignment;
                if ($assignment === null || $assignment->employee_id !== $employee->id || ! $assignment->isEffective()) {
                    throw ValidationException::withMessages(['card' => 'An effective current assignment is required to activate a card.']);
                }
                $otherActive = IdCard::query()->where('employee_id', $card->employee_id)->whereKeyNot($card->id)->where('status', IdCard::ACTIVE)->exists();
                if ($card->employee === null || $card->employee->status !== 'active' || $otherActive) {
                    throw ValidationException::withMessages(['card' => 'Only an active employee\'s only card can be made active.']);
                }
            }

            $old = $card->status;
            $changes = ['status' => $to];
            if ($transition === 'print') {
                $changes += $this->printing($card, $actor);
            }
            if ($transition === 'issue' && (bool) $this->settings->get('id_cards.require_quality_check') && $card->qc_status !== 'passed') {
                throw ValidationException::withMessages(['card' => 'The printed card must pass the quality check before it is issued.']);
            }
            if ($stamp !== null) {
                $changes[$stamp] = now();
            }
            if ($transition === 'revoke') {
                $changes['revoke_reason'] = $reason;
                $changes['is_current'] = false;
            }
            $card->forceFill($changes)->save();
            if (in_array($transition, ['revoke', 'report_lost', 'report_damaged'], true)) {
                $this->credentials->revokeForCard($card, 'card_'.$to, $actor);
            }

            $this->audit->write($transition === 'print' && $old === IdCard::PRINTED ? 'card.reprinted' : 'card.'.$transition, $actor, $card, $organizationId === null ? null : (string) $organizationId, ['status' => $old], ['status' => $to], $reason);

            return $card->fresh();
        });
    }

    /**
     * What printing records (ADR 0010): the template version (the batch's, else
     * the active one), the print count, the printer, a fresh quality check, and
     * a credential when the card has none yet.
     *
     * @return array<string, mixed>
     */
    private function printing(IdCard $card, User $actor): array
    {
        if ($card->status === IdCard::PRINTED && ! $card->reprint_required) {
            throw ValidationException::withMessages(['card' => 'A printed card is printed again only after a reprint is approved.']);
        }
        $version = $card->current_print_batch_id !== null
            ? CardPrintBatch::query()->whereKey($card->current_print_batch_id)->value('card_template_version_id')
            : CardTemplateVersion::query()->where('status', CardTemplateVersion::ACTIVE)->value('id');
        if ($version === null) {
            throw ValidationException::withMessages(['card' => 'No card template is active: activate one first.']);
        }
        if ($this->credentials->tokenFor($card) === null) {
            $this->credentials->issue($card, $actor);
        }

        return [
            'card_template_version_id' => $version, 'print_count' => (int) $card->print_count + 1, 'printed_by' => $actor->getKey(),
            'qc_status' => 'pending', 'qc_by' => null, 'qc_at' => null, 'qc_notes' => null, 'reprint_required' => false,
        ];
    }
}
