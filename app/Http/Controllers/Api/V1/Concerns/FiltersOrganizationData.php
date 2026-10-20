<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Query filters and pagination shared by the organization-to-employee
 * endpoints, so every list behaves identically.
 *
 * Filters are matched on business codes (organization_code, position_code,
 * employee_number) rather than internal ids: an external system stores the code
 * it was given and should not need to learn our UUIDs to query by it.
 */
trait FiltersOrganizationData
{
    /** Largest page an external caller may request. */
    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 25;

    protected function perPage(Request $request): int
    {
        $requested = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    /**
     * `updated_after` lets an integration poll for changes instead of pulling
     * the whole structure each time.
     *
     * An unparseable value is rejected with a 422 rather than ignored: silently
     * dropping the filter would hand the caller the entire dataset when it
     * asked for a delta, which is both a surprise and a needless bulk export.
     *
     * @throws ValidationException
     */
    protected function applyUpdatedAfter(Builder $query, Request $request, string $column = 'updated_at'): Builder
    {
        $value = $request->string('updated_after')->trim()->toString();

        if ($value === '') {
            return $query;
        }

        try {
            $timestamp = Carbon::parse($this->repairTimezoneOffset($value));
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'updated_after' => 'updated_after must be a valid ISO 8601 timestamp.',
            ]);
        }

        return $query->where($column, '>=', $timestamp);
    }

    /**
     * Restore a `+` that URL decoding turned into a space.
     *
     * An ISO 8601 timestamp carries its offset as `+03:00`, and a caller that
     * does not percent-encode it sends a value that arrives as `... 03:00` and
     * no longer parses. Repairing it is friendlier than rejecting a timestamp
     * that is correct everywhere except in its transport encoding.
     */
    private function repairTimezoneOffset(string $value): string
    {
        return preg_replace('/(\d{2}:\d{2}:\d{2}(?:\.\d+)?) (\d{2}:\d{2})$/', '$1+$2', $value) ?? $value;
    }

    /** Case-insensitive exact match of a request parameter on a code column. */
    protected function applyCodeFilter(Builder $query, Request $request, string $parameter, string $column): Builder
    {
        $value = $request->string($parameter)->trim()->toString();

        if ($value === '') {
            return $query;
        }

        return $this->whereCodeEquals($query, $column, $value);
    }

    /**
     * Case-insensitive exact match on a code column, on every engine the app
     * runs on (PostgreSQL in production, SQLite in tests, MySQL on the old host).
     */
    protected function whereCodeEquals(Builder $query, string $column, string $value): Builder
    {
        // A case-insensitive *equality*, not a pattern: `employee_number=%`
        // matches nothing instead of every row. On PostgreSQL lower(col) = lower(?)
        // is served by the lower(...) indexes on employees.employee_number and
        // positions.job_position_code; the earlier ILIKE could only use the
        // trigram index and took ~0.7 s at 3M employees
        // (docs/production-readiness/database-capacity.md). MySQL/MariaDB
        // collations are already case-insensitive, so a plain = uses the index.
        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->where($column, $value);
        }

        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        return $query->whereRaw("lower({$wrapped}) = lower(?)", [$value]);
    }

    protected function applyStatusFilter(Builder $query, Request $request, string $column = 'status'): Builder
    {
        $value = $request->string('status')->trim()->toString();

        if ($value === '') {
            return $query;
        }

        return $query->where($column, $value);
    }

    /**
     * Envelope shared by every list response.
     *
     * @param  array<int, mixed>  $data
     * @return array<string, mixed>
     */
    protected function paginated(LengthAwarePaginator $paginator, array $data): array
    {
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }
}
