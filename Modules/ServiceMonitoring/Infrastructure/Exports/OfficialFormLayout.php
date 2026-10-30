<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Infrastructure\Exports;

use Modules\ServiceMonitoring\Domain\Enums\FinalAction;
use Modules\ServiceMonitoring\Domain\Enums\FormType;
use Modules\ServiceMonitoring\Domain\Enums\ReasonCategory;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Enums\RoleCategory;
use Modules\ServiceMonitoring\Domain\Enums\TechnologyType;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;

/**
 * The official form tables (D25) as plain structures both the XLSX and the
 * PDF render: the same section titles, column headings and groupings, ተ/ቁ
 * numbering, the totals row and the printed notes. Headings come from
 * FormDefinition in the export's language.
 *
 * A table is {title, header: rows of {text, colspan, rowspan}, body: rows of
 * scalars, total_rows: indices of body rows that are totals, notes}.
 */
final class OfficialFormLayout
{
    /**
     * @param  array<string, mixed>  $report  ConsolidationService::report()
     * @return array<string, list<array<string, mixed>>> sheet key => tables
     */
    public static function sections(FormType $type, string $version, int $ecYear, array $report, bool $named, string $locale): array
    {
        $definition = FormDefinition::describe($type, $version, $ecYear, $locale);
        $levels = array_map(fn (ReportingLevel $l): string => $l->value, FormDefinition::registerTables($type));
        $rows = array_values(array_filter($report['rows'], fn (array $r): bool => in_array($r['level'], $levels, true)));
        $register = array_values(array_filter($report['register'], fn (array $r): bool => in_array($r['level'], $levels, true)));
        $digital = array_values(array_filter($report['digital_services'], fn (array $d): bool => in_array($d['participant_level'], $levels, true)));
        $none = (string) __('service-monitoring.export.no_rows', [], $locale);
        $name = fn (array $r, string $prefix = 'institution_'): string => (string) (($locale === 'am' ? ($r[$prefix.'am'] ?? null) : null) ?: ($r[$prefix.'en'] ?? $r[$prefix.'am'] ?? ''));

        return [
            'section_1' => self::register($definition, $register, $named, $locale, $none, $name),
            'summary' => [self::summary($definition, $report['summary'], $levels)],
            'section_2' => self::section2($definition, $rows, $type, $locale, $none),
            'section_3' => [self::section3($definition, $rows, $digital, $locale, $none)],
            'section_4' => self::section4($definition, $rows, $report, $locale, $none),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function register(array $definition, array $entries, bool $named, string $locale, string $none, callable $name): array
    {
        $s1 = $definition['section_1'];
        $tables = [];
        foreach ($s1['tables'] as $table) {
            $header = [
                [self::cell($definition['section_1']['columns'][0]['label'], 1, 2), self::cell($table['institution_column'], 1, 2),
                    self::cell($s1['columns'][1]['label'], 1, 2), self::cell($s1['columns'][2]['label'], 1, 2), self::cell($s1['columns'][3]['label'], 1, 2),
                    self::cell($s1['role_group'], count($s1['role_columns'])), self::cell($table['remark_column'], 1, 2)],
                array_map(fn (array $r): array => self::cell($r['label']), $s1['role_columns']),
            ];
            $body = [];
            $n = 0;
            foreach (array_filter($entries, fn (array $e): bool => $e['level'] === $table['level']) as $entry) {
                $role = RoleCategory::from($entry['role_category']);
                $body[] = [
                    ++$n,
                    $name($entry),
                    $named ? (string) ($entry['full_name'] ?? $role->label($locale)) : $role->label($locale),
                    $named ? (string) $entry['reason_detail'] : ReasonCategory::from($entry['reason_category'])->label($locale),
                    $named ? (string) $entry['action_detail'] : FinalAction::from($entry['final_action'])->label($locale),
                    ...array_map(fn (array $r): string => $r['value'] === $entry['role_category'] ? 'X' : '', $s1['role_columns']),
                    trim(implode(' — ', array_filter([$entry['office_name'] ?? null, $entry['remark'] ?? null]))),
                ];
            }
            if ($body === []) {
                $body[] = ['', $none, '', '', '', ...array_fill(0, count($s1['role_columns']), ''), ''];
            }
            $tables[] = ['title' => $s1['heading'], 'subtitle' => $table['title'], 'header' => $header, 'body' => $body, 'total_rows' => [], 'notes' => []];
        }

        return $tables;
    }

    /** @param list<string> $levels */
    private static function summary(array $definition, array $summary, array $levels): array
    {
        $s = $definition['section_1']['summary'];
        $roles = $s['role_columns'];
        $header = [[self::cell($definition['section_1']['columns'][0]['label'], 1, 2), self::cell($s['action_column'], 1, 2)], []];
        foreach ($s['groups'] as $group) {
            $header[0][] = self::cell($group['label'], count($roles) + 1);
            foreach ($roles as $role) {
                $header[1][] = self::cell($role['label']);
            }
            $header[1][] = self::cell($s['total']);
        }
        $header[0][] = self::cell($s['remark'], 1, 2);

        $body = [];
        foreach ($s['rows'] as $i => $row) {
            $line = [$i + 1, $row['label']];
            foreach ($levels as $level) {
                foreach ($roles as $role) {
                    $line[] = $summary['matrix'][$level][$row['value']][$role['value']] ?? 0;
                }
                $line[] = $summary['row_totals'][$level][$row['value']] ?? 0;
            }
            $line[] = '';
            $body[] = $line;
        }
        $total = ['', $s['total']];
        foreach ($levels as $level) {
            foreach ($roles as $role) {
                $total[] = $summary['column_totals'][$level][$role['value']] ?? 0;
            }
            $total[] = $summary['level_totals'][$level] ?? 0;
        }
        $total[] = '';
        $body[] = $total;

        return ['title' => $definition['section_1']['title'], 'subtitle' => $s['title'], 'header' => $header, 'body' => $body, 'total_rows' => [count($body) - 1], 'notes' => array_values($s['notes'])];
    }

    /** @return list<array<string, mixed>> */
    private static function section2(array $definition, array $rows, FormType $type, string $locale, string $none): array
    {
        $s2 = $definition['section_2'];
        $header = [[self::cell($definition['section_1']['columns'][0]['label'], 1, 2), self::cell($s2['institution_column'], 1, 2)], []];
        $groupDone = [];
        foreach ($s2['fields'] as $field) {
            if ($field['group'] !== null) {
                if (! isset($groupDone[$field['group']])) {
                    $span = count(array_filter($s2['fields'], fn (array $f): bool => $f['group'] === $field['group']));
                    $header[0][] = self::cell((string) $field['group_label'], $span);
                    $groupDone[$field['group']] = true;
                }
                $header[1][] = self::cell($field['label']);
            } else {
                $header[0][] = self::cell($field['label'], 1, 2);
            }
        }
        $keys = array_column($s2['fields'], 'key');
        $body = [];
        $totals = [];
        $n = 0;
        $groups = $type === FormType::B ? [ReportingLevel::SubCity->value, ReportingLevel::Woreda->value] : [ReportingLevel::Center->value];
        foreach ($groups as $level) {
            $levelRows = array_values(array_filter($rows, fn (array $r): bool => $r['level'] === $level));
            foreach ($levelRows as $row) {
                $body[] = [++$n, self::name($row, $locale), ...array_map(fn (string $k): int => (int) $row['section2'][$k], $keys)];
            }
            if ($type === FormType::B) {
                $body[] = ['', (string) __('service-monitoring.form.columns.total', [], $locale).' — '.ReportingLevel::from($level)->label($locale),
                    ...array_map(fn (string $k): int => array_sum(array_map(fn (array $r): int => (int) $r['section2'][$k], $levelRows)), $keys)];
                $totals[] = count($body) - 1;
            }
        }
        $body[] = ['', (string) __('service-monitoring.form.columns.total', [], $locale), ...array_map(fn (string $k): int => array_sum(array_map(fn (array $r): int => (int) $r['section2'][$k], $rows)), $keys)];
        $totals[] = count($body) - 1;

        $narrative = array_map(fn (array $r): array => [self::name($r, $locale), (string) ($r['narrative'] ?? '') ?: $none], $rows);

        return [
            ['title' => $s2['title'], 'subtitle' => null, 'header' => $header, 'body' => $body, 'total_rows' => $totals, 'notes' => $s2['notes']],
            ['title' => $s2['narrative'], 'subtitle' => null, 'header' => [[self::cell($s2['institution_column']), self::cell($s2['narrative'])]],
                'body' => $narrative === [] ? [[$none, '']] : $narrative, 'total_rows' => [], 'notes' => []],
        ];
    }

    private static function section3(array $definition, array $rows, array $digital, string $locale, string $none): array
    {
        $c = $definition['section_3']['columns'];
        $header = [
            [self::cell($definition['section_1']['columns'][0]['label'], 1, 2), self::cell($definition['section_2']['institution_column'], 1, 2),
                self::cell($c['main_total'], 1, 2), self::cell($c['sub_total'], 1, 2), self::cell($c['digital_group'], 2),
                self::cell($c['service_name'], 1, 2), self::cell($c['technology'], 1, 2), self::cell($c['system_count'], 1, 2), self::cell($c['remark'], 1, 2)],
            [self::cell($c['digital_main']), self::cell($c['digital_sub'])],
        ];
        $body = [];
        $n = 0;
        foreach ($rows as $row) {
            $services = array_values(array_filter($digital, fn (array $d): bool => $d['participant_id'] === $row['participant_id']));
            $first = [++$n, self::name($row, $locale), $row['totals']['main_total'], $row['totals']['sub_total'], $row['digital']['main'], $row['digital']['sub']];
            if ($services === []) {
                $body[] = [...$first, $none, '', '', ''];

                continue;
            }
            foreach ($services as $i => $service) {
                $serviceName = (string) (($locale === 'am' ? $service['service_name_am'] : null) ?: ($service['service_name_en'] ?: $service['service_name_am']));
                $technology = TechnologyType::from($service['technology_type'])->label($locale).($service['technology_other'] ? ' ('.$service['technology_other'].')' : '').' / '.$service['system_name'];
                $lead = $i === 0 ? $first : ['', '', '', '', '', ''];
                $body[] = [...$lead, ($service['level'] === 'sub' ? '— ' : '').$serviceName, $technology, $service['system_count'], (string) ($service['remark'] ?? '')];
            }
        }
        $body[] = ['', (string) __('service-monitoring.form.columns.total', [], $locale),
            array_sum(array_map(fn (array $r): int => (int) $r['totals']['main_total'], $rows)), array_sum(array_map(fn (array $r): int => (int) $r['totals']['sub_total'], $rows)),
            array_sum(array_map(fn (array $r): int => (int) $r['digital']['main'], $rows)), array_sum(array_map(fn (array $r): int => (int) $r['digital']['sub'], $rows)),
            '', '', array_sum(array_map(fn (array $r): int => (int) $r['digital']['systems'], $rows)), ''];

        return ['title' => $definition['section_3']['title'], 'subtitle' => null, 'header' => $header, 'body' => $body, 'total_rows' => [count($body) - 1], 'notes' => $definition['section_3']['notes']];
    }

    /** @return list<array<string, mixed>> */
    private static function section4(array $definition, array $rows, array $report, string $locale, string $none): array
    {
        $tables = [self::section4Table($definition, $rows, 'section4', $locale, null)];
        if (count($report['quarters']) > 1) {
            $tables[] = self::section4Table($definition, $rows, 'section4_latest', $locale, (string) __('service-monitoring.export.latest_quarter', [], $locale));
        }

        return $tables;
    }

    private static function section4Table(array $definition, array $rows, string $part, string $locale, ?string $subtitle): array
    {
        $c = $definition['section_4']['columns'];
        $header = [
            [self::cell($definition['section_1']['columns'][0]['label'], 1, 2), self::cell($definition['section_2']['institution_column'], 1, 2),
                self::cell($c['main_total'], 1, 2), self::cell($c['sub_total'], 1, 2), self::cell($c['main_group'], 3), self::cell($c['sub_group'], 3),
                self::cell($c['below_reasons'], 1, 2), self::cell($c['actions_taken'], 1, 2)],
            [self::cell($c['above']), self::cell($c['at']), self::cell($c['below']), self::cell($c['above']), self::cell($c['at']), self::cell($c['below'])],
        ];
        $body = [];
        $n = 0;
        foreach ($rows as $row) {
            $values = $row[$part];
            $body[] = [++$n, self::name($row, $locale), $row['totals']['main_total'], $row['totals']['sub_total'],
                ...array_map(fn (string $f): int => (int) ($values[$f] ?? 0), FormDefinition::SECTION_4), (string) ($row['below_reasons'] ?? ''), (string) ($row['actions_taken'] ?? '')];
        }
        $body[] = ['', (string) __('service-monitoring.form.columns.total', [], $locale),
            array_sum(array_map(fn (array $r): int => (int) $r['totals']['main_total'], $rows)), array_sum(array_map(fn (array $r): int => (int) $r['totals']['sub_total'], $rows)),
            ...array_map(fn (string $f): int => array_sum(array_map(fn (array $r): int => (int) ($r[$part][$f] ?? 0), $rows)), FormDefinition::SECTION_4), '', ''];

        return ['title' => $definition['section_4']['title'], 'subtitle' => $subtitle, 'header' => $header, 'body' => $body, 'total_rows' => [count($body) - 1], 'notes' => $definition['section_4']['notes']];
    }

    /** @return array{text: string, colspan: int, rowspan: int} */
    private static function cell(string $text, int $colspan = 1, int $rowspan = 1): array
    {
        return ['text' => $text, 'colspan' => $colspan, 'rowspan' => $rowspan];
    }

    private static function name(array $row, string $locale): string
    {
        return (string) (($locale === 'am' ? $row['name_am'] : null) ?: ($row['name_en'] ?: $row['name_am']));
    }
}
