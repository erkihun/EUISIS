<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use Illuminate\Support\Collection;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Whether each section is complete (D10): either it passes its rules or it is
 * flagged "የለም" and empty. Submission is refused while any section has a
 * problem. Also finds Section I employees already reported in an earlier
 * accepted quarter of the same fiscal year (D14).
 */
class SubmissionValidator
{
    /**
     * Problems per section (1–4); an empty array means the report may be submitted.
     *
     * @return array<int, list<string>>
     */
    public function problems(Submission $submission): array
    {
        $submission->loadMissing(['accountabilityEntries', 'institutionBuilding', 'prefillValues', 'digitalServices', 'serviceTotals', 'standardPerformance']);
        $problems = array_filter([
            1 => $this->section1($submission),
            2 => $this->section2($submission),
            3 => $this->section3($submission),
            4 => $this->section4($submission),
        ]);

        return $problems;
    }

    /** @return array<int, bool> section => complete */
    public function completeness(Submission $submission): array
    {
        $problems = $this->problems($submission);

        return [1 => ! isset($problems[1]), 2 => ! isset($problems[2]), 3 => ! isset($problems[3]), 4 => ! isset($problems[4])];
    }

    /** @return list<string> */
    private function section1(Submission $submission): array
    {
        $entries = $submission->accountabilityEntries;
        if ($submission->section_1_nothing) {
            return $entries->isEmpty() ? [] : [__('service-monitoring.validation.nothing_not_empty', ['section' => 1])];
        }
        if ($entries->isEmpty()) {
            return [__('service-monitoring.validation.section_incomplete', ['section' => 1])];
        }
        $unconfirmed = $this->repeatEmployeeIds($submission)->diff($entries->where('repeat_confirmed', true)->pluck('employee_id'));

        return $unconfirmed->isEmpty() ? [] : [__('service-monitoring.validation.repeat_employee')];
    }

    /** @return list<string> */
    private function section2(Submission $submission): array
    {
        $building = $submission->institutionBuilding;
        if ($building === null) {
            return [__('service-monitoring.validation.section_incomplete', ['section' => 2])];
        }
        $problems = [];
        foreach (FormDefinition::section2Keys() as $field) {
            if ($building->{$field} === null) {
                return [__('service-monitoring.validation.section_incomplete', ['section' => 2])];
            }
        }
        if ($submission->section_2_nothing) {
            $nonZero = array_values(array_filter(FormDefinition::section2Flow(), fn (string $f): bool => (int) $building->{$f} !== 0));
            if ($nonZero !== []) {
                $problems[] = __('service-monitoring.validation.flow_must_be_zero', ['fields' => implode(', ', array_map(fn (string $f): string => (string) __("service-monitoring.form.section_2.fields.{$f}"), $nonZero))]);
            }
        }
        foreach ($submission->prefillValues as $prefill) {
            if (! in_array($prefill->field, FormDefinition::overridableFields(), true) || $prefill->system_value === null) {
                continue;
            }
            if ((int) $building->{$prefill->field} !== $prefill->system_value && mb_strlen(trim((string) $prefill->override_reason)) < FormDefinition::MIN_OVERRIDE_REASON) {
                $problems[] = __('service-monitoring.validation.override_reason', ['system' => $prefill->system_value, 'min' => FormDefinition::MIN_OVERRIDE_REASON]);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function section3(Submission $submission): array
    {
        $totals = $submission->serviceTotals;
        if ($totals === null || $totals->main_total === null || $totals->sub_total === null) {
            return [__('service-monitoring.validation.totals_required')];
        }
        if ($submission->section_3_nothing) {
            return $submission->digitalServices->isEmpty() ? [] : [__('service-monitoring.validation.nothing_not_empty', ['section' => 3])];
        }

        return $submission->digitalServices->isEmpty() ? [__('service-monitoring.validation.section_incomplete', ['section' => 3])] : [];
    }

    /** @return list<string> */
    private function section4(Submission $submission): array
    {
        $totals = $submission->serviceTotals;
        $performance = $submission->standardPerformance;
        if ($submission->section_4_nothing) {
            $entered = $performance !== null && collect(FormDefinition::SECTION_4)->contains(fn (string $f): bool => (int) $performance->{$f} > 0);

            return $entered ? [__('service-monitoring.validation.nothing_not_empty', ['section' => 4])] : [];
        }
        if ($totals === null || $totals->main_total === null || $totals->sub_total === null) {
            return [__('service-monitoring.validation.totals_required')];
        }
        if ($performance === null || collect(FormDefinition::SECTION_4)->contains(fn (string $f): bool => $performance->{$f} === null)) {
            return [__('service-monitoring.validation.section_incomplete', ['section' => 4])];
        }

        return self::section4Problems($performance->only(FormDefinition::SECTION_4) + $performance->only(['below_reasons', 'actions_taken']), (int) $totals->main_total, (int) $totals->sub_total);
    }

    /**
     * The Section IV rules on their own, for the save endpoint and the screen:
     * above + at + below = total for main and for sub services, and reasons
     * plus actions (≥ 20 characters each) when anything is below standard.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function section4Problems(array $values, int $mainTotal, int $subTotal): array
    {
        $problems = [];
        foreach (['main' => $mainTotal, 'sub' => $subTotal] as $level => $total) {
            $sum = (int) ($values["{$level}_above"] ?? 0) + (int) ($values["{$level}_at"] ?? 0) + (int) ($values["{$level}_below"] ?? 0);
            if ($sum !== $total) {
                $problems[] = __('service-monitoring.validation.sum_mismatch', ['sum' => $sum, 'total' => $total, 'level' => __("service-monitoring.enums.service_level.{$level}")]);
            }
        }
        if ((int) ($values['main_below'] ?? 0) > 0 || (int) ($values['sub_below'] ?? 0) > 0) {
            foreach (['below_reasons', 'actions_taken'] as $field) {
                if (mb_strlen(trim((string) ($values[$field] ?? ''))) < FormDefinition::MIN_BELOW_STANDARD_TEXT) {
                    $problems[] = __('service-monitoring.validation.below_text', ['min' => FormDefinition::MIN_BELOW_STANDARD_TEXT]);
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * Employees of this submission already reported in an accepted submission
     * of an earlier quarter of the same fiscal year (any participant: people move).
     *
     * @return Collection<int, string>
     */
    public function repeatEmployeeIds(Submission $submission): Collection
    {
        $employeeIds = $submission->accountabilityEntries->pluck('employee_id')->filter()->values();
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return $this->earlierAcceptedEmployees($submission, $employeeIds->all());
    }

    /**
     * @param  list<string>  $employeeIds
     * @return Collection<int, string>
     */
    public function earlierAcceptedEmployees(Submission $submission, array $employeeIds): Collection
    {
        $period = $submission->period;

        return AccountabilityEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereHas('submission', fn ($q) => $q->where('status', SubmissionStatus::Accepted->value)
                ->whereHas('period', fn ($p) => $p->where('fiscal_year_id', $period->fiscal_year_id)->where('quarter', '<', $period->quarter)))
            ->distinct()
            ->pluck('employee_id')
            ->map(fn ($id): string => (string) $id);
    }
}
