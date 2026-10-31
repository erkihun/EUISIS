<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\FiscalYearStatus;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** Creates an Ethiopian fiscal year and its four quarterly periods with the default schedule (D2, D5). */
final readonly class CreateFiscalYear
{
    public function __construct(private FiscalCalendar $calendar, private ServiceMonitoringAudit $audit) {}

    public function execute(int $ecYear, ?User $actor, string $formVersion = FormDefinition::CURRENT_VERSION): FiscalYear
    {
        FormDefinition::assertVersion($formVersion);
        if (FiscalYear::query()->where('ec_year', $ecYear)->exists()) {
            throw ValidationException::withMessages(['ec_year' => __('service-monitoring.validation.fiscal_year_exists', ['year' => $ecYear])]);
        }

        return DB::transaction(function () use ($ecYear, $actor, $formVersion): FiscalYear {
            [$start, $end] = $this->calendar->fiscalYear($ecYear);
            $year = FiscalYear::query()->create([
                'ec_year' => $ecYear,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'status' => FiscalYearStatus::Active,
                'created_by' => $actor?->id,
            ]);

            foreach ([1, 2, 3, 4] as $quarter) {
                $period = new ReportingPeriod([
                    'fiscal_year_id' => $year->id,
                    'quarter' => $quarter,
                    'form_version' => $formVersion,
                    ...$this->calendar->defaultSchedule($ecYear, $quarter),
                ]);
                $period->status = $period->effectiveStatus();
                $period->save();
            }

            $this->audit->record(AuditEventType::ServiceMonitoringFiscalYearCreated, $actor, $year, null, ['ec_year' => $ecYear, 'form_version' => $formVersion]);

            return $year->load('periods');
        });
    }
}
