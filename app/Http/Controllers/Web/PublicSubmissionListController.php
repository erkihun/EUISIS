<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackType;
use App\Enums\SubmitterIdentityMode;
use App\Http\Controllers\Controller;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\PublicFeedbackCategory;
use App\Models\User;
use App\Services\ServiceFeedback\PublicFeedbackMetrics;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every public submission the user may read — Feedback, Tip-offs and
 * Complaints, about employees and about institutions — in one server-side
 * paginated list (docs/admin-public-feedback-management.md).
 *
 * Nothing new is decided here: the base query is
 * PublicFeedbackMetrics::query(), which applies the type grants (WHAT), the
 * target-type grants and the organization scope (WHERE), and never shows a
 * user a submission about themselves. Every filter only narrows that base, so
 * a forged organization, unit, category or handler id returns nothing rather
 * than another institution's rows. Each row opens the type's own detail
 * page, which checks access again. The list carries no submitter identity and
 * no narrative.
 */
class PublicSubmissionListController extends Controller
{
    private const PER_PAGE = 25;

    private const SORTS = ['newest', 'oldest', 'status', 'organization', 'category'];

    public function __construct(
        private readonly PublicFeedbackMetrics $metrics,
        private readonly PublicFeedbackVisibility $visibility,
    ) {}

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    /** Routed types with no handler yet: the review queue nothing may fall out of. */
    public function unrouted(Request $request): Response
    {
        return $this->render($request, 'unrouted');
    }

    private function render(Request $request, ?string $queue): Response
    {
        /** @var User $user */
        $user = $request->user();
        $types = $this->visibility->visibleTypes($user);
        abort_if($types === [], 403);

        $filters = $this->metrics->filters($request->query());
        if ($queue !== null) {
            $filters['status'] = $queue;
        }
        $extra = $this->extraFilters($request, $user);
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'newest';

        $list = $this->narrow($this->metrics->query($user, $filters), $extra, $user);
        // The cards split the same slice by type and status, so they ignore those two filters (and the queue).
        $cards = $this->narrow($this->metrics->query($user, array_diff_key($filters, array_flip(['submission_type', 'tip_type', 'status']))), $extra, $user);
        $locale = app()->getLocale();

        $entries = $this->sorted($list, $sort)
            ->with([
                'employee:id,full_name,employee_number',
                'organization:id,name_en,name_am',
                'organizationUnit:id,name_en,name_am',
                'organizationTarget:id,code,name_en,name_am',
                'category:id,name_en,name_am',
                'currentAssignment',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (EmployeeServiceFeedback $entry): array => $this->row($entry, $locale));

        $options = $this->metrics->filterOptions($user);

        return Inertia::render('ServiceFeedback/AllSubmissions', [
            'queue' => $queue ?? 'all',
            'entries' => $entries,
            'overview' => $this->metrics->overview($cards),
            'filters' => $filters + $extra + ['sort' => $sort],
            'filterOptions' => [
                'organizations' => $options['organizations'],
                'categories' => $options['categories'],
                // The work units of the one institution chosen above, never of an institution outside the user's scope.
                'units' => $this->unitOptions($filters['organization_id'] ?? null, $options['organizations']),
                'statuses' => $options['statuses'],
                'types' => $types,
                'targets' => $this->visibility->visibleTargetTypes($user),
                'identityModes' => SubmitterIdentityMode::values(),
                'sorts' => self::SORTS,
            ],
            // Whether any submission exists in scope at all, so the page can tell "none yet" from "none match".
            'hasAny' => $this->metrics->query($user)->exists(),
        ]);
    }

    /**
     * The relevant-work-unit filter choices: the units of the selected
     * institution, offered only when that institution is one the user may
     * see. Archived units stay listed so older submissions remain findable.
     *
     * @param  list<array<string, mixed>>  $organizations  the user's organization options
     * @return list<array{id: string, name_en: string, name_am: string|null}>
     */
    private function unitOptions(?string $organizationId, array $organizations): array
    {
        if ($organizationId === null || ! in_array($organizationId, array_column($organizations, 'id'), true)) {
            return [];
        }

        return OrganizationUnit::query()->withTrashed()
            ->where('organization_id', $organizationId)
            ->orderBy('name_en')
            ->limit(500)
            ->get(['id', 'name_en', 'name_am'])
            ->map(fn (OrganizationUnit $unit): array => ['id' => (string) $unit->id, 'name_en' => (string) $unit->name_en, 'name_am' => $unit->name_am])
            ->all();
    }

    /**
     * The list's own filters beyond PublicFeedbackMetrics::FILTER_KEYS, validated.
     *
     * @return array<string, string>
     */
    private function extraFilters(Request $request, User $user): array
    {
        $extra = [];
        $q = $request->query('q');
        if (is_string($q) && trim($q) !== '') {
            // Capped, so a pasted essay cannot become a pathological LIKE pattern.
            $extra['q'] = mb_substr(trim($q), 0, 100);
        }
        $unit = $request->query('organization_unit_id');
        if (is_string($unit) && Str::isUuid($unit)) {
            $extra['organization_unit_id'] = $unit;
        }
        $mode = $request->query('identity_mode');
        if (is_string($mode) && in_array($mode, SubmitterIdentityMode::values(), true)) {
            $extra['identity_mode'] = $mode;
        }
        if ($request->boolean('mine')) {
            $extra['mine'] = '1';
        }

        return $extra;
    }

    /**
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @param  array<string, string>  $extra
     * @return Builder<EmployeeServiceFeedback>
     */
    private function narrow(Builder $query, array $extra, User $user): Builder
    {
        if (isset($extra['organization_unit_id'])) {
            $query->where('organization_unit_id', $extra['organization_unit_id']);
        }
        if (isset($extra['identity_mode'])) {
            $query->where('submitter_identity_mode', $extra['identity_mode']);
        }
        if (isset($extra['mine'])) {
            $query->whereHas('currentAssignment', fn (Builder $q) => $q->where('handler_type', 'user')->where('handler_id', (string) $user->getKey()));
        }
        if (isset($extra['q'])) {
            $term = $extra['q'];
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $employees = $this->visibility->canSeeTarget($user, PublicFeedbackTargetType::Employee);

            /*
             * The reference is matched exactly (its unique index); names are a contains-match
             * inside the already scoped and filtered slice. The narrative and the submitter
             * are never searched here: a match would reveal who sent what.
             */
            $query->where(function (Builder $q) use ($term, $pattern, $employees): void {
                $q->where('reference_number', mb_strtoupper($term))
                    ->orWhere('target_name_snapshot', ci_like_operator(), $pattern)
                    ->orWhere('service_name_snapshot', ci_like_operator(), $pattern)
                    ->orWhereHas('organizationTarget', fn (Builder $org) => $org->where('name_en', ci_like_operator(), $pattern)->orWhere('name_am', ci_like_operator(), $pattern))
                    ->orWhereHas('category', fn (Builder $category) => $category->where('name_en', ci_like_operator(), $pattern)->orWhere('name_am', ci_like_operator(), $pattern));
                if ($employees) {
                    $q->orWhereHas('employee', fn (Builder $employee) => $employee->where('full_name', ci_like_operator(), $pattern)->orWhere('employee_number', $term));
                }
            });
        }

        return $query;
    }

    /**
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Builder<EmployeeServiceFeedback>
     */
    private function sorted(Builder $query, string $sort): Builder
    {
        // Sub-selects rather than joins: the scoped base uses unqualified column names.
        return match ($sort) {
            'oldest' => $query->orderBy('submitted_at')->orderBy('id'),
            'status' => $query->orderBy('status')->orderByDesc('submitted_at')->orderByDesc('id'),
            'organization' => $query->orderBy(Organization::query()->select('name_en')->whereColumn('organizations.id', 'employee_service_feedback.organization_id')->limit(1))->orderByDesc('submitted_at')->orderByDesc('id'),
            'category' => $query->orderBy(PublicFeedbackCategory::query()->select('name_en')->whereColumn('public_feedback_categories.id', 'employee_service_feedback.category_id')->limit(1))->orderByDesc('submitted_at')->orderByDesc('id'),
            default => $query->orderByDesc('submitted_at')->orderByDesc('id'),
        };
    }

    /** @return array<string, mixed> */
    private function row(EmployeeServiceFeedback $entry, string $locale): array
    {
        $name = fn (?object $model, string $field = 'name'): ?string => $model === null ? null
            : ($locale === 'am' && filled($model->{$field.'_am'} ?? null) ? $model->{$field.'_am'} : $model->{$field.'_en'});
        $organizationTarget = $entry->target_type === PublicFeedbackTargetType::Organization;

        return [
            'id' => $entry->id,
            'reference' => $entry->reference_number,
            'type' => $entry->type->value,
            'tip_type' => $entry->tip_type?->value,
            'target_type' => $organizationTarget ? 'organization' : 'employee',
            // The institution's name, or the employee's (staff with the employee-target grant only reach these rows).
            'target_name' => $organizationTarget
                ? ($name($entry->organizationTarget) ?? $entry->target_name_snapshot)
                : ($entry->employee?->full_name ?? $entry->target_name_snapshot),
            'target_detail' => $organizationTarget ? $entry->organizationTarget?->code : $entry->employee?->employee_number,
            'organization' => $name($entry->organization),
            // The relevant work unit as recorded at submission; the live unit only for rows older than the snapshot.
            'organization_unit' => $entry->relevantUnitName($locale) ?? $name($entry->organizationUnit),
            'unit_selection_mode' => $entry->unit_selection_mode,
            'category' => $entry->category?->localizedName($locale),
            'service' => $entry->service_name_snapshot ?? $entry->service_text,
            'status' => $entry->status->value,
            'submitted_at' => ($entry->submitted_at ?? $entry->created_at)?->toIso8601String(),
            'handler' => $entry->currentAssignment?->handlerName($locale),
            'identity_mode' => $entry->submitter_identity_mode?->value,
            'url' => $entry->type === ServiceFeedbackType::Feedback
                ? route('service-feedback.admin.show', $entry->id)
                : route('service-submissions.show', ['type' => $entry->type->value, 'submission' => $entry->id]),
        ];
    }
}
