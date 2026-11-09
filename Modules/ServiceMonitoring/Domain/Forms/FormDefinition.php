<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Forms;

use InvalidArgumentException;
use Modules\ServiceMonitoring\Domain\Enums\FieldAggregation;
use Modules\ServiceMonitoring\Domain\Enums\FinalAction;
use Modules\ServiceMonitoring\Domain\Enums\FormType;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Enums\RoleCategory;

/**
 * The official forms (FY 2019 E.C., Form A for center institutions and Form B
 * for sub-city and woreda institutions) in one place: sections, fields, how
 * each figure aggregates across quarters (D4), the header text of each form
 * version (D17) and the order of columns. Validation, the entry screens (as
 * typed props), aggregation and the exports all read this class, so a field
 * is added or re-labelled here or nowhere.
 *
 * Labels live in lang/{am,en}/service-monitoring.php under `form.`; the
 * Amharic text is the forms' own wording.
 */
final class FormDefinition
{
    public const CURRENT_VERSION = 'fy2019';

    /** @var list<string> */
    public const VERSIONS = ['fy2019'];

    public const MIN_OVERRIDE_REASON = 10;

    public const MIN_BELOW_STANDARD_TEXT = 20;

    /**
     * Section II, in the form's column order. `prefill` fields show a system
     * figure (D18); `reference` ones show it without asking for a reason;
     * `newly_organized` ones carry the "only newly organized" rule where the
     * form version has it (D19).
     *
     * @var array<string, array{aggregation: FieldAggregation, group: ?string, newly_organized: bool, prefill: bool, reference: bool}>
     */
    private const SECTION_2 = [
        'daycare_count' => ['aggregation' => FieldAggregation::Flow, 'group' => null, 'newly_organized' => true, 'prefill' => false, 'reference' => false],
        'cafeteria_count' => ['aggregation' => FieldAggregation::Flow, 'group' => null, 'newly_organized' => true, 'prefill' => true, 'reference' => true],
        'consumer_shop_count' => ['aggregation' => FieldAggregation::Flow, 'group' => null, 'newly_organized' => true, 'prefill' => false, 'reference' => false],
        'waiting_area_count' => ['aggregation' => FieldAggregation::Stock, 'group' => 'waiting_area', 'newly_organized' => false, 'prefill' => false, 'reference' => false],
        'waiting_area_capacity' => ['aggregation' => FieldAggregation::Stock, 'group' => 'waiting_area', 'newly_organized' => false, 'prefill' => false, 'reference' => false],
        'suggestion_box_count' => ['aggregation' => FieldAggregation::Stock, 'group' => null, 'newly_organized' => false, 'prefill' => false, 'reference' => false],
        'led_count' => ['aggregation' => FieldAggregation::Stock, 'group' => null, 'newly_organized' => false, 'prefill' => false, 'reference' => false],
        'signage_count' => ['aggregation' => FieldAggregation::Stock, 'group' => null, 'newly_organized' => false, 'prefill' => false, 'reference' => false],
        'employees_excl_drivers' => ['aggregation' => FieldAggregation::Stock, 'group' => null, 'newly_organized' => false, 'prefill' => true, 'reference' => false],
        'chest_badges_excl_drivers' => ['aggregation' => FieldAggregation::Stock, 'group' => null, 'newly_organized' => false, 'prefill' => true, 'reference' => false],
        'desk_badges' => ['aggregation' => FieldAggregation::Stock, 'group' => null, 'newly_organized' => false, 'prefill' => false, 'reference' => false],
        'awareness_trainings' => ['aggregation' => FieldAggregation::Flow, 'group' => null, 'newly_organized' => false, 'prefill' => false, 'reference' => false],
        'awareness_participants' => ['aggregation' => FieldAggregation::Flow, 'group' => null, 'newly_organized' => false, 'prefill' => false, 'reference' => false],
    ];

    /** Section IV counts: assessed per quarter, so summed across quarters (D4). */
    public const SECTION_4 = ['main_above', 'main_at', 'main_below', 'sub_above', 'sub_at', 'sub_below'];

    /** Institution service totals (Sections III and IV): stock. */
    public const SERVICE_TOTALS = ['main_total', 'sub_total'];

    /** "Only newly organized facilities count" is printed on Form B only (D19). */
    private const NEWLY_ORGANIZED_RULE = ['fy2019' => ['A' => false, 'B' => true]];

    public static function assertVersion(string $version): void
    {
        if (! in_array($version, self::VERSIONS, true)) {
            throw new InvalidArgumentException("Unknown service monitoring form version: {$version}");
        }
    }

    /** @return list<string> */
    public static function section2Keys(): array
    {
        return array_keys(self::SECTION_2);
    }

    public static function aggregationOf(string $field): FieldAggregation
    {
        if (isset(self::SECTION_2[$field])) {
            return self::SECTION_2[$field]['aggregation'];
        }
        if (in_array($field, self::SECTION_4, true)) {
            return FieldAggregation::Flow;
        }
        if (in_array($field, self::SERVICE_TOTALS, true)) {
            return FieldAggregation::Stock;
        }

        throw new InvalidArgumentException("Unknown service monitoring field: {$field}");
    }

    /** @return list<string> */
    public static function section2Flow(): array
    {
        return array_keys(array_filter(self::SECTION_2, fn (array $f): bool => $f['aggregation'] === FieldAggregation::Flow));
    }

    /** @return list<string> */
    public static function section2Stock(): array
    {
        return array_keys(array_filter(self::SECTION_2, fn (array $f): bool => $f['aggregation'] === FieldAggregation::Stock));
    }

    /**
     * Fields whose system figure must be explained when the report differs (D18).
     *
     * @return list<string>
     */
    public static function overridableFields(): array
    {
        return array_keys(array_filter(self::SECTION_2, fn (array $f): bool => $f['prefill'] && ! $f['reference']));
    }

    /** @return list<string> */
    public static function prefillFields(): array
    {
        return array_keys(array_filter(self::SECTION_2, fn (array $f): bool => $f['prefill']));
    }

    public static function newlyOrganizedRule(FormType $type, string $version = self::CURRENT_VERSION): bool
    {
        self::assertVersion($version);

        return self::NEWLY_ORGANIZED_RULE[$version][$type->value];
    }

    /**
     * The Section I.1 tables a form has: one for Form A, sub-city and woreda for Form B.
     *
     * @return list<ReportingLevel>
     */
    public static function registerTables(FormType $type): array
    {
        return $type === FormType::A ? [ReportingLevel::Center] : [ReportingLevel::SubCity, ReportingLevel::Woreda];
    }

    /**
     * I.2 column groups: the same as the register tables.
     *
     * @return list<ReportingLevel>
     */
    public static function summaryGroups(FormType $type): array
    {
        return self::registerTables($type);
    }

    /**
     * I.2 rows, in the form's order (the total row is added by the summary).
     *
     * @return list<FinalAction>
     */
    public static function summaryRows(): array
    {
        return FinalAction::cases();
    }

    /** @return list<RoleCategory> */
    public static function roleColumns(): array
    {
        return RoleCategory::cases();
    }

    /**
     * Header of a role column as printed on the form version (D17): Form A's
     * summary reads "ሹመኛ/ሜሪት+ሹመት/" where Form B's reads "ሹመኛ አመራር".
     */
    public static function roleHeader(RoleCategory $role, FormType $type, bool $summary, ?string $locale = null): string
    {
        $key = $summary ? "service-monitoring.form.summary_roles.{$type->value}.{$role->value}" : "service-monitoring.form.register_roles.{$role->value}";

        return (string) __($key, [], $locale);
    }

    /**
     * Everything the entry screens and exports need to draw a form: titles,
     * column headings in order, field metadata and the printed notes.
     *
     * @return array<string, mixed>
     */
    public static function describe(FormType $type, string $version, int $ecYear, ?string $locale = null): array
    {
        self::assertVersion($version);
        $t = fn (string $key, array $replace = []): string => (string) __("service-monitoring.form.{$key}", ['year' => $ecYear, ...$replace], $locale);
        $newlyOrganized = self::newlyOrganizedRule($type, $version);

        return [
            'version' => $version,
            'form_type' => $type->value,
            'ec_year' => $ecYear,
            'newly_organized_rule' => $newlyOrganized,
            'title' => $t("title.{$type->value}"),
            'general_notes' => array_map(fn (int $n): string => $t("general_notes.{$n}"), range(1, 6)),
            'section_1' => [
                'title' => $t('section_1.title'),
                'heading' => $t("section_1.heading.{$type->value}"),
                'tables' => array_map(fn (ReportingLevel $level): array => [
                    'level' => $level->value,
                    'title' => $t("section_1.tables.{$level->value}"),
                    'institution_column' => $t($level === ReportingLevel::Woreda ? 'section_1.columns.woreda' : 'section_1.columns.institution'),
                    'remark_column' => $t($level === ReportingLevel::Woreda ? 'section_1.columns.remark_woreda' : 'section_1.columns.remark'),
                ], self::registerTables($type)),
                'columns' => [
                    ['key' => 'sequence', 'label' => $t('columns.sequence')],
                    ['key' => 'full_name', 'label' => $t('section_1.columns.full_name')],
                    ['key' => 'reason_detail', 'label' => $t('section_1.columns.reason_detail')],
                    ['key' => 'action_detail', 'label' => $t('section_1.columns.action_detail')],
                ],
                'role_group' => $t('section_1.columns.role_group'),
                'role_columns' => array_map(fn (RoleCategory $r): array => ['value' => $r->value, 'label' => self::roleHeader($r, $type, false, $locale)], self::roleColumns()),
                'summary' => [
                    'title' => $t('section_1.summary.title'),
                    'action_column' => $t('section_1.summary.action_column'),
                    'rows' => array_map(fn (FinalAction $a): array => ['value' => $a->value, 'label' => $t("section_1.summary.rows.{$a->value}")], self::summaryRows()),
                    'total' => $t('columns.total'),
                    'groups' => array_map(fn (ReportingLevel $l): array => ['level' => $l->value, 'label' => $t("section_1.summary.groups.{$l->value}")], self::summaryGroups($type)),
                    'role_columns' => array_map(fn (RoleCategory $r): array => ['value' => $r->value, 'label' => self::roleHeader($r, $type, true, $locale)], self::roleColumns()),
                    'remark' => $t('columns.remark'),
                    'notes' => array_map(fn (string $n): string => $t("section_1.summary.notes.{$n}"), ['a', 'b', 'c']),
                ],
            ],
            'section_2' => [
                'title' => $t('section_2.title'),
                'institution_column' => $t('section_1.columns.institution'),
                'fields' => array_map(fn (string $key): array => [
                    'key' => $key,
                    'label' => $t("section_2.fields.{$key}"),
                    'aggregation' => self::SECTION_2[$key]['aggregation']->value,
                    'group' => self::SECTION_2[$key]['group'],
                    'group_label' => self::SECTION_2[$key]['group'] !== null ? $t('section_2.groups.'.self::SECTION_2[$key]['group']) : null,
                    'newly_organized' => $newlyOrganized && self::SECTION_2[$key]['newly_organized'],
                    'prefill' => self::SECTION_2[$key]['prefill'],
                    'reference' => self::SECTION_2[$key]['reference'],
                ], self::section2Keys()),
                'narrative' => $t('section_2.narrative'),
                'notes' => $newlyOrganized ? [$t('section_2.notes.newly_organized')] : [],
            ],
            'section_3' => [
                'title' => $t('section_3.title'),
                'columns' => [
                    'main_total' => $t('section_3.columns.main_total'),
                    'sub_total' => $t('section_3.columns.sub_total'),
                    'digital_group' => $t('section_3.columns.digital_group'),
                    'digital_main' => $t('section_3.columns.digital_main'),
                    'digital_sub' => $t('section_3.columns.digital_sub'),
                    'service_name' => $t('section_3.columns.service_name'),
                    'technology' => $t('section_3.columns.technology'),
                    'system_count' => $t('section_3.columns.system_count'),
                    'remark' => $t('columns.remark'),
                ],
                'notes' => [$t('section_3.notes.verified'), $t('section_3.notes.current_year')],
            ],
            'section_4' => [
                'title' => $t('section_4.title'),
                'columns' => [
                    'main_total' => $t('section_4.columns.main_total'),
                    'sub_total' => $t('section_4.columns.sub_total'),
                    'main_group' => $t('section_4.columns.main_group'),
                    'sub_group' => $t('section_4.columns.sub_group'),
                    'above' => $t('section_4.columns.above'),
                    'at' => $t('section_4.columns.at'),
                    'below' => $t('section_4.columns.below'),
                    'below_reasons' => $t('section_4.columns.below_reasons'),
                    'actions_taken' => $t('section_4.columns.actions_taken'),
                ],
                'notes' => [],
            ],
        ];
    }
}
