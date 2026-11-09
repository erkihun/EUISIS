<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\ServiceMonitoring\Application\Services\ParticipantSuggester;

/**
 * Prints the participant registry entries the organization structure
 * suggests (ParticipantSuggester, D7) as a dry run; --apply registers the
 * applicable ones. A woreda without a published sub-city parent is listed
 * as skipped and never registered.
 */
final class SuggestParticipantsCommand extends Command
{
    protected $signature = 'service-monitoring:suggest-participants {--apply : Register the proposed participants} {--from= : Active-from date (Y-m-d), default today}';

    protected $description = 'Propose (and with --apply register) service-monitoring participants from the organization structure';

    public function handle(ParticipantSuggester $suggester): int
    {
        $proposals = $suggester->proposals();
        if ($proposals === []) {
            $this->info((string) __('service-monitoring.suggest.none'));

            return self::SUCCESS;
        }

        $this->table(array_values((array) __('service-monitoring.suggest.headers')), array_map(fn (array $p): array => [
            $p['name_en'],
            $p['level'],
            $p['level'] === 'woreda' ? ($p['parent_name_en'] ?? '— (skipped: no sub-city parent)') : '—',
            $p['reason'],
        ], $proposals));

        if (! $this->option('apply')) {
            $this->comment((string) __('service-monitoring.suggest.dry_run'));

            return self::SUCCESS;
        }

        $count = $suggester->apply($proposals, (string) ($this->option('from') ?: now()->toDateString()), null);
        $this->info((string) __('service-monitoring.suggest.applied', ['count' => $count]));

        return self::SUCCESS;
    }
}
