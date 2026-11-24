<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;

/**
 * Reads the register's filters from the query string, keeping only values of
 * the expected shape. An organization id from the client is only a filter:
 * the query's scope decides what the actor may see.
 */
final class Filters
{
    public const ALL = ['cycle_id', 'report_type_id', 'organization_id', 'workflow', 'timeliness', 'applicability', 'search', 'due_from', 'due_to', 'as_of'];

    private const ENUMS = [
        'workflow' => ['not_started', 'draft', 'submitted', 'under_review', 'returned', 'approved', 'rejected'],
        'timeliness' => ['on_time', 'early', 'late', 'overdue', 'not_due'],
        'applicability' => ['required', 'exempted', 'cancelled'],
    ];

    /**
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    public static function from(Request $request, array $allowed = self::ALL): array
    {
        $filters = [];
        foreach ($allowed as $key) {
            $value = $request->query($key);
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            $valid = match (true) {
                in_array($key, ['cycle_id', 'report_type_id', 'organization_id'], true) => Str::isUuid($value),
                isset(self::ENUMS[$key]) => in_array($value, self::ENUMS[$key], true),
                in_array($key, ['due_from', 'due_to', 'as_of'], true) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)),
                default => mb_strlen($value) <= 100,
            };
            if ($valid) {
                $filters[$key] = $value;
            }
        }
        // A future as-of date is the present.
        if (isset($filters['as_of']) && $filters['as_of'] >= now('Africa/Addis_Ababa')->toDateString()) {
            unset($filters['as_of']);
        }

        return $filters;
    }

    /** @return array{report_types: list<array<string, mixed>>, cycles: list<array<string, mixed>>} for the filter pick-lists */
    public static function options(): array
    {
        return [
            'report_types' => ReportType::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'is_active'])->map->only(['id', 'name_en', 'name_am', 'is_active'])->all(),
            'cycles' => Cycle::query()->orderByDesc('due_at')->limit(60)->get()->map(fn (Cycle $c): array => [
                'id' => $c->id, 'report_type_id' => $c->report_type_id, 'label_en' => $c->period_label_en, 'label_am' => $c->period_label_am, 'due_at' => $c->due_at->toIso8601String(),
            ])->all(),
        ];
    }
}
