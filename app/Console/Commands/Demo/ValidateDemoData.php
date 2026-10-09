<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Support\Demo\DemoDataValidator;
use Illuminate\Console\Command;

/**
 * Read-only: checks the development / QA / UAT demo dataset
 * (docs/demo-seed-data.md). Exit code 1 when any check fails.
 */
class ValidateDemoData extends Command
{
    protected $signature = 'demo-data:validate';

    protected $description = 'Read-only: check the demo dataset (organizations, structure, employees, cafeterias, policies, transactions)';

    public function handle(DemoDataValidator $validator): int
    {
        $this->components->warn('DEVELOPMENT / QA / UAT DEMO DATA');

        $failed = 0;
        foreach ($validator->validate() as $result) {
            $this->components->twoColumnDetail(
                $result['check'].($result['detail'] !== '' ? " <fg=gray>({$result['detail']})</>" : ''),
                $result['passed'] ? '<fg=green>OK</>' : '<fg=red>FAIL</>',
            );
            $failed += $result['passed'] ? 0 : 1;
        }

        foreach ($validator->notes() as $note) {
            $this->components->info($note);
        }

        $this->newLine();
        foreach ($validator->counts() as $label => $count) {
            $this->line(sprintf('  %-22s %d', $label.':', $count));
        }

        if ($failed > 0) {
            $this->components->error("{$failed} check(s) failed.");

            return self::FAILURE;
        }

        $this->components->info('All demo data checks passed.');

        return self::SUCCESS;
    }
}
