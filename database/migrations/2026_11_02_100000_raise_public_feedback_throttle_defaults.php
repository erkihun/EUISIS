<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raises the shipped public feedback submit ceilings (ADR 0024).
 *
 * The throttle now spends these ceilings on stored submissions only, and the
 * defaults rise from 3 per QR code per address and 8 per address in ten
 * minutes to 10 and 30: whole service halls and the districts behind a mobile
 * carrier's shared address sit on one client address, and the old per-address
 * ceiling would have refused genuine submissions on a busy day.
 *
 * SystemSettingsSeeder has already written the old defaults into every existing
 * environment, so a row is rewritten ONLY where it still holds the exact old
 * shipped value. An administrator who chose another number keeps it.
 */
return new class extends Migration
{
    /** key => [old shipped value, new value] */
    private const CEILINGS = [
        'rate_limit_per_source' => ['3', '10'],
        'rate_limit_per_ip' => ['8', '30'],
    ];

    public function up(): void
    {
        $this->apply(fn (array $pair): array => $pair);
    }

    public function down(): void
    {
        $this->apply(fn (array $pair): array => [$pair[1], $pair[0]]);
    }

    /**
     * @param  callable(array{0: string, 1: string}): array{0: string, 1: string}  $direction
     */
    private function apply(callable $direction): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $hasDefaultColumn = Schema::hasColumn('system_settings', 'default_value');

        foreach (self::CEILINGS as $key => $pair) {
            [$from, $to] = $direction($pair);

            $row = DB::table('system_settings')
                ->where('group', 'service_feedback')
                ->where('key', $key);

            $updates = [];

            if ((clone $row)->where('value', $from)->exists()) {
                $updates['value'] = $to;
            }

            if ($hasDefaultColumn && (clone $row)->where('default_value', $from)->exists()) {
                $updates['default_value'] = $to;
            }

            if ($updates !== []) {
                $row->update($updates + ['updated_at' => now()]);
            }
        }
    }
};
