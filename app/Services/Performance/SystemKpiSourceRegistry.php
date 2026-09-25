<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\TransferStatus;
use App\Models\EmployeeServiceFeedback;
use App\Models\EmployeeTransfer;
use App\Models\IdCard;
use Illuminate\Support\Carbon;

/**
 * KPI actuals the system already knows (docs/epms-calculation-rules.md §5):
 * nobody should type a number a module already records. Each source returns
 * value/numerator/denominator/weight for an organization (and optionally an
 * employee) and a period, from fixed, parameterized queries only.
 */
final class SystemKpiSourceRegistry
{
    /** @return array<string, array{label_en: string, label_am: string, level: string}> */
    public static function sources(): array
    {
        return [
            'id_cards.issued' => ['label_en' => 'ID cards issued to the organization\'s employees', 'label_am' => 'ለተቋሙ ሠራተኞች የተሰጡ መታወቂያዎች', 'level' => 'organization'],
            'employee_transfers.completed' => ['label_en' => 'Employee transfers completed (in or out)', 'label_am' => 'የተጠናቀቁ የሠራተኛ ዝውውሮች', 'level' => 'organization'],
            'service_feedback.average_rating' => ['label_en' => 'Average service feedback rating', 'label_am' => 'አማካይ የአገልግሎት ግብረ መልስ ደረጃ', 'level' => 'employee'],
        ];
    }

    public static function has(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::sources());
    }

    /** @return array{value: ?string, numerator: ?string, denominator: ?string, weight: ?string, reference: string} */
    public function measure(string $key, string $organizationId, ?string $employeeId, Carbon $from, Carbon $to): array
    {
        $end = $to->copy()->endOfDay();

        return match ($key) {
            'id_cards.issued' => $this->count(
                // Issued is a historical fact: later loss/expiry does not un-issue a card.
                IdCard::query()->whereNotNull('issued_at')->whereBetween('issued_at', [$from->copy()->startOfDay(), $end])
                    ->whereHas('employee.currentAssignment', fn ($q) => $q->where('organization_id', $organizationId))
                    ->count(),
                'id_cards',
            ),
            'employee_transfers.completed' => $this->count(
                EmployeeTransfer::query()->where('status', TransferStatus::Completed->value)
                    ->whereBetween('completed_at', [$from->copy()->startOfDay(), $end])
                    ->where(fn ($q) => $q->where('from_organization_id', $organizationId)->orWhere('to_organization_id', $organizationId))
                    ->count(),
                'employee_transfers',
            ),
            'service_feedback.average_rating' => $this->average($organizationId, $employeeId, $from, $end),
            default => throw new \InvalidArgumentException("Unknown system KPI source [{$key}]."),
        };
    }

    /** @return array{value: ?string, numerator: ?string, denominator: ?string, weight: ?string, reference: string} */
    private function count(int $count, string $reference): array
    {
        return ['value' => (string) $count, 'numerator' => null, 'denominator' => null, 'weight' => null, 'reference' => $reference];
    }

    /** @return array{value: ?string, numerator: ?string, denominator: ?string, weight: ?string, reference: string} */
    private function average(string $organizationId, ?string $employeeId, Carbon $from, Carbon $end): array
    {
        $query = EmployeeServiceFeedback::query()->whereBetween('created_at', [$from->copy()->startOfDay(), $end])
            ->where('organization_id', $organizationId)
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId));
        $count = (clone $query)->count();
        $sum = (int) (clone $query)->sum('rating');

        // Weighted by response count so a parent re-averages correctly.
        return [
            'value' => $count === 0 ? null : (string) round($sum / $count, 4),
            'numerator' => (string) $sum,
            'denominator' => (string) $count,
            'weight' => (string) $count,
            'reference' => 'employee_service_feedback',
        ];
    }
}
