<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Import;

use App\Enums\ProcessType;
use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\LegalSource;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\OrganizationUnit;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\ServiceType;

/**
 * What each kind of import sheet looks like (ADR-LB-004): its columns, which
 * are required, how a reference column resolves to an existing record, and
 * which column is the row's own code. One definition drives parsing,
 * validation, the downloadable template and the commit.
 *
 * Reference columns resolve by CODE inside the organization (or city-level
 * where the kind allows), never by name: a name-only match is a suggestion
 * the person confirms in the mapping screen.
 */
final class DesignImportDefinitions
{
    public const TYPES = ['mandates', 'functions', 'services', 'processes', 'activities', 'tasks', 'work_standards'];

    /**
     * @return array<string, array{
     *   code: string|null,
     *   columns: array<string, array{required?: bool, type?: string, max?: int, in?: list<string>}>,
     *   references: array<string, array{model: class-string, column: string, scope: string, multiple?: bool, label: string}>
     * }>
     */
    public static function all(): array
    {
        $date = ['type' => 'date'];
        $text = ['type' => 'string', 'max' => 255];
        $long = ['type' => 'string', 'max' => 5000];

        return [
            'mandates' => [
                'code' => 'code',
                'columns' => [
                    'code' => ['required' => true, 'type' => 'code', 'max' => 40], 'title_en' => ['required' => true, ...$text], 'title_am' => $text,
                    'legal_instrument_type_code' => ['required' => true, ...$text], 'legal_instrument_number' => ['required' => true, 'type' => 'string', 'max' => 100],
                    'legal_reference' => $text, 'article_reference' => $text, 'issuing_authority' => $text, 'legal_source_code' => $text,
                    'effective_from' => ['required' => true, ...$date], 'effective_to' => $date, 'description_en' => $long, 'description_am' => $long, 'legacy_code' => ['type' => 'string', 'max' => 120],
                ],
                'references' => [
                    'legal_instrument_type_code' => ['model' => LegalInstrumentType::class, 'column' => 'code', 'scope' => 'global', 'label' => 'legal_instrument_type'],
                    'legal_source_code' => ['model' => LegalSource::class, 'column' => 'code', 'scope' => 'citable', 'label' => 'legal_source'],
                ],
            ],
            'functions' => [
                'code' => 'code',
                'columns' => [
                    'code' => ['required' => true, 'type' => 'code', 'max' => 40], 'title_en' => ['required' => true, ...$text], 'title_am' => $text,
                    'mandate_code' => ['required' => true, ...$text], 'power_code' => $text, 'parent_function_code' => $text, 'legal_reference' => $text,
                    'sequence' => ['type' => 'integer'], 'effective_from' => $date, 'effective_to' => $date, 'description_en' => $long, 'description_am' => $long, 'legacy_code' => ['type' => 'string', 'max' => 120],
                ],
                'references' => [
                    'mandate_code' => ['model' => InstitutionMandate::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'mandate'],
                    'power_code' => ['model' => OrganizationPower::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'power'],
                    'parent_function_code' => ['model' => OrganizationFunction::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'function'],
                ],
            ],
            'services' => [
                'code' => 'code',
                'columns' => [
                    'code' => ['required' => true, 'type' => 'code', 'max' => 40], 'name_en' => ['required' => true, ...$text], 'name_am' => $text,
                    'function_codes' => $text, 'service_type_code' => $text, 'beneficiary_type' => ['type' => 'string', 'max' => 40], 'delivery_channel' => ['type' => 'string', 'max' => 40],
                    'legal_basis' => $text, 'owner_unit_code' => $text, 'effective_from' => ['required' => true, ...$date], 'effective_to' => $date,
                    'description_en' => $long, 'description_am' => $long, 'legacy_code' => ['type' => 'string', 'max' => 120],
                ],
                'references' => [
                    'function_codes' => ['model' => OrganizationFunction::class, 'column' => 'code', 'scope' => 'organization', 'multiple' => true, 'label' => 'function'],
                    'service_type_code' => ['model' => ServiceType::class, 'column' => 'code', 'scope' => 'global', 'label' => 'service_type'],
                    'owner_unit_code' => ['model' => OrganizationUnit::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'unit'],
                ],
            ],
            'processes' => [
                'code' => 'code',
                'columns' => [
                    'code' => ['required' => true, 'type' => 'code', 'max' => 40], 'name_en' => ['required' => true, ...$text], 'name_am' => $text,
                    'service_code' => ['required' => true, ...$text], 'process_type' => ['type' => 'string', 'in' => ProcessType::values()], 'owner_unit_code' => $text,
                    'effective_from' => ['required' => true, ...$date], 'effective_to' => $date, 'description_en' => $long, 'description_am' => $long, 'legacy_code' => ['type' => 'string', 'max' => 120],
                ],
                'references' => [
                    'service_code' => ['model' => Service::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'service'],
                    'owner_unit_code' => ['model' => OrganizationUnit::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'unit'],
                ],
            ],
            'activities' => [
                'code' => 'code',
                'columns' => [
                    'code' => ['required' => true, 'type' => 'code', 'max' => 40], 'name_en' => ['required' => true, ...$text], 'name_am' => $text,
                    'process_code' => ['required' => true, ...$text], 'parent_activity_code' => $text, 'sequence' => ['type' => 'integer'],
                    'description_en' => $long, 'description_am' => $long, 'legacy_code' => ['type' => 'string', 'max' => 120],
                ],
                'references' => [
                    'process_code' => ['model' => Process::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'process'],
                    'parent_activity_code' => ['model' => ProcessActivity::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'activity'],
                ],
            ],
            'tasks' => [
                'code' => 'code',
                'columns' => [
                    'code' => ['required' => true, 'type' => 'code', 'max' => 40], 'name_en' => ['required' => true, ...$text], 'name_am' => $text,
                    'activity_code' => ['required' => true, ...$text], 'sequence' => ['type' => 'integer'], 'required_output' => $long,
                    'description_en' => $long, 'description_am' => $long, 'legacy_code' => ['type' => 'string', 'max' => 120],
                ],
                'references' => [
                    'activity_code' => ['model' => ProcessActivity::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'activity'],
                ],
            ],
            'work_standards' => [
                'code' => null,
                'columns' => [
                    'task_code' => ['required' => true, ...$text], 'standard_measure' => $text, 'bpr_reference' => $text,
                    'planned_quantity' => ['type' => 'decimal'], 'quantity_unit' => ['type' => 'string', 'max' => 60], 'planned_time_minutes' => ['type' => 'decimal'],
                    'planned_quality' => ['type' => 'decimal'], 'quality_unit' => ['type' => 'string', 'max' => 60], 'quality_measure' => $long, 'quality_source' => $text,
                    'effective_from' => ['required' => true, ...$date], 'effective_to' => $date, 'legacy_code' => ['type' => 'string', 'max' => 120],
                ],
                'references' => [
                    'task_code' => ['model' => ProcessTask::class, 'column' => 'code', 'scope' => 'organization', 'label' => 'task'],
                ],
            ],
        ];
    }

    /** @return array{code: string|null, columns: array<string, array<string, mixed>>, references: array<string, array<string, mixed>>} */
    public static function for(string $type): array
    {
        $all = self::all();
        if (! isset($all[$type])) {
            throw new \InvalidArgumentException("Unknown design import type [{$type}].");
        }

        return $all[$type];
    }

    /** @return list<string> */
    public static function columns(string $type): array
    {
        return array_keys(self::for($type)['columns']);
    }
}
