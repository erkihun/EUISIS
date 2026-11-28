<?php

declare(strict_types=1);

namespace Modules\Leave\Infrastructure\Console;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Support\Features;
use Illuminate\Console\Command;
use Modules\Leave\Application\Services\LeaveGrantService;

/**
 * Brings every active employee's leave ledger up to date: the yearly grant
 * of the current budget year and the closing of days that can no longer be
 * used. Safe to run any number of times.
 */
final class GrantLeaveYearCommand extends Command
{
    protected $signature = 'leave:grant-year {--employee= : Only this employee number}';

    protected $description = 'Write the yearly leave grants of the current budget year and close leave days that can no longer be used';

    public function handle(LeaveGrantService $grants): int
    {
        if (! Features::enabled('leave')) {
            $this->info('The leave module is switched off (FEATURE_LEAVE).');

            return self::SUCCESS;
        }

        $count = 0;
        $failed = 0;
        Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->when($this->option('employee'), fn ($query, $number) => $query->where('employee_number', $number))
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($grants, &$count, &$failed): void {
                foreach ($employees as $employee) {
                    try {
                        $grants->settle($employee);
                        $count++;
                    } catch (\Throwable $exception) {
                        $failed++;
                        report($exception);
                    }
                }
            });

        $this->info("Leave ledgers brought up to date: {$count}. Failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
