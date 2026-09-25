<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\IdCardPrintSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IdCardPrintSnapshotService
{
    public function prepare(IdCard $card, User $actor, ?string $orientation = null): IdCardPrintSnapshot
    {
        return DB::transaction(function () use ($card, $actor, $orientation) {
            Employee::query()->whereKey($card->employee_id)->lockForUpdate()->firstOrFail();
            $card = IdCard::query()->lockForUpdate()->findOrFail($card->id);
            $this->assertPrintable($card);
            Gate::forUser($actor)->authorize($card->status === CardStatus::PendingPrint ? 'print' : 'reprint', $card);
            $data = app(IdCardRenderDataFactory::class)->make($card, $orientation);
            $impact = app(IdCardFieldImpactService::class);
            $values = $impact->renderedValues($data);
            $template = app(IdCardTemplateService::class)->active($orientation);
            $path = 'card-print-artifacts/'.Str::uuid();
            $renderer = app(IdCardSvgRenderer::class);
            $disk = Storage::disk('local');
            foreach (['front' => $renderer->renderFront($data), 'back' => $renderer->renderBack($data)] as $side => $svg) {
                if (! $disk->put($path.'-'.$side.'.svg', $svg)) {
                    throw ValidationException::withMessages(['print' => __('employee-portal.upload_failed')]);
                }
            }

            return IdCardPrintSnapshot::create([
                'id_card_id' => $card->id, 'employee_id' => $card->employee_id,
                'template_id' => $template?->id,
                'template_version' => hash('sha256', json_encode([$template?->getAttributes(), $data->layout, $data->orientation])),
                'orientation' => $data->orientation,
                'width_mm' => $data->widthMm, 'height_mm' => $data->heightMm,
                'rendered_fields' => array_keys($values), 'rendered_values' => $values,
                'comparison_values' => $impact->currentValues($card->employee, array_keys($values)),
                'artifact_path' => $path, 'prepared_by' => $actor->id,
            ]);
        });
    }

    public function confirm(IdCard $card, IdCardPrintSnapshot $snapshot, User $actor): void
    {
        DB::transaction(function () use ($card, $snapshot, $actor) {
            Employee::query()->whereKey($card->employee_id)->lockForUpdate()->firstOrFail();
            $card = IdCard::query()->lockForUpdate()->findOrFail($card->id);
            $this->assertPrintable($card);
            Gate::forUser($actor)->authorize($card->status === CardStatus::PendingPrint ? 'print' : 'reprint', $card);
            $snapshot = IdCardPrintSnapshot::query()->lockForUpdate()->findOrFail($snapshot->id);
            if ($snapshot->id_card_id !== $card->id || $snapshot->printed_at || $snapshot->created_at->lt(now()->subDay())) {
                throw ValidationException::withMessages(['print' => __('employee-portal.invalid_print')]);
            }
            $latest = app(IdCardFieldImpactService::class)->latest($card);
            if ($latest && strcmp($latest->id, $snapshot->id) >= 0) {
                throw ValidationException::withMessages(['print' => __('employee-portal.invalid_print')]);
            }
            $snapshot->update(['printed_at' => now(), 'printed_by' => $actor->id, 'print_sequence' => ($latest?->print_sequence ?? 0) + 1]);
            $firstPrint = $card->status === CardStatus::PendingPrint;
            $card->update([
                'status' => $firstPrint ? CardStatus::Printed : $card->status,
                'printed_at' => now(), 'reprint_required' => false,
                'reprint_reasons' => null, 'reprint_required_at' => null,
            ]);
            $audit = app(WriteAuditLogAction::class);
            $audit->execute(AuditEventType::CardSnapshotCreated, $actor, $card, $card->employee?->currentAssignment?->organization_id, newValues: ['snapshot_id' => $snapshot->id, 'rendered_fields' => $snapshot->rendered_fields]);
            $audit->execute($firstPrint ? AuditEventType::CardPrinted : AuditEventType::CardReprinted, $actor, $card, $card->employee?->currentAssignment?->organization_id, newValues: ['snapshot_id' => $snapshot->id]);
            // Changes made after preparation must still be flagged against what was actually printed.
            app(IdCardSnapshotComparisonService::class)->evaluate($card->employee);
        });
    }

    private function assertPrintable(IdCard $card): void
    {
        abort_unless($card->is_current && in_array($card->status, [CardStatus::PendingPrint, CardStatus::Printed, CardStatus::Issued, CardStatus::Active], true), 422);
    }
}
