<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cafeteria\Contracts\CafeteriaDirectory;
use Modules\ServiceMonitoring\Domain\Models\InstitutionBuilding;
use Modules\ServiceMonitoring\Domain\Models\PrefillValue;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * System figures shown beside three Section II inputs (D18), read from the
 * platform and never written back:
 *
 *   employees_excl_drivers     current, active assignments in the participant's own
 *                              organization (not its descendants), minus positions whose
 *                              occupation is on the driver list
 *   chest_badges_excl_drivers  active or issued ID cards of those employees
 *   cafeterias                 the organization's own active cafeteria locations (reference
 *                              only, through the Cafeteria module's public directory)
 *
 * With an empty driver list the first two are not computed and carry a notice.
 */
class PrefillService
{
    public function __construct(
        private readonly ServiceMonitoringSettings $settings,
        private readonly CafeteriaDirectory $cafeterias,
    ) {}

    public function capture(Submission $submission): void
    {
        $organizationId = $submission->organization_id;
        $drivers = $this->settings->driverOccupationIds();
        $now = now();

        $figures = [];
        if ($drivers === []) {
            $figures['employees_excl_drivers'] = [null, 'employee_assignments', 'driver_list_empty'];
            $figures['chest_badges_excl_drivers'] = [null, 'id_cards', 'driver_list_empty'];
        } else {
            $employees = $this->employeesExcludingDrivers($organizationId, $drivers);
            $figures['employees_excl_drivers'] = [(clone $employees)->distinct()->count('employee_id'), 'employee_assignments', null];
            $figures['chest_badges_excl_drivers'] = [
                IdCard::query()->whereIn('employee_id', (clone $employees)->select('employee_id'))
                    ->whereIn('status', [CardStatus::Active->value, CardStatus::Issued->value])
                    ->distinct()->count('employee_id'),
                'id_cards', null,
            ];
        }
        $figures['cafeteria_count'] = [
            count(array_filter($this->cafeterias->locations(), fn (array $l): bool => $l['organization_id'] === $organizationId && $l['is_active'])),
            'cafeteria_locations', null,
        ];

        $building = InstitutionBuilding::query()->firstOrCreate(['submission_id' => $submission->id]);
        foreach ($figures as $field => [$value, $source, $notice]) {
            PrefillValue::query()->updateOrCreate(
                ['submission_id' => $submission->id, 'field' => $field],
                ['system_value' => $value, 'source' => $source, 'notice' => $notice, 'captured_at' => $now],
            );
            // A convenience start value for the overridable figures; the cafeteria count stays
            // the user's, because the form counts only newly organized ones.
            if ($field !== 'cafeteria_count' && $value !== null && $building->{$field} === null) {
                $building->{$field} = $value;
            }
        }
        $building->save();
    }

    /**
     * @param  list<string>  $drivers
     * @return Builder<EmployeeAssignment>
     */
    private function employeesExcludingDrivers(string $organizationId, array $drivers): Builder
    {
        return EmployeeAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->where(fn (Builder $q): Builder => $q->whereNull('position_id')
                ->orWhereNotIn('position_id', Position::query()->select('id')->whereIn('occupation_id', $drivers)));
    }
}
