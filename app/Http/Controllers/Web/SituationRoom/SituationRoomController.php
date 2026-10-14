<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SituationRoom;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\PublicFeedbackCategory;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationCategory;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationLabels;
use App\Services\SituationRoom\SituationPermissions;
use App\Services\SituationRoom\SituationRoomService;
use App\Services\SituationRoom\SituationScopeResolver;
use App\Services\SituationRoom\SituationSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Situation Room pages: launcher, one category, and the wall.
 *
 * Thin by design: the route gates the grant, SituationRoomService resolves
 * the scope (403 for a focus outside it) and computes, and the heavy payload
 * is an Inertia deferred prop so the page frame paints first.
 */
final class SituationRoomController extends Controller
{
    public function __construct(
        private readonly SituationRoomService $room,
        private readonly SituationScopeResolver $resolver,
        private readonly OrganizationRollup $rollup,
        private readonly SituationSettings $settings,
        private readonly WriteAuditLogAction $audit,
        private readonly OrganizationScopeService $organizationScope,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->room->context($user, $request);

        return Inertia::render('SituationRoom/Index', [
            ...$this->frame($context),
            'result' => Inertia::defer(fn (): array => $this->room->category(SituationCategory::Overview, $context)),
        ]);
    }

    public function category(Request $request, string $category): Response
    {
        $category = SituationCategory::from($category);
        /** @var User $user */
        $user = $request->user();
        abort_unless($category->visibleTo($user), 403);
        $context = $this->room->context($user, $request);

        return Inertia::render('SituationRoom/Category', [
            ...$this->frame($context),
            'category' => $category->toArray(),
            'options' => $this->options($category, $context),
            'result' => Inertia::defer(function () use ($category, $context, $request): array {
                $result = $this->room->category($category, $context);

                if (SituationRoomService::showsEmployeeBreakdown($result['payload']['panels'])) {
                    $this->audit->execute(
                        eventType: AuditEventType::SituationRoomEmployeeBreakdownViewed,
                        actor: $context->user,
                        organizationId: $context->scope->focusOrganizationId,
                        newValues: ['category' => $category->value, 'filters' => $context->filters->toArray()],
                        request: $request,
                    );
                }

                return $result;
            }),
        ]);
    }

    public function wall(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->room->context($user, $request);

        return Inertia::render('SituationRoom/Wall', [
            'labels' => SituationLabels::dictionary(),
            'scope' => $this->scope($context),
            'filters' => $context->filters->toArray(),
            'privacy' => ['small_cell' => $this->settings->smallCellThreshold()],
            'refresh_seconds' => $this->settings->wallRefreshSeconds(),
            'rotation_seconds' => $this->settings->wallRotationSeconds(),
            'result' => Inertia::defer(fn (): array => $this->room->wall($context)),
        ]);
    }

    /**
     * What every Situation Room page shows around its panels.
     *
     * @return array<string, mixed>
     */
    private function frame(SituationContext $context): array
    {
        $user = $context->user;

        return [
            'labels' => SituationLabels::dictionary(),
            'categories' => array_map(fn (SituationCategory $c): array => $c->toArray(), SituationCategory::visibleFor($user)),
            'filters' => $context->filters->toArray(),
            'query' => $context->filters->query(),
            'scope' => $this->scope($context),
            'privacy' => ['small_cell' => $this->settings->smallCellThreshold()],
            'can' => [
                'wall' => $user->can(SituationPermissions::WALL),
                'reports' => $user->can(SituationPermissions::REPORTS),
                'actions_manage' => $user->can(SituationPermissions::ACTIONS_MANAGE),
                'community_voice' => $user->can(SituationPermissions::PUBLIC_VOICE),
            ],
        ];
    }

    /**
     * Where the viewer is looking: the focus, the path to it they may see,
     * and the rows one level down to drill into.
     *
     * @return array<string, mixed>
     */
    private function scope(SituationContext $context): array
    {
        $scope = $context->scope;
        $rollup = $this->rollup->for($scope);
        $breadcrumb = [];

        if ($scope->focusOrganizationId !== null && ($version = $this->resolver->publishedVersionId()) !== null) {
            $ancestors = DB::table('organization_closure_paths')
                ->where('hierarchy_version_id', $version)
                ->where('descendant_organization_id', $scope->focusOrganizationId)
                ->orderByDesc('depth')
                ->pluck('ancestor_organization_id')
                ->map(fn ($id): string => (string) $id)
                ->all();
            $visible = $scope->unrestricted ? $ancestors : array_values(array_filter($ancestors, fn (string $id): bool => $this->organizationScope->canAccessOrganization($context->user, $id)));
            $names = Organization::query()->whereIn('id', $visible)->get(['id', 'name_en', 'name_am'])->keyBy(fn ($o): string => (string) $o->id);
            foreach ($visible as $id) {
                $breadcrumb[] = ['id' => $id, 'label' => SituationLabel::names($names[$id]->name_en ?? null, $names[$id]->name_am ?? null)];
            }
        }

        return [
            'focus' => $scope->focusOrganizationId,
            'breadcrumb' => $breadcrumb,
            'children' => array_values(array_map(
                fn (array $g): array => ['id' => $g['id'], 'label' => $g['label']],
                array_filter($rollup['groups'], fn (array $g): bool => ! $g['own']),
            )),
            'hierarchy' => $rollup['hierarchy'],
            'unrestricted' => $scope->unrestricted,
            'empty' => $scope->isEmpty(),
        ];
    }

    /**
     * Filter choices that make sense for the category, drawn from the scope.
     *
     * @return array<string, mixed>
     */
    private function options(SituationCategory $category, SituationContext $context): array
    {
        $options = ['units' => [], 'feedback_categories' => [], 'public_voice' => false];
        $focus = $context->scope->focusOrganizationId;

        if ($focus !== null) {
            $options['units'] = OrganizationUnit::query()
                ->where('organization_id', $focus)
                ->orderBy('name_en')
                ->limit(500)
                ->get(['id', 'name_en', 'name_am'])
                ->map(fn ($u): array => ['id' => (string) $u->id, 'label' => SituationLabel::names($u->name_en, $u->name_am)])
                ->all();
        }

        if (in_array($category, [SituationCategory::Complaints, SituationCategory::Feedback, SituationCategory::TipOffs], true)) {
            $options['public_voice'] = true;
            $type = match ($category) {
                SituationCategory::Complaints => 'complaint',
                SituationCategory::Feedback => 'feedback',
                default => 'suggestion',
            };
            $options['feedback_categories'] = PublicFeedbackCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name_en', 'name_am', 'submission_types'])
                ->filter(fn ($c): bool => empty($c->submission_types) || in_array($type, (array) $c->submission_types, true))
                ->map(fn ($c): array => ['id' => (string) $c->id, 'label' => SituationLabel::names($c->name_en, $c->name_am)])
                ->values()
                ->all();
        }

        return $options;
    }
}
