<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Codes and numbers for new records (EUISIS GenerateCodeAction, simplified):
 * a typed code is kept as given; a blank one is generated from the format in
 * Core settings › Numbering. Each rendered pattern keeps its own counter, so
 * {year} restarts the sequence every year. A generated value already taken
 * (imported data) is skipped.
 */
final class CodeGenerator
{
    /** setting name and the table.column the code must be unique in */
    private const ENTITIES = [
        'organization' => ['numbering.organization_code_format', 'organizations', 'code'],
        'unit' => ['numbering.unit_code_format', 'organization_units', 'code'],
        'position' => ['numbering.position_code_format', 'positions', 'job_position_code'],
        'employee' => ['numbering.employee_number_format', 'employees', 'employee_number'],
        'card' => ['numbering.card_number_format', 'id_cards', 'card_number'],
    ];

    public function __construct(private readonly CoreSettings $settings) {}

    /** The code the next record of a kind would get, without taking it (the form's preview). */
    public function preview(string $entity): ?string
    {
        if (! isset(self::ENTITIES[$entity])) {
            return null;
        }
        [$setting, $table, $column] = self::ENTITIES[$entity];
        $format = (string) $this->settings->get($setting);
        preg_match('/\{seq:([1-9])\}/', $format, $match);
        $pattern = str_replace(['{year}', $match[0]], [now()->format('Y'), '{seq}'], $format);
        $last = (int) DB::table('code_sequences')->where('pattern', $pattern)->value('last_value');
        do {
            $last++;
            $code = str_replace('{seq}', str_pad((string) $last, (int) $match[1], '0', STR_PAD_LEFT), $pattern);
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }

    public function code(string $entity, ?string $manual = null): string
    {
        $manual = $manual === null ? '' : trim($manual);
        if ($manual !== '') {
            return $manual;
        }
        [$setting, $table, $column] = self::ENTITIES[$entity];
        $format = (string) $this->settings->get($setting);
        preg_match('/\{seq:([1-9])\}/', $format, $match);
        $pattern = str_replace(['{year}', $match[0]], [now()->format('Y'), '{seq}'], $format);

        return DB::transaction(function () use ($pattern, $match, $table, $column): string {
            // The first number of a pattern: two requests may both find no row, so the
            // row is created without failing on the other's insert, then locked by both.
            DB::table('code_sequences')->insertOrIgnore(['pattern' => $pattern, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $last = (int) DB::table('code_sequences')->where('pattern', $pattern)->lockForUpdate()->value('last_value');

            do {
                $last++;
                $code = str_replace('{seq}', str_pad((string) $last, (int) $match[1], '0', STR_PAD_LEFT), $pattern);
            } while (DB::table($table)->where($column, $code)->exists());

            DB::table('code_sequences')->where('pattern', $pattern)->update(['last_value' => $last, 'updated_at' => now()]);

            return $code;
        });
    }
}
