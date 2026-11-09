<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\IdCard;
use App\Services\Employees\EmployeePortalNotifier;
use Illuminate\Support\Facades\DB;

class IdCardSnapshotComparisonService
{
    public function __construct(private IdCardFieldImpactService $impact) {}

    public function compare(IdCard $card): array
    {
        $snapshot = $this->impact->latest($card);
        if (! $snapshot) {
            return ['snapshot_available' => false, 'has_changes' => false, 'changed_fields' => []];
        }
        $current = $this->impact->currentValues($card->employee->fresh(), $snapshot->rendered_fields);
        $changed = [];
        foreach ($snapshot->comparison_values as $field => $value) {
            if (($current[$field] ?? null) !== $value) {
                $changed[] = $field;
            }
        }

        return ['snapshot_available' => true, 'has_changes' => $changed !== [], 'changed_fields' => $changed];
    }

    public function evaluate(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            $cards = IdCard::query()->where('employee_id', $employee->id)->where('is_current', true)->whereIn('status', ['printed', 'issued', 'active'])->lockForUpdate()->get();
            foreach ($cards as $card) {
                $result = $this->compare($card);
                if (! $result['has_changes']) {
                    // A correction or reversion is not proof a physical reprint succeeded.
                    continue;
                }
                $reasons = array_values(array_unique([...($card->reprint_reasons ?? []), ...$result['changed_fields']]));
                if ($card->reprint_required && $reasons === $card->reprint_reasons) {
                    continue;
                }
                $card->update(['reprint_required' => true, 'reprint_reasons' => $reasons, 'reprint_required_at' => $card->reprint_required_at ?? now()]);
                app(WriteAuditLogAction::class)->execute(AuditEventType::CardReprintRequired, auth()->user(), $card, $employee->currentAssignment?->organization_id, newValues: ['changed_fields' => $reasons]);
                DB::afterCommit(fn () => app(EmployeePortalNotifier::class)->reprint($card));
            }
        });
    }
}
