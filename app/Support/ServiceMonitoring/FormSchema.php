<?php

declare(strict_types=1);

namespace App\Support\ServiceMonitoring;

/**
 * The contract of an administrator-built monitoring form (docs/service-monitoring.md §Schema).
 *
 * A schema is data, not code:
 *
 *   { sections: [ { key, title, blocks: [ block, … ] } ] }
 *
 * where a block is one of
 *
 *   note   { type, key, text }                       instructions (ማሳሰቢያ), nothing to fill
 *   text   { type, key, label, required }            a free-text answer
 *   table  { type, key, title, description?, row_mode, rows?, max_rows?,
 *            show_totals, column_groups?, columns }
 *
 * row_mode: `dynamic` (the institution adds rows, e.g. people held accountable),
 * `fixed` (rows are predefined, e.g. the list of action types) or `single`
 * (exactly one row: the institution's own figures). A column is
 * text | textarea | number | check ("X" mark) | select | sum (a row total of
 * named number columns, always computed here, never typed).
 *
 * Answers are keyed by block key: a text block holds a string, a fixed table
 * an object keyed by row key, a dynamic or single table a list of rows.
 * The server recomputes every sum column and total, so a client cannot
 * submit a ድምር that disagrees with its own figures.
 */
final class FormSchema
{
    public const BLOCK_TYPES = ['note', 'text', 'table'];

    public const COLUMN_TYPES = ['text', 'textarea', 'number', 'check', 'select', 'sum'];

    public const ROW_MODES = ['dynamic', 'fixed', 'single'];

    public const MAX_SECTIONS = 30;

    public const MAX_BLOCKS = 30;

    public const MAX_COLUMNS = 40;

    public const MAX_FIXED_ROWS = 100;

    public const MAX_DYNAMIC_ROWS = 500;

    public const MAX_NUMBER = 1_000_000_000_000;

    private const KEY = '/^[a-z][a-z0-9_]{0,39}$/';

    /** @param array<string, mixed> $schema */
    private function __construct(private readonly array $schema) {}

    /** @param array<string, mixed> $schema */
    public static function from(array $schema): self
    {
        return new self($schema);
    }

    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return ['sections' => []];
    }

    /**
     * Structural problems, keyed by dotted path. Empty = a well-formed schema.
     * `$forPublish` adds the rules a form must meet before institutions see it.
     *
     * @return array<string, string>
     */
    public function problems(bool $forPublish = false): array
    {
        $errors = [];
        $sections = $this->schema['sections'] ?? null;

        if (! is_array($sections) || ! array_is_list($sections)) {
            return ['schema.sections' => 'The form must be a list of sections.'];
        }
        if (count($sections) > self::MAX_SECTIONS) {
            $errors['schema.sections'] = 'A form may have at most '.self::MAX_SECTIONS.' sections.';
        }
        if ($forPublish && $sections === []) {
            $errors['schema.sections'] = 'Add at least one section before publishing.';
        }

        $blockKeys = [];
        foreach ($sections as $s => $section) {
            $path = "schema.sections.{$s}";
            if (! is_array($section)) {
                $errors[$path] = 'Invalid section.';

                continue;
            }
            $this->checkLabel($section['title'] ?? null, "{$path}.title", $errors, 'section title');
            $blocks = $section['blocks'] ?? null;
            if (! is_array($blocks) || ! array_is_list($blocks)) {
                $errors["{$path}.blocks"] = 'A section must be a list of blocks.';

                continue;
            }
            if (count($blocks) > self::MAX_BLOCKS) {
                $errors["{$path}.blocks"] = 'A section may have at most '.self::MAX_BLOCKS.' blocks.';
            }
            if ($forPublish && $blocks === []) {
                $errors["{$path}.blocks"] = 'Every section needs at least one block before publishing.';
            }

            foreach ($blocks as $b => $block) {
                $blockPath = "{$path}.blocks.{$b}";
                if (! is_array($block) || ! in_array($block['type'] ?? null, self::BLOCK_TYPES, true)) {
                    $errors["{$blockPath}.type"] = 'Unknown block type.';

                    continue;
                }
                $key = $block['key'] ?? null;
                if (! is_string($key) || ! preg_match(self::KEY, $key)) {
                    $errors["{$blockPath}.key"] = 'Invalid block key.';
                } elseif (isset($blockKeys[$key])) {
                    $errors["{$blockPath}.key"] = 'Block keys must be unique within the form.';
                } else {
                    $blockKeys[$key] = true;
                }

                match ($block['type']) {
                    'note' => $this->checkLabel($block['text'] ?? null, "{$blockPath}.text", $errors, 'note', 5000),
                    'text' => $this->checkLabel($block['label'] ?? null, "{$blockPath}.label", $errors, 'question'),
                    'table' => $this->checkTable($block, $blockPath, $errors),
                };
            }
        }

        return $errors;
    }

    /**
     * Validate and normalize answers. Drafts may be incomplete; `$complete`
     * also enforces required fields. Returns the clean data (sums recomputed)
     * and the problems keyed by `data.<block>.<row>.<column>`.
     *
     * @param  array<string, mixed>  $input
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function normalize(array $input, bool $complete): array
    {
        $data = [];
        $errors = [];

        foreach ($this->blocks() as $block) {
            $key = $block['key'];
            $value = $input[$key] ?? null;

            if ($block['type'] === 'text') {
                $text = is_string($value) ? trim($value) : '';
                if (mb_strlen($text) > 10_000) {
                    $errors["data.{$key}"] = 'This answer is too long.';
                }
                if ($complete && ! empty($block['required']) && $text === '') {
                    $errors["data.{$key}"] = 'This answer is required.';
                }
                $data[$key] = $text;

                continue;
            }

            if ($block['type'] !== 'table') {
                continue;
            }

            if ($block['row_mode'] === 'fixed') {
                $rows = [];
                $given = is_array($value) ? $value : [];
                foreach ($block['rows'] as $row) {
                    $rowInput = is_array($given[$row['key']] ?? null) ? $given[$row['key']] : [];
                    $rows[$row['key']] = $this->normalizeRow($block, $rowInput, "data.{$key}.{$row['key']}", $complete, $errors);
                }
                $data[$key] = $rows;

                continue;
            }

            $given = is_array($value) && array_is_list($value) ? $value : [];
            $limit = $block['row_mode'] === 'single' ? 1 : (int) ($block['max_rows'] ?? self::MAX_DYNAMIC_ROWS);
            if (count($given) > $limit) {
                $errors["data.{$key}"] = "At most {$limit} rows are allowed.";
                $given = array_slice($given, 0, $limit);
            }
            $rows = [];
            foreach ($given as $i => $rowInput) {
                if (! is_array($rowInput) || $this->isBlankRow($block, $rowInput)) {
                    continue;
                }
                $rows[] = $this->normalizeRow($block, $rowInput, "data.{$key}.{$i}", $complete, $errors);
            }
            if ($block['row_mode'] === 'single' && $rows === []) {
                $rows[] = $this->normalizeRow($block, [], "data.{$key}.0", $complete, $errors);
            }
            $data[$key] = $rows;
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * Column totals of one table's normalized rows: number and sum columns
     * add up, check columns count their marks.
     *
     * @param  array<string, mixed>  $block
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    public static function totals(array $block, array $rows): array
    {
        $totals = [];
        foreach ($block['columns'] as $column) {
            if (! in_array($column['type'], ['number', 'sum', 'check'], true)) {
                continue;
            }
            $sum = 0;
            foreach ($rows as $row) {
                $cell = $row[$column['key']] ?? null;
                $sum += $column['type'] === 'check' ? ($cell === true ? 1 : 0) : (is_int($cell) || is_float($cell) ? $cell : 0);
            }
            $totals[$column['key']] = $sum;
        }

        return $totals;
    }

    /** @return list<array<string, mixed>> every block in order */
    public function blocks(): array
    {
        $blocks = [];
        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['blocks'] ?? [] as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    public function tables(): array
    {
        return array_values(array_filter($this->blocks(), static fn (array $block): bool => $block['type'] === 'table'));
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function normalizeRow(array $block, array $input, string $path, bool $complete, array &$errors): array
    {
        $row = [];
        foreach ($block['columns'] as $column) {
            $key = $column['key'];
            $raw = $input[$key] ?? null;
            $cellPath = "{$path}.{$key}";

            $row[$key] = match ($column['type']) {
                'check' => $raw === true || $raw === 1 || $raw === '1' || $raw === 'true',
                'number' => $this->number($raw, $cellPath, $errors),
                'select' => $this->option($raw, $column, $cellPath, $errors),
                'sum' => null,
                default => $this->text($raw, $column['type'] === 'textarea' ? 5000 : 500, $cellPath, $errors),
            };

            if ($complete && ! empty($column['required']) && in_array($row[$key], [null, ''], true)) {
                $errors[$cellPath] = 'Required.';
            }
        }

        // Row totals are derived, never typed.
        foreach ($block['columns'] as $column) {
            if ($column['type'] === 'sum') {
                $total = 0;
                foreach ($column['sum_of'] ?? [] as $source) {
                    $total += is_int($row[$source] ?? null) || is_float($row[$source] ?? null) ? $row[$source] : 0;
                }
                $row[$column['key']] = $total;
            }
        }

        return $row;
    }

    /** @param array<string, string> $errors */
    private function number(mixed $raw, string $path, array &$errors): int|float|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_numeric($raw)) {
            $errors[$path] = 'Enter a number.';

            return null;
        }
        $value = $raw + 0;
        if ($value < 0 || $value > self::MAX_NUMBER) {
            $errors[$path] = 'Enter a number between 0 and '.number_format(self::MAX_NUMBER).'.';

            return null;
        }

        return is_float($value) ? round($value, 2) : $value;
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, string>  $errors
     */
    private function option(mixed $raw, array $column, string $path, array &$errors): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw) || ! in_array($raw, $column['options'] ?? [], true)) {
            $errors[$path] = 'Choose one of the listed options.';

            return null;
        }

        return $raw;
    }

    /** @param array<string, string> $errors */
    private function text(mixed $raw, int $max, string $path, array &$errors): string
    {
        $text = is_scalar($raw) ? trim((string) $raw) : '';
        if (mb_strlen($text) > $max) {
            $errors[$path] = "At most {$max} characters.";

            return mb_substr($text, 0, $max);
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $row
     */
    private function isBlankRow(array $block, array $row): bool
    {
        foreach ($block['columns'] as $column) {
            if ($column['type'] === 'sum') {
                continue;
            }
            $cell = $row[$column['key']] ?? null;
            if ($column['type'] === 'check' ? ($cell === true || $cell === 1 || $cell === '1' || $cell === 'true') : ! in_array(is_string($cell) ? trim($cell) : $cell, [null, ''], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $errors
     */
    private function checkTable(array $block, string $path, array &$errors): void
    {
        $this->checkLabel($block['title'] ?? null, "{$path}.title", $errors, 'table title');

        $mode = $block['row_mode'] ?? null;
        if (! in_array($mode, self::ROW_MODES, true)) {
            $errors["{$path}.row_mode"] = 'Choose how rows are entered.';
        }

        $groups = [];
        foreach (is_array($block['column_groups'] ?? null) ? $block['column_groups'] : [] as $g => $group) {
            $key = is_array($group) ? ($group['key'] ?? null) : null;
            if (! is_string($key) || ! preg_match(self::KEY, $key) || isset($groups[$key])) {
                $errors["{$path}.column_groups.{$g}.key"] = 'Invalid or duplicate group key.';

                continue;
            }
            $groups[$key] = true;
            $this->checkLabel($group['label'] ?? null, "{$path}.column_groups.{$g}.label", $errors, 'group heading');
        }

        $columns = $block['columns'] ?? null;
        if (! is_array($columns) || ! array_is_list($columns) || $columns === []) {
            $errors["{$path}.columns"] = 'A table needs at least one column.';

            return;
        }
        if (count($columns) > self::MAX_COLUMNS) {
            $errors["{$path}.columns"] = 'A table may have at most '.self::MAX_COLUMNS.' columns.';
        }

        $numberColumns = [];
        $columnKeys = [];
        foreach ($columns as $c => $column) {
            if (is_array($column) && ($column['type'] ?? null) === 'number' && is_string($column['key'] ?? null)) {
                $numberColumns[$column['key']] = true;
            }
        }
        foreach ($columns as $c => $column) {
            $columnPath = "{$path}.columns.{$c}";
            if (! is_array($column)) {
                $errors[$columnPath] = 'Invalid column.';

                continue;
            }
            $key = $column['key'] ?? null;
            if (! is_string($key) || ! preg_match(self::KEY, $key) || isset($columnKeys[$key])) {
                $errors["{$columnPath}.key"] = 'Invalid or duplicate column key.';
            } else {
                $columnKeys[$key] = true;
            }
            $this->checkLabel($column['label'] ?? null, "{$columnPath}.label", $errors, 'column heading');

            $type = $column['type'] ?? null;
            if (! in_array($type, self::COLUMN_TYPES, true)) {
                $errors["{$columnPath}.type"] = 'Unknown column type.';
            }
            if (isset($column['group']) && $column['group'] !== null && $column['group'] !== '' && ! isset($groups[$column['group']])) {
                $errors["{$columnPath}.group"] = 'The column belongs to a group that does not exist.';
            }
            if ($type === 'select') {
                $options = $column['options'] ?? null;
                if (! is_array($options) || $options === [] || count($options) > 50
                    || count(array_unique($options)) !== count($options)
                    || array_filter($options, static fn ($o): bool => ! is_string($o) || trim($o) === '' || mb_strlen($o) > 200) !== []) {
                    $errors["{$columnPath}.options"] = 'A choice column needs 1–50 distinct, non-empty options.';
                }
            }
            if ($type === 'sum') {
                $sources = $column['sum_of'] ?? null;
                if (! is_array($sources) || $sources === [] || array_diff($sources, array_keys($numberColumns)) !== []) {
                    $errors["{$columnPath}.sum_of"] = 'A total column must add up one or more number columns of the same table.';
                }
            }
        }

        if ($mode === 'fixed') {
            $rows = $block['rows'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
                $errors["{$path}.rows"] = 'A table with fixed rows needs at least one row.';

                return;
            }
            if (count($rows) > self::MAX_FIXED_ROWS) {
                $errors["{$path}.rows"] = 'At most '.self::MAX_FIXED_ROWS.' fixed rows.';
            }
            $rowKeys = [];
            foreach ($rows as $r => $row) {
                $key = is_array($row) ? ($row['key'] ?? null) : null;
                if (! is_string($key) || ! preg_match(self::KEY, $key) || isset($rowKeys[$key])) {
                    $errors["{$path}.rows.{$r}.key"] = 'Invalid or duplicate row key.';

                    continue;
                }
                $rowKeys[$key] = true;
                $this->checkLabel($row['label'] ?? null, "{$path}.rows.{$r}.label", $errors, 'row label');
            }
        }

        if ($mode === 'dynamic' && isset($block['max_rows'])
            && (! is_int($block['max_rows']) || $block['max_rows'] < 1 || $block['max_rows'] > self::MAX_DYNAMIC_ROWS)) {
            $errors["{$path}.max_rows"] = 'The row limit must be between 1 and '.self::MAX_DYNAMIC_ROWS.'.';
        }
    }

    /** @param array<string, string> $errors */
    private function checkLabel(mixed $value, string $path, array &$errors, string $what, int $max = 500): void
    {
        if (! is_string($value) || trim($value) === '') {
            $errors[$path] = "The {$what} is required.";
        } elseif (mb_strlen($value) > $max) {
            $errors[$path] = "The {$what} may be at most {$max} characters.";
        }
    }
}
