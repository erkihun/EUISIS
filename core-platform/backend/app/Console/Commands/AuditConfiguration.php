<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ConfigAudit;
use Illuminate\Console\Command;

/** The production configuration review (ADR 0011): fails in production when a required setting is wrong. */
class AuditConfiguration extends Command
{
    protected $signature = 'core:config-audit';

    protected $description = 'Review the running configuration against the production rules';

    public function handle(ConfigAudit $audit): int
    {
        $results = $audit->results();
        $this->table(['Setting', 'Result', 'Value', 'What to do'], array_map(fn (array $r): array => [$r['key'], $r['status'], $r['value'], $r['advice']], $results));
        $failed = array_filter($results, fn (array $r): bool => $r['status'] === 'fail');

        return $failed !== [] && app()->isProduction() ? self::FAILURE : self::SUCCESS;
    }
}
