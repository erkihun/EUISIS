<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Actions\CreateFiscalYear;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;

/** Creates an Ethiopian fiscal year and its four periods with the default schedule (D2, D5). */
final class CreateFiscalYearCommand extends Command
{
    protected $signature = 'service-monitoring:create-fiscal-year {ec_year : The Ethiopian fiscal year, e.g. 2019}';

    protected $description = 'Create a service-monitoring fiscal year and its four quarterly periods';

    public function handle(CreateFiscalYear $create, FiscalCalendar $calendar): int
    {
        $year = (int) $this->argument('ec_year');
        if ($year < 2000 || $year > 2100) {
            $this->error('The Ethiopian fiscal year must be between 2000 and 2100.');

            return self::FAILURE;
        }

        try {
            $fiscalYear = $create->execute($year, null);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->table(['Q', 'Starts', 'Ends', 'Opens', 'Due', 'Sub-city review', 'City review'], $fiscalYear->periods->map(fn ($p): array => [
            $p->quarter,
            $calendar->display($p->starts_on->toDateString(), 'en'),
            $calendar->display($p->ends_on->toDateString(), 'en'),
            $calendar->display($p->opens_on->toDateString(), 'en'),
            $calendar->display($p->submission_due_on->toDateString(), 'en'),
            $calendar->display($p->subcity_review_due_on->toDateString(), 'en'),
            $calendar->display($p->city_review_due_on->toDateString(), 'en'),
        ])->all());
        $this->info((string) __('service-monitoring.flash.fiscal_year_created', ['year' => $year], 'en'));

        return self::SUCCESS;
    }
}
