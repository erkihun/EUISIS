<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the shipped appearance colours from Tailwind's stock blue/orange to the
 * institutional navy and signal red.
 *
 * The defaults in SystemSettingsRegistry only apply to a setting that has no
 * row yet, and SystemSettingsSeeder has already written the old values into
 * every existing environment — so without this migration the new brand would
 * only ever appear on a fresh install.
 *
 * Rows are rewritten ONLY where they still hold the exact old shipped value. An
 * administrator who has deliberately chosen a colour keeps it; this is a
 * correction of a default, not an override of a decision.
 */
return new class extends Migration
{
    /** key => [old shipped value, new value] */
    private const COLORS = [
        'primary_color' => ['#2563EB', '#122170'],
        'secondary_color' => ['#1E40AF', '#1D3084'],
        'accent_color' => ['#F97316', '#D12908'],
    ];

    public function up(): void
    {
        $this->apply(fn (array $pair) => $pair);
    }

    public function down(): void
    {
        $this->apply(fn (array $pair) => [$pair[1], $pair[0]]);
    }

    /**
     * @param  callable(array{0: string, 1: string}): array{0: string, 1: string}  $direction
     */
    private function apply(callable $direction): void
    {
        $hasDefaultColumn = Schema::hasColumn('system_settings', 'default_value');

        foreach (self::COLORS as $key => $pair) {
            [$from, $to] = $direction($pair);

            $row = DB::table('system_settings')
                ->where('group', 'appearance')
                ->where('key', $key);

            $updates = [];

            /* Only touch a value the administrator has not changed. Comparison
               is case-insensitive because a hex typed by hand may differ in
               case from the seeded constant. */
            if ((clone $row)->whereRaw('UPPER(value) = ?', [strtoupper($from)])->exists()) {
                $updates['value'] = $to;
            }

            /* `default_value` records what the system ships, so it always
               follows — it is not a user choice. */
            if ($hasDefaultColumn) {
                $updates['default_value'] = $to;
            }

            if ($updates !== []) {
                $updates['updated_at'] = now();
                $row->update($updates);
            }
        }
    }
};
