<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One business rule written once, as SQL that is true when the rule FAILS for
 * the row (ADR 0009). The same rule then decides a single record (select its
 * flag) and filters a whole list (where NOT rule) in one query, so a picker
 * and a decision can never disagree and a list never runs one query per row.
 */
final readonly class EligibilityRule
{
    /** @param list<mixed> $bindings */
    public function __construct(public string $code, public string $failsWhen, public array $bindings, public string $message) {}

    /** The literal for a true boolean column on this connection. */
    public static function true(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'true' : '1';
    }

    /**
     * Adds one 0/1 column per rule: flag_0 … flag_n, 1 when the rule fails.
     *
     * @param  list<self>  $rules
     */
    public static function selectFlags(Builder $query, array $rules): Builder
    {
        foreach (array_values($rules) as $index => $rule) {
            $query->selectRaw("case when ({$rule->failsWhen}) then 1 else 0 end as eligibility_flag_{$index}", $rule->bindings);
        }

        return $query;
    }

    /**
     * Keeps only the rows every rule passes.
     *
     * @param  list<self>  $rules
     */
    public static function passing(Builder $query, array $rules): Builder
    {
        foreach ($rules as $rule) {
            $query->whereRaw("not ({$rule->failsWhen})", $rule->bindings);
        }

        return $query;
    }

    /**
     * The failing rules of a row loaded with selectFlags, as code => message.
     *
     * @param  list<self>  $rules
     * @return array<string, string>
     */
    public static function failures(object $row, array $rules): array
    {
        $failures = [];
        foreach (array_values($rules) as $index => $rule) {
            if ((int) data_get($row, "eligibility_flag_{$index}") === 1) {
                $failures[$rule->code] = $rule->message;
            }
        }

        return $failures;
    }
}
