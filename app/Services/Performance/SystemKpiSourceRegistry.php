<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\TransferStatus;
use App\Models\EmployeeServiceFeedback;
use App\Models\EmployeeTransfer;
use App\Models\IdCard;
use App\Services\Performance\Calculation\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * KPI actuals the system already knows (docs/epms-calculation-rules.md §5):
 * nobody should type a number a module already records. Each source returns
 * value/numerator/denominator/weight for an organization (and optionally an
 * employee) and a period, from fixed, parameterized queries only.
 */
final class SystemKpiSourceRegistry
{
    /** Client ratings collected through the employee QR feedback form. */
    public const SERVICE_FEEDBACK = 'service_feedback.average_rating';

    /** @return array<string, array{label_en: string, label_am: string, level: string}> */
    public static function sources(): array
    {
        return [
            'id_cards.issued' => ['label_en' => 'ID cards issued to the organization\'s employees', 'label_am' => 'ለተቋሙ ሠራተኞች የተሰጡ መታወቂያዎች', 'level' => 'organization'],
            'employee_transfers.completed' => ['label_en' => 'Employee transfers completed (in or out)', 'label_am' => 'የተጠናቀቁ የሠራተኛ ዝውውሮች', 'level' => 'organization'],
            self::SERVICE_FEEDBACK => ['label_en' => 'Average client feedback rating of position services used for performance evaluation', 'label_am' => 'ለአፈጻጸም ምዘና በተመረጡ የሥራ መደብ አገልግሎቶች ላይ የተገልጋዮች አማካይ ግብረ መልስ ደረጃ', 'level' => 'employee'],
        ];
    }

    public static function has(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::sources());
    }

    /**
     * @param  array{position_id?: string, position_service_id?: string, organization_unit_ids?: list<string>}  $scope  narrows sources that know these dimensions
     * @return array{value: ?string, numerator: ?string, denominator: ?string, weight: ?string, reference: string}
     */
    public function measure(string $key, string $organizationId, ?string $employeeId, Carbon $from, Carbon $to, array $scope = []): array
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
            self::SERVICE_FEEDBACK => $this->average($organizationId, $employeeId, $from, $end, $scope),
            default => throw new \InvalidArgumentException("Unknown system KPI source [{$key}]."),
        };
    }

    /** @return array{value: ?string, numerator: ?string, denominator: ?string, weight: ?string, reference: string} */
    private function count(int $count, string $reference): array
    {
        return ['value' => (string) $count, 'numerator' => null, 'denominator' => null, 'weight' => null, 'reference' => $reference];
    }

    /**
     * Client ratings of services marked for performance evaluation.
     *
     * Feedback carries the organization, position and service it was given
     * for, frozen at submission, so a transferred employee's old ratings stay
     * with the old position. Every review status counts: review moderates the
     * comment, and a hidden comment's rating still counts (ServiceFeedbackStatus).
     *
     * @param  array{position_id?: string, position_service_id?: string, organization_unit_ids?: list<string>}  $scope
     * @return array{value: ?string, numerator: ?string, denominator: ?string, weight: ?string, reference: string}
     */
    private function average(string $organizationId, ?string $employeeId, Carbon $from, Carbon $end, array $scope): array
    {
        $query = EmployeeServiceFeedback::query()->whereBetween('created_at', [$from->copy()->startOfDay(), $end])
            ->where('organization_id', $organizationId)
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->when(isset($scope['position_id']), fn ($q) => $q->where('position_id', $scope['position_id']))
            ->when(isset($scope['position_service_id']), fn ($q) => $q->where('position_service_id', $scope['position_service_id']))
            ->when(isset($scope['organization_unit_ids']), fn ($q) => $q->whereIn('organization_unit_id', $scope['organization_unit_ids']))
            // A retired service keeps its ratings; an advisory one never counted.
            ->whereHas('positionService', fn ($service) => $service->withTrashed()->where('is_performance_evaluation_enabled', true));
        $count = (clone $query)->count();
        $sum = (string) (int) (clone $query)->sum('rating');

        if ($count === 0) {
            // No rating is not a rating of zero.
            return ['value' => null, 'numerator' => null, 'denominator' => null, 'weight' => null, 'reference' => 'employee_service_feedback'];
        }

        // Weighted by response count so a parent re-averages correctly.
        return [
            'value' => Dec::str(Dec::div(BigDecimal::of($sum), BigDecimal::of($count))),
            'numerator' => $sum,
            'denominator' => (string) $count,
            'weight' => (string) $count,
            'reference' => 'employee_service_feedback',
        ];
    }
}
