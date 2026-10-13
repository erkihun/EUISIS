<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\PublicFeedbackHandlerType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\PublicFeedbackAssignment;
use App\Models\PublicFeedbackCategory;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Scoped counts and breakdowns over every public submission type (brief
 * §81, 83–86). Every query starts from what the user may see — organization
 * scope, target-type grants, never their own — so the dashboard, the report
 * and the export agree with the inboxes. There is no per-employee ranking
 * here by design (§82).
 */
final class PublicFeedbackMetrics
{
    public const FILTER_KEYS = ['organization_id', 'submission_type', 'target_type', 'category_id', 'status', 'date_from', 'date_to', 'handler_type', 'handler_id'];

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
        $query = $this->visibility->applyTargetScope(EmployeeServiceFeedback::query(), $user);

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
        if (isset($filters['target_type'])) {
            $filters['target_type'] === PublicFeedbackTargetType::Employee->value
                ? $query->where(fn (Builder $q) => $q->where('target_type', 'employee')->orWhereNull('target_type'))
                : $query->where('target_type', $filters['target_type']);
        }
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
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
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, array{id: string, name_en: ?string, name_am: ?string, total: int}>
     */
    public function byCategory(Builder $query): Collection
    {
        $rows = (clone $query)->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) AS total')->groupBy('category_id')->orderByDesc('total')->get();
        $names = PublicFeedbackCategory::query()->whereIn('id', $rows->pluck('category_id')->all())->get(['id', 'name_en', 'name_am'])->keyBy('id');

        return $rows->map(fn ($row): array => [
            'id' => $row->category_id,
            'name_en' => $names[$row->category_id]->name_en ?? null,
            'name_am' => $names[$row->category_id]->name_am ?? null,
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
        $columns = ['submitted_at', 'reference', 'type', 'target_type', 'target_name', 'organization', 'organization_unit', 'category', 'status', 'handler', 'subject', 'message'];
        if ($this->visibility->canSeeIdentity($user)) {
            array_splice($columns, 10, 0, ['client_name', 'client_contact']);
        }

        return $columns;
    }

    /**
     * One flat row per submission, in the export column order.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return list<array<string, mixed>>
     */
    public function exportRows(Builder $query, User $user): array
    {
        $identity = $this->visibility->canSeeIdentity($user);
        $rows = [];

        (clone $query)
            ->with(['employee:id,full_name', 'organization:id,name_en', 'organizationUnit:id,name_en', 'category:id,name_en', 'currentAssignment'])
            ->orderBy('submitted_at')
            ->chunk(500, function ($chunk) use (&$rows, $identity): void {
                foreach ($chunk as $item) {
                    $row = [
                        'submitted_at' => ($item->submitted_at ?? $item->created_at)?->toDateTimeString(),
                        'reference' => $item->reference_number,
                        'type' => $item->type->value,
                        'target_type' => $item->target_type?->value ?? 'employee',
                        'target_name' => $item->target_name_snapshot ?? $item->employee?->full_name,
                        'organization' => $item->organization?->name_en,
                        'organization_unit' => $item->organizationUnit?->name_en,
                        'category' => $item->category?->name_en,
                        'status' => $item->status->value,
                        'handler' => $item->currentAssignment?->handler_name_snapshot,
                        'subject' => $item->subject,
                        'message' => $item->comment,
                    ];
                    if ($identity) {
                        $row['client_name'] = $item->client_name;
                        $row['client_contact'] = $item->client_contact;
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
            'categories' => PublicFeedbackCategory::query()
                ->when($organizationIds !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $organizationIds)))
                ->orderBy('sort_order')->orderBy('name_en')->get(['id', 'name_en', 'name_am'])->all(),
            'statuses' => ServiceFeedbackStatus::values(),
            'types' => array_column(ServiceFeedbackType::cases(), 'value'),
            'targets' => array_column(PublicFeedbackTargetType::cases(), 'value'),
        ];
    }
}
