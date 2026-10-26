<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CardPrintBatch;
use App\Models\CardPrintBatchItem;
use App\Models\CardTemplateVersion;
use App\Models\IdCard;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CodeGenerator;
use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Card production (ADR 0010): print batches, the quality check, and reprints.
 *
 *  - A batch (card_print_batches.manage) gathers cards waiting for their
 *    first print, or printed cards whose reprint was approved; a card is in
 *    one open batch at most. It is printed with the template version active
 *    when it was made. Marking it printed takes every card through the same
 *    "print" step as a single card (ChangeCardStatus); a spoiled card stays
 *    waiting and can go into another batch.
 *  - The quality check (cards.quality_check) passes or fails a printed card,
 *    by someone other than the person who printed it. A card is issued only
 *    after passing (setting id_cards.require_quality_check).
 *  - A reprint (cards.reprint, with a reason) is for a printed card not yet
 *    issued — it failed the check, was spoiled or its credential was rotated
 *    — and only up to id_cards.max_reprints times; after that the card is
 *    revoked and replaced instead. After issue, a damaged card is replaced.
 */
final readonly class ManagePrintBatches
{
    public const MAX_CARDS = 500;

    public function __construct(
        private AuditWriter $audit,
        private OrganizationScope $scope,
        private CodeGenerator $codes,
        private CoreSettings $settings,
        private ChangeCardStatus $cards,
    ) {}

    /** @param list<string> $cardIds */
    public function create(array $cardIds, ?string $notes, User $actor): CardPrintBatch
    {
        $this->authorize($actor, 'card_print_batches.manage');
        $cardIds = array_values(array_unique(array_filter($cardIds, 'is_string')));
        if ($cardIds === [] || count($cardIds) > self::MAX_CARDS) {
            throw ValidationException::withMessages(['card_ids' => 'Choose between 1 and '.self::MAX_CARDS.' cards.']);
        }

        return DB::transaction(function () use ($cardIds, $notes, $actor): CardPrintBatch {
            $version = CardTemplateVersion::query()->where('status', CardTemplateVersion::ACTIVE)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['card_ids' => 'No card template is active: activate one first.']);
            $cards = IdCard::query()->with('employee.currentAssignment')->whereKey($cardIds)->orderBy('id')->lockForUpdate()->get();
            if ($cards->count() !== count($cardIds)) {
                throw ValidationException::withMessages(['card_ids' => 'Some cards were not found.']);
            }
            $openBatches = CardPrintBatch::query()->where('status', CardPrintBatch::DRAFT)->pluck('id')->all();
            $items = [];
            foreach ($cards as $card) {
                $kind = match (true) {
                    $card->status === IdCard::PENDING_PRINT && $card->is_current => 'print',
                    $card->status === IdCard::PRINTED && $card->is_current && $card->reprint_required => 'reprint',
                    default => throw ValidationException::withMessages(['card_ids' => "Card {$card->card_number} is not waiting to be printed or reprinted."]),
                };
                if ($card->current_print_batch_id !== null && in_array($card->current_print_batch_id, $openBatches, true)) {
                    throw ValidationException::withMessages(['card_ids' => "Card {$card->card_number} is already in an open batch."]);
                }
                if (! $this->scope->allows($actor, $card->employee?->currentAssignment?->organization_id)) {
                    throw ValidationException::withMessages(['card_ids' => "Card {$card->card_number} is outside your organizations."]);
                }
                $items[] = [$card, $kind];
            }

            $batch = CardPrintBatch::query()->create([
                'batch_number' => $this->codes->code('print_batch'), 'status' => CardPrintBatch::DRAFT, 'card_template_version_id' => $version->id,
                'printer_notes' => $notes, 'created_by' => $actor->getKey(),
            ]);
            foreach ($items as [$card, $kind]) {
                $item = $batch->items()->create([
                    'id_card_id' => $card->id, 'kind' => $kind, 'status' => 'queued', 'qc_status' => 'pending',
                    'reprint_reason' => $kind === 'reprint' ? $this->lastReprintReason($card) : null,
                ]);
                $card->forceFill(['current_print_batch_id' => $batch->id, 'print_batch_item_id' => $item->id])->save();
            }
            $this->audit->writeFor('card_print_batch.created', $actor, 'CardPrintBatch', (string) $batch->id, null, null,
                ['batch_number' => $batch->batch_number, 'cards' => count($items), 'template_version_id' => $version->id]);

            return $batch;
        });
    }

    /** @param list<string> $spoiledItemIds items that came out of the printer unusable */
    public function markPrinted(CardPrintBatch $batch, ?string $notes, array $spoiledItemIds, User $actor): CardPrintBatch
    {
        $this->authorize($actor, 'card_print_batches.manage');

        return DB::transaction(function () use ($batch, $notes, $spoiledItemIds, $actor): CardPrintBatch {
            $batch = CardPrintBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== CardPrintBatch::DRAFT) {
                throw ValidationException::withMessages(['batch' => 'Only a draft batch can be marked printed.']);
            }
            $printed = 0;
            foreach ($batch->items()->with('card')->orderBy('id')->get() as $item) {
                if (in_array($item->id, $spoiledItemIds, true)) {
                    $item->update(['status' => 'spoiled']);
                    $item->card->forceFill(['current_print_batch_id' => null])->save();

                    continue;
                }
                $this->cards->execute($item->card, 'print', $item->kind === 'reprint' ? $item->reprint_reason : null, $actor);
                $item->update(['status' => 'printed']);
                IdCard::query()->whereKey($item->id_card_id)->update(['current_print_batch_id' => null]);
                $printed++;
            }
            $batch->update(['status' => CardPrintBatch::COMPLETED, 'printed_by' => $actor->getKey(), 'printed_at' => now(), 'printer_notes' => $notes ?? $batch->printer_notes]);
            $this->audit->writeFor('card_print_batch.printed', $actor, 'CardPrintBatch', (string) $batch->id, null, null,
                ['printed' => $printed, 'spoiled' => count($spoiledItemIds)]);

            return $batch;
        });
    }

    public function cancel(CardPrintBatch $batch, string $reason, User $actor): CardPrintBatch
    {
        $this->authorize($actor, 'card_print_batches.manage');

        return DB::transaction(function () use ($batch, $reason, $actor): CardPrintBatch {
            $batch = CardPrintBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== CardPrintBatch::DRAFT) {
                throw ValidationException::withMessages(['batch' => 'Only a draft batch can be cancelled.']);
            }
            IdCard::query()->where('current_print_batch_id', $batch->id)->update(['current_print_batch_id' => null]);
            $batch->update(['status' => CardPrintBatch::CANCELLED, 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()]);
            $this->audit->writeFor('card_print_batch.cancelled', $actor, 'CardPrintBatch', (string) $batch->id, null, null, ['status' => 'cancelled'], $reason);

            return $batch;
        });
    }

    /** Passes or fails a printed card. A failed card needs a reprint (or revocation) before it can be issued. */
    public function qualityCheck(IdCard $card, bool $passed, ?string $notes, User $actor): IdCard
    {
        $this->authorize($actor, 'cards.quality_check');
        if (! $passed && mb_strlen(trim((string) $notes)) < 5) {
            throw ValidationException::withMessages(['qc_notes' => 'Say what is wrong with the card (at least 5 characters).']);
        }

        return DB::transaction(function () use ($card, $passed, $notes, $actor): IdCard {
            $card = IdCard::query()->with('employee.currentAssignment')->lockForUpdate()->findOrFail($card->id);
            $organization = $card->employee?->currentAssignment?->organization_id;
            if (! $this->scope->allows($actor, $organization === null ? null : (string) $organization)) {
                throw ValidationException::withMessages(['card' => 'The card holder is outside your organizations.']);
            }
            if ($card->status !== IdCard::PRINTED || $card->qc_status !== 'pending') {
                throw ValidationException::withMessages(['card' => 'Only a printed card waiting for its check can be checked.']);
            }
            if ($card->printed_by !== null && (int) $card->printed_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['card' => 'Someone other than the person who printed the card checks it.']);
            }
            $result = $passed ? 'passed' : 'failed';
            $changes = ['qc_status' => $result, 'qc_by' => $actor->getKey(), 'qc_at' => now(), 'qc_notes' => $notes];
            if (! $passed) {
                $reasons = (array) ($card->reprint_reasons ?? []);
                $reasons[] = ['reason' => 'quality_check_failed', 'note' => $notes, 'at' => now()->toIso8601String()];
                $changes += ['reprint_reasons' => $reasons];
            }
            $card->forceFill($changes)->save();
            CardPrintBatchItem::query()->whereKey($card->print_batch_item_id)->where('id_card_id', $card->id)
                ->update(['qc_status' => $result, 'qc_by' => $actor->getKey(), 'qc_at' => now(), 'qc_notes' => $notes]);
            $this->audit->write('card.quality_'.$result, $actor, $card, $organization === null ? null : (string) $organization, null, ['qc_status' => $result], $notes);

            return $card;
        });
    }

    /** Approves printing a printed, not yet issued card again. */
    public function requestReprint(IdCard $card, string $reason, User $actor): IdCard
    {
        $this->authorize($actor, 'cards.reprint');
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give the reason for the reprint (at least 5 characters).']);
        }

        return DB::transaction(function () use ($card, $reason, $actor): IdCard {
            $card = IdCard::query()->with('employee.currentAssignment')->lockForUpdate()->findOrFail($card->id);
            $organization = $card->employee?->currentAssignment?->organization_id;
            if (! $this->scope->allows($actor, $organization === null ? null : (string) $organization)) {
                throw ValidationException::withMessages(['card' => 'The card holder is outside your organizations.']);
            }
            if ($card->status !== IdCard::PRINTED || ! $card->is_current) {
                throw ValidationException::withMessages(['card' => 'Only a printed card that has not been issued can be reprinted; an issued card is replaced.']);
            }
            $max = $this->settings->int('id_cards.max_reprints');
            if ((int) $card->print_count - 1 >= $max) {
                throw ValidationException::withMessages(['card' => "This card has been reprinted {$max} times already: revoke it and request a replacement."]);
            }
            $reasons = (array) ($card->reprint_reasons ?? []);
            $reasons[] = ['reason' => 'reprint_approved', 'note' => $reason, 'by' => $actor->getKey(), 'at' => now()->toIso8601String()];
            $card->forceFill(['reprint_required' => true, 'reprint_reasons' => $reasons, 'reprint_required_at' => now()])->save();
            $this->audit->write('card.reprint_approved', $actor, $card, $organization === null ? null : (string) $organization, null, ['print_count' => $card->print_count], $reason);

            return $card;
        });
    }

    private function lastReprintReason(IdCard $card): ?string
    {
        $last = collect((array) ($card->reprint_reasons ?? []))->last();

        return is_array($last) ? (string) ($last['note'] ?? $last['reason'] ?? '') : null;
    }

    private function authorize(User $actor, string $permission): void
    {
        abort_unless($actor->is_active && $actor->can($permission), 403);
    }
}
