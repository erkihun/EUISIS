<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\ImportBatch;
use App\Models\User;
use App\Services\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * One kind of business import (ADR 0009). A definition names its columns,
 * checks a row with the Core's own rules (never a weaker copy) and writes a
 * valid row through the domain action that owns the change, as the approver.
 * Validation sees the rows before it (`$seen`) so a file cannot conflict
 * with itself.
 */
abstract class ImportDefinition
{
    /** Values seen in earlier rows of the same file: kind => value => row number. */
    protected array $seen = [];

    public function __construct(protected readonly OrganizationScope $scope) {}

    /** @return list<string> the template's columns, in order */
    abstract public function columns(): array;

    /**
     * What is wrong with a row, each as field, code and message (empty when valid).
     *
     * @param  array<string, ?string>  $row
     * @return list<array{field: string, code: string, message: string}>
     */
    abstract public function validate(array $row, int $number, User $uploader): array;

    /**
     * Writes a valid row; the result names the record it produced.
     *
     * @param  array<string, ?string>  $row
     * @return array{0: string, 1: string} [result type, result id]
     */
    abstract public function process(array $row, ImportBatch $batch, User $actor): array;

    /** The permission the uploader and the approver need besides imports.create / imports.approve. */
    abstract public function permission(): string;

    /** After every row: what spans rows (organization imports place the new organizations in a hierarchy draft). */
    public function finish(ImportBatch $batch, User $actor): void {}

    /**
     * A raw spreadsheet row keyed by its header, trimmed, empty cells as null,
     * and only the definition's columns.
     *
     * @param  array<string, mixed>  $cells
     * @return array<string, ?string>
     */
    public function normalize(array $cells): array
    {
        $row = [];
        foreach ($this->columns() as $column) {
            $value = $cells[$column] ?? null;
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            $value = $value === null ? null : trim((string) $value);
            $row[$column] = $value === '' ? null : $value;
        }

        return $row;
    }

    /** @return array{field: string, code: string, message: string} */
    protected function error(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }

    /**
     * Required and length checks with Laravel's validator, as codes.
     *
     * @param  array<string, ?string>  $row
     * @param  array<string, list<string>>  $rules
     * @return list<array{field: string, code: string, message: string}>
     */
    protected function basic(array $row, array $rules): array
    {
        $validator = Validator::make($row, $rules);
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $failed = array_key_first($validator->failed()[$field] ?? ['Invalid' => []]);
            $code = match ($failed) {
                'Required' => 'REQUIRED',
                'Max' => 'TOO_LONG',
                'Date', 'DateFormat', 'Before', 'BeforeOrEqual', 'After' => 'INVALID_DATE',
                'Email' => 'INVALID_EMAIL',
                'Integer', 'Min', 'Between' => 'INVALID_NUMBER',
                'In' => 'INVALID_VALUE',
                default => 'INVALID_VALUE',
            };
            $errors[] = $this->error($field, $code, $messages[0]);
        }

        return $errors;
    }

    /** A date in the past or today (future-dated changes are not supported), or null. */
    protected function pastDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $date->isFuture() ? null : $date;
    }

    /** Remembers a value of this file; returns the earlier row that had it, if any. */
    protected function remember(string $kind, ?string $value, int $number): ?int
    {
        if ($value === null) {
            return null;
        }
        $key = mb_strtolower($value);
        if (isset($this->seen[$kind][$key])) {
            return $this->seen[$kind][$key];
        }
        $this->seen[$kind][$key] = $number;

        return null;
    }

    /** How many rows of this file already use a value (a position's seats). */
    protected function count(string $kind, string $value): int
    {
        return $this->seen[$kind.':count'][$value] ?? 0;
    }

    protected function increment(string $kind, string $value): void
    {
        $this->seen[$kind.':count'][$value] = $this->count($kind, $value) + 1;
    }

    protected function inScope(User $user, ?string $organizationId): bool
    {
        return $organizationId !== null && $this->scope->allows($user, $organizationId);
    }
}
