<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\PublicFeedbackHandlerType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\PublicTipType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\Employee;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\PublicFeedbackAssignment;
use App\Models\User;
use App\Services\Calendar\LocalizedDateService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

/**
 * Scoped counts and breakdowns over every public submission type (brief
 * §81, 83–86). Every query starts from what the user may see — organization
 * scope, target-type grants, never their own — so the dashboard, the report
 * and the export agree with the inboxes. There is no per-employee ranking
 * here by design (§82).
 *
 * Tip-offs are reports of something suspected, never findings. Their counts
 * are broken down by tip type (with legacy, unclassified suggestions as their
 * own bucket so the parts always add up to the whole), by institution in
 * name order, and — only inside one institution — by targeted employee in
 * name order. Nothing here ranks an employee or an institution, scores them,
 * or carries a tipster's identity or a narrative.
 */
final class PublicFeedbackMetrics
{
    public const FILTER_KEYS = ['organization_id', 'submission_type', 'tip_type', 'target_type', 'status', 'date_from', 'date_to', 'handler_type', 'handler_id'];

    /** Statuses that mean "still being worked". */
    private const OPEN = ['pending', 'unrouted', 'assigned', 'under_review', 'reviewed'];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly PublicFeedbackVisibility $visibility,
    ) {}

    /**
     * Keep only known, well-formed filter values.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function filters(array $input): array
    {
        $filters = [];
        foreach (self::FILTER_KEYS as $key) {
            $value = $input[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            $valid = match ($key) {
                'submission_type' => in_array($value, array_column(ServiceFeedbackType::cases(), 'value'), true),
                'tip_type' => in_array($value, [...PublicTipType::values(), PublicTipType::UNCLASSIFIED_LEGACY], true),
                'target_type' => in_array($value, array_column(PublicFeedbackTargetType::cases(), 'value'), true),
                'status' => in_array($value, ServiceFeedbackStatus::values(), true),
                'handler_type' => in_array($value, PublicFeedbackHandlerType::values(), true),
                'date_from', 'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
                default => mb_strlen($value) <= 64,
            };
            if ($valid) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * Everything the user may see, narrowed by the filters.
     *
     * @param  array<string, string>  $filters
     * @return Builder<EmployeeServiceFeedback>
     */
    public function query(User $user, array $filters = []): Builder
    {
        // WHAT: the types and target types the user's grants allow. WHERE: the organization scope below.
        $query = $this->visibility->applyTypeScope($this->visibility->applyTargetScope(EmployeeServiceFeedback::query(), $user), $user);

        if ($user->employee_id !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $user->employee_id));
        }
        if (! $this->scope->isUnrestricted($user)) {
            $query->whereIn('organization_id', $this->scope->accessibleOrganizationIds($user)->all());
        }

        if (isset($filters['organization_id'])) {
            if ($this->scope->isUnrestricted($user) || $this->scope->canAccessOrganization($user, $filters['organization_id'])) {
                $query->where('organization_id', $filters['organization_id']);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        if (isset($filters['submission_type'])) {
            $query->where('type', $filters['submission_type']);
        }
        if (isset($filters['tip_type'])) {
            // A tip type narrows the slice to Tip-offs of that type.
            $query->where('type', ServiceFeedbackType::Suggestion->value);
            $filters['tip_type'] === PublicTipType::UNCLASSIFIED_LEGACY
                ? $query->whereNull('tip_type')
                : $query->where('tip_type', $filters['tip_type']);
        }
        if (isset($filters['target_type'])) {
            $filters['target_type'] === PublicFeedbackTargetType::Employee->value
                ? $query->where(fn (Builder $q) => $q->where('target_type', 'employee')->orWhereNull('target_type'))
                : $query->where('target_type', $filters['target_type']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('submitted_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('submitted_at', '<=', $filters['date_to']);
        }
        if (isset($filters['handler_type'], $filters['handler_id'])) {
            $query->whereHas('currentAssignment', fn (Builder $q) => $q
                ->where('handler_type', $filters['handler_type'])
                ->where('handler_id', $filters['handler_id']));
        }

        return $query;
    }

    /**
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return array{total: int, by_type: array<string, int>, by_target: array<string, int>, by_status: array<string, int>}
     */
    public function overview(Builder $query): array
    {
        $byType = (clone $query)->selectRaw('type, COUNT(*) AS total')->groupBy('type')->pluck('total', 'type');
        $byTarget = (clone $query)->selectRaw("COALESCE(target_type, 'employee') AS target, COUNT(*) AS total")->groupBy('target')->pluck('total', 'target');
        $byStatus = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        $status = fn (string ...$keys): int => array_sum(array_map(fn (string $key): int => (int) ($byStatus[$key] ?? 0), $keys));

        return [
            'total' => (int) $byType->sum(),
            'by_type' => [
                'feedback' => (int) ($byType['feedback'] ?? 0),
                'suggestion' => (int) ($byType['suggestion'] ?? 0),
                'complaint' => (int) ($byType['complaint'] ?? 0),
            ],
            'by_target' => [
                'employee' => (int) ($byTarget['employee'] ?? 0),
                'organization' => (int) ($byTarget['organization'] ?? 0),
            ],
            'by_status' => [
                'pending' => $status('pending'),
                'unrouted' => $status('unrouted'),
                'assigned' => $status('assigned'),
                'under_review' => $status('under_review', 'reviewed'),
                // Every recorded outcome, whatever the type's word for it.
                'resolved' => $status('resolved', 'accepted', 'not_adopted'),
                'closed' => $status('closed'),
                'hidden' => $status('hidden'),
            ],
        ];
    }

    /**
     * Tip-offs in the slice by type. The three buckets always add up to the
     * total: a suggestion stored before tip types existed is counted as
     * UNCLASSIFIED_LEGACY, never guessed into one of the two.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return array{total: int, CORRUPTION: int, IMPROPER_PRACTICE: int, UNCLASSIFIED_LEGACY: int}
     */
    public function tipOffs(Builder $query): array
    {
        $byType = (clone $query)->where('type', ServiceFeedbackType::Suggestion->value)
            ->selectRaw("COALESCE(tip_type, '".PublicTipType::UNCLASSIFIED_LEGACY."') AS bucket, COUNT(*) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $counts = [
            PublicTipType::Corruption->value => (int) ($byType[PublicTipType::Corruption->value] ?? 0),
            PublicTipType::ImproperPractice->value => (int) ($byType[PublicTipType::ImproperPractice->value] ?? 0),
            PublicTipType::UNCLASSIFIED_LEGACY => (int) ($byType[PublicTipType::UNCLASSIFIED_LEGACY] ?? 0),
        ];

        return ['total' => array_sum($counts)] + $counts;
    }

    /**
     * Tip-offs per institution, in institution name order — a count of
     * reports received, not a comparison of conduct.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, array{id: ?string, name_en: ?string, name_am: ?string, total: int, CORRUPTION: int, IMPROPER_PRACTICE: int, UNCLASSIFIED_LEGACY: int}>
     */
    public function tipOffsByOrganization(Builder $query): Collection
    {
        $rows = (clone $query)->where('type', ServiceFeedbackType::Suggestion->value)
            ->selectRaw('organization_id, COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN tip_type = 'CORRUPTION' THEN 1 ELSE 0 END) AS corruption_count")
            ->selectRaw("SUM(CASE WHEN tip_type = 'IMPROPER_PRACTICE' THEN 1 ELSE 0 END) AS improper_count")
            ->selectRaw('SUM(CASE WHEN tip_type IS NULL THEN 1 ELSE 0 END) AS legacy_count')
            ->groupBy('organization_id')
            ->get();
        $names = Organization::query()->whereIn('id', $rows->pluck('organization_id')->filter()->all())->get(['id', 'name_en', 'name_am'])->keyBy('id');

        return $rows->map(fn ($row): array => [
            'id' => $row->organization_id,
            'name_en' => $names[$row->organization_id]->name_en ?? null,
            'name_am' => $names[$row->organization_id]->name_am ?? null,
            'total' => (int) $row->total,
            PublicTipType::Corruption->value => (int) $row->corruption_count,
            PublicTipType::ImproperPractice->value => (int) $row->improper_count,
            PublicTipType::UNCLASSIFIED_LEGACY => (int) $row->legacy_count,
        ])->sortBy(fn (array $row): string => mb_strtolower((string) ($row['name_en'] ?? '')))->values();
    }

    /**
     * Employee-targeted Tip-offs inside ONE institution, by employee name —
     * for the institution's authorized reviewers, never city-wide and never
     * ordered by count, so it cannot read as a league table. Empty unless an
     * institution is selected and the user may see employee targets.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @param  array<string, string>  $filters
     * @return Collection<int, array{employee_id: string, full_name: ?string, employee_number: ?string, total: int, CORRUPTION: int, IMPROPER_PRACTICE: int, UNCLASSIFIED_LEGACY: int}>
     */
    public function employeeTargetedTipOffs(Builder $query, User $user, array $filters): Collection
    {
        if (! isset($filters['organization_id']) || ! $this->visibility->canSeeTarget($user, PublicFeedbackTargetType::Employee)) {
            return collect();
        }

        $rows = (clone $query)->where('type', ServiceFeedbackType::Suggestion->value)
            ->whereNotNull('employee_id')
            ->selectRaw('employee_id, COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN tip_type = 'CORRUPTION' THEN 1 ELSE 0 END) AS corruption_count")
            ->selectRaw("SUM(CASE WHEN tip_type = 'IMPROPER_PRACTICE' THEN 1 ELSE 0 END) AS improper_count")
            ->selectRaw('SUM(CASE WHEN tip_type IS NULL THEN 1 ELSE 0 END) AS legacy_count')
            ->groupBy('employee_id')
            ->get();
        $people = Employee::query()->whereIn('id', $rows->pluck('employee_id')->all())->get(['id', 'full_name', 'employee_number'])->keyBy('id');

        return $rows->map(fn ($row): array => [
            'employee_id' => (string) $row->employee_id,
            'full_name' => $people[$row->employee_id]->full_name ?? null,
            'employee_number' => $people[$row->employee_id]->employee_number ?? null,
            'total' => (int) $row->total,
            PublicTipType::Corruption->value => (int) $row->corruption_count,
            PublicTipType::ImproperPractice->value => (int) $row->improper_count,
            PublicTipType::UNCLASSIFIED_LEGACY => (int) $row->legacy_count,
        ])->sortBy(fn (array $row): string => mb_strtolower((string) ($row['full_name'] ?? '')))->values();
    }

    /**
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, array{id: ?string, name_en: ?string, name_am: ?string, total: int, open: int, closed: int}>
     */
    public function byOrganization(Builder $query): Collection
    {
        $rows = (clone $query)
            ->selectRaw('organization_id, COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status IN (\''.implode("','", self::OPEN).'\') THEN 1 ELSE 0 END) AS open_count')
            ->selectRaw('SUM(CASE WHEN status = \'closed\' THEN 1 ELSE 0 END) AS closed_count')
            ->groupBy('organization_id')
            ->orderByDesc('total')
            ->get();
        $names = Organization::query()->whereIn('id', $rows->pluck('organization_id')->filter()->all())->get(['id', 'name_en', 'name_am'])->keyBy('id');

        return $rows->map(fn ($row): array => [
            'id' => $row->organization_id,
            'name_en' => $names[$row->organization_id]->name_en ?? null,
            'name_am' => $names[$row->organization_id]->name_am ?? null,
            'total' => (int) $row->total,
            'open' => (int) $row->open_count,
            'closed' => (int) $row->closed_count,
        ])->values();
    }

    /**
     * The relevant work units visitors named on the organization form, under
     * the name recorded at submission, plus one "General / Not sure" bucket
     * (id null). Employee-QR rows and rows older than the field are left out.
     * A count of what submissions concern — never a finding about a unit.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, array{id: ?string, name_en: ?string, name_am: ?string, general: bool, total: int}>
     */
    public function byUnit(Builder $query): Collection
    {
        $rows = (clone $query)
            ->where('target_type', PublicFeedbackTargetType::Organization->value)
            ->where(fn (Builder $q) => $q->whereNotNull('organization_unit_id')->orWhere('unit_selection_mode', 'GENERAL'))
            ->selectRaw('organization_unit_id, MAX(organization_unit_name_snapshot) AS name_en, MAX(organization_unit_name_am_snapshot) AS name_am, COUNT(*) AS total')
            ->groupBy('organization_unit_id')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row): array => [
            'id' => $row->organization_unit_id,
            'name_en' => $row->organization_unit_id === null ? null : $row->name_en,
            'name_am' => $row->organization_unit_id === null ? null : $row->name_am,
            'general' => $row->organization_unit_id === null,
            'total' => (int) $row->total,
        ])->values();
    }

    /**
     * Current handlers of the submissions in the slice (brief §83 "routing/handler").
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, array{id: string, handler_type: string, handler_id: string, name_en: string, name_am: ?string, total: int}>
     */
    public function byHandler(Builder $query): Collection
    {
        $ids = (clone $query)->select('employee_service_feedback.id');

        return PublicFeedbackAssignment::query()
            ->whereNull('ended_at')
            ->whereIn('submission_id', $ids)
            ->selectRaw('handler_type, handler_id, MAX(handler_name_snapshot) AS name_en, MAX(handler_name_am_snapshot) AS name_am, COUNT(*) AS total')
            ->groupBy('handler_type', 'handler_id')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row): array {
                // The model casts handler_type to the enum, aggregate rows included.
                $type = $row->handler_type instanceof PublicFeedbackHandlerType ? $row->handler_type->value : (string) $row->handler_type;

                return [
                    'id' => $type.':'.$row->handler_id,
                    'handler_type' => $type,
                    'handler_id' => (string) $row->handler_id,
                    'name_en' => (string) $row->name_en,
                    'name_am' => $row->name_am,
                    'total' => (int) $row->total,
                ];
            })->values();
    }

    /**
     * Export columns for a user: identity columns only with the grant (brief §36, 86).
     *
     * @return list<string>
     */
    public function exportColumns(User $user): array
    {
        $columns = ['submitted_at', 'reference', 'type', 'tip_type', 'intake_channel', 'target_type', 'target_name', 'organization', 'organization_unit', 'status', 'handler', 'subject', 'message', 'involved_parties'];
        if ($this->visibility->canSeeIdentity($user)) {
            array_splice($columns, 11, 0, ['client_name', 'client_contact', 'client_email', 'submitter_organization', 'submitter_address']);
        }

        return $columns;
    }

    /**
     * One flat row per submission, in the export column order.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return list<array<string, mixed>>
     */
    public function exportRows(Builder $query, User $user, ?string $locale = null): array
    {
        $identity = $this->visibility->canSeeIdentity($user);
        $locale = in_array($locale, ['en', 'am'], true) ? $locale : app()->getLocale();
        $dates = app(LocalizedDateService::class);
        $value = fn (string $group, ?string $key): ?string => $key === null ? null
            : (Lang::has("service-feedback.export_values.{$group}.{$key}", $locale) ? (string) __("service-feedback.export_values.{$group}.{$key}", [], $locale) : $key);
        $name = fn (?object $model, string $field = 'name'): ?string => $model === null ? null
            : ($locale === 'am' && filled($model->{$field.'_am'} ?? null) ? $model->{$field.'_am'} : $model->{$field.'_en'});
        $rows = [];

        (clone $query)
            ->with(['employee:id,full_name', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'organizationTarget:id,name_en,name_am', 'currentAssignment'])
            ->orderBy('submitted_at')
            ->chunk(500, function ($chunk) use (&$rows, $identity, $locale, $dates, $value, $name): void {
                foreach ($chunk as $item) {
                    $tipType = $item->tip_type?->value ?? ($item->type === ServiceFeedbackType::Suggestion ? PublicTipType::UNCLASSIFIED_LEGACY : null);
                    $organizationTarget = $item->target_type === PublicFeedbackTargetType::Organization;
                    $row = [
                        // Gregorian in English, Ethiopian in Amharic (the shared calendar service).
                        'submitted_at' => $dates->displayDateTime($item->submitted_at ?? $item->created_at, $locale),
                        'reference' => $item->reference_number,
                        'type' => $value('types', $item->type->value),
                        'tip_type' => $tipType === null ? null : (string) __("service-feedback.tip_types.{$tipType}", [], $locale),
                        'intake_channel' => $value('intake_channels', $item->intake_channel?->value),
                        'target_type' => $value('target_types', $organizationTarget ? 'organization' : 'employee'),
                        'target_name' => $organizationTarget
                            ? ($name($item->organizationTarget) ?? $item->target_name_snapshot)
                            : ($item->target_name_snapshot ?? $item->employee?->full_name),
                        'organization' => $name($item->organization),
                        // The relevant work unit as recorded at submission; "General / Not sure" is spelled out, never a fake unit.
                        'organization_unit' => $item->relevantUnitName($locale)
                            ?? ($item->isGeneralUnitSelection() ? (string) __('service-feedback.export_values.unit_selection.GENERAL', [], $locale) : $name($item->organizationUnit)),
                        'status' => $value('statuses', $item->status->value),
                        'handler' => $item->currentAssignment?->handlerName($locale),
                        'subject' => $item->subject,
                        'message' => $item->comment,
                        'involved_parties' => $item->involved_parties,
                    ];
                    if ($identity) {
                        $row['client_name'] = $item->client_name;
                        $row['client_contact'] = $item->client_contact;
                        $row['client_email'] = $item->client_email;
                        $row['submitter_organization'] = $item->submitter_organization;
                        $row['submitter_address'] = $item->submitter_address === null ? null : implode(', ', $item->submitter_address);
                    }
                    $rows[] = $row;
                }
            });

        return $rows;
    }

    /** @return array<string, mixed> */
    public function filterOptions(User $user): array
    {
        $organizationIds = $this->scope->isUnrestricted($user) ? null : $this->scope->accessibleOrganizationIds($user)->all();

        return [
            'organizations' => Organization::query()
                ->when($organizationIds !== null, fn ($q) => $q->whereIn('id', $organizationIds))
                ->orderBy('name_en')->get(['id', 'name_en', 'name_am'])->all(),
            'statuses' => ServiceFeedbackStatus::values(),
            // Only what the user's grants let them read: no option that can only ever return nothing.
            'types' => $this->visibility->visibleTypes($user),
            'tipTypes' => [...PublicTipType::values(), PublicTipType::UNCLASSIFIED_LEGACY],
            'targets' => $this->visibility->visibleTargetTypes($user),
        ];
    }
}
