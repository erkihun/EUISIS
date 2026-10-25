<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ManageBreakGlass;
use Illuminate\Console\Command;

/** Records emergency access whose time ran out (ADR 0010); the grant already gave nothing past its end. */
class ExpireBreakGlass extends Command
{
    protected $signature = 'core:break-glass-expire';

    protected $description = 'Mark break-glass grants past their end as expired';

    public function handle(ManageBreakGlass $breakGlass): int
    {
        $this->info($breakGlass->expire().' emergency access grant(s) expired.');

        return self::SUCCESS;
    }
}
