<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Organizations\AmharicOrganizationCodes;
use Illuminate\Console\Command;

class ApplyAmharicOrganizationCodes extends Command
{
    protected $signature = 'organizations:apply-amharic-codes';

    protected $description = 'Give existing organization types and live organizations codes in the Amharic prefix scheme';

    public function handle(AmharicOrganizationCodes $codes): int
    {
        $actor = User::role('Super Admin')->orderBy('id')->first();
        if ($actor === null) {
            $this->error('A Super Admin account must exist first.');

            return self::FAILURE;
        }

        $result = $codes->apply($actor);
        $this->info("Organization types changed: {$result['types']}. Organizations changed: {$result['organizations']}.");

        return self::SUCCESS;
    }
}
