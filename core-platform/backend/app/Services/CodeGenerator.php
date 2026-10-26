<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CodeRuleEntityType;
use App\Models\CodeRule;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Codes and numbers for new records. A typed code is kept as given. A blank
 * one comes from the active Code Rule for the entity (Code Rules screen, the
 * EUISIS generator) when there is one; otherwise from the format in Core
 * settings › Numbering, where each rendered pattern keeps its own counter, so
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
        'print_batch' => ['numbering.print_batch_number_format', 'card_print_batches', 'batch_number'],
    ];

    /** The Code Rules entity type of each kind the Core numbers; print batches have none in EUISIS. */
    private const RULE_ENTITIES = [
        'organization' => CodeRuleEntityType::Organization,
        'unit' => CodeRuleEntityType::OrganizationUnit,
        'position' => CodeRuleEntityType::Position,
        'employee' => CodeRuleEntityType::Employee,
        'card' => CodeRuleEntityType::IdCard,
    ];

    public function __construct(
        private readonly CoreSettings $settings,
        private readonly CodeRuleResolver $rules,
        private readonly CodeGeneratorService $ruleGenerator,
    ) {}

    /**
     * The code the next record of a kind would get, without taking it (the form's preview).
     *
     * @param  array<string, mixed>  $context  what the rule's tokens may need (organization_id, organization_unit_id, ...)
     */
    public function preview(string $entity, array $context = []): ?string
    {
        if (! isset(self::ENTITIES[$entity])) {
            return null;
        }
        $rule = $this->rule($entity, $context);
        if ($rule !== null) {
            try {
                return $this->ruleGenerator->preview($rule, $context);
            } catch (Throwable $exception) {
                $this->ruleUnusable($entity, $exception);
            }
        }
        [$setting, $table, $column] = self::ENTITIES[$entity];
        [$pattern, $width] = $this->pattern($setting);
        $last = (int) DB::table('code_sequences')->where('pattern', $pattern)->value('last_value');
        do {
            $last++;
            $code = str_replace('{seq}', str_pad((string) $last, $width, '0', STR_PAD_LEFT), $pattern);
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }

    /** @param array<string, mixed> $context */
    public function code(string $entity, ?string $manual = null, array $context = []): string
    {
        $manual = $manual === null ? '' : trim($manual);
        if ($manual !== '') {
            return $manual;
        }
        $rule = $this->rule($entity, $context);
        if ($rule !== null) {
            try {
                return $this->ruleGenerator->generate($rule, $context, auth()->user());
            } catch (Throwable $exception) {
                $this->ruleUnusable($entity, $exception);
            }
        }
        [$setting, $table, $column] = self::ENTITIES[$entity];
        [$pattern, $width] = $this->pattern($setting);

        return DB::transaction(function () use ($pattern, $width, $table, $column): string {
            // The first number of a pattern: two requests may both find no row, so the
            // row is created without failing on the other's insert, then locked by both.
            DB::table('code_sequences')->insertOrIgnore(['pattern' => $pattern, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $last = (int) DB::table('code_sequences')->where('pattern', $pattern)->lockForUpdate()->value('last_value');

            do {
                $last++;
                $code = str_replace('{seq}', str_pad((string) $last, $width, '0', STR_PAD_LEFT), $pattern);
            } while (DB::table($table)->where($column, $code)->exists());

            DB::table('code_sequences')->where('pattern', $pattern)->update(['last_value' => $last, 'updated_at' => now()]);

            return $code;
        });
    }

    /** @param array<string, mixed> $context */
    private function rule(string $entity, array $context): ?CodeRule
    {
        $type = self::RULE_ENTITIES[$entity] ?? null;
        if ($type === null) {
            return null;
        }
        try {
            return $this->rules->resolve($type, $context);
        } catch (Throwable $exception) {
            // Before the Code Rules tables exist (an older database) the settings format applies.
            $this->ruleUnusable($entity, $exception);

            return null;
        }
    }

    /** A rule that cannot produce a code here (a token without its context) must not stop the record; the settings format applies. */
    private function ruleUnusable(string $entity, Throwable $exception): void
    {
        Log::warning('Code rule not applied; the numbering format from settings is used.', ['entity' => $entity, 'error' => $exception->getMessage()]);
    }

    /** The settings format as a pattern with a {seq} placeholder, and the sequence width. @return array{0: string, 1: int} */
    private function pattern(string $setting): array
    {
        $format = (string) $this->settings->get($setting);
        preg_match('/\{seq:([1-9])\}/', $format, $match);

        return [str_replace(['{year}', $match[0] ?? '{seq}'], [now()->format('Y'), '{seq}'], $format), (int) ($match[1] ?? 4)];
    }
}
