<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\Intelligence\Contracts\AreaAnalysis;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationCache;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationRoomService;
use App\Services\SituationRoom\SituationScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one Intelligence area for a viewer: resolves the scope and filters the
 * Situation Room way, computes the panels (each isolated: one failure is one
 * ERROR card) and the insights, and caches the payload under the scope,
 * filter and grants fingerprints so no organization ever sees another's
 * figures.
 */
final class IntelligenceService
{
    public function __construct(
        private readonly SituationRoomService $room,
        private readonly SituationCache $cache,
        private readonly IntelligenceAreaRegistry $registry,
        private readonly OrganizationRollup $rollup,
        private readonly SituationScopeResolver $resolver,
        private readonly OrganizationScopeService $organizationScope,
    ) {}

    public function context(User $user, Request $request): SituationContext
    {
        return $this->room->context($user, $request);
    }

    public function analysisFor(IntelligenceArea $area): ?AreaAnalysis
    {
        return $this->registry->analysisFor($area);
    }

    /**
     * @return array{generated_at: string, cached_for: int, payload: array{panels: list<array<string, mixed>>, insights: list<array<string, mixed>>, has_errors: bool}}
     */
    public function area(IntelligenceArea $area, SituationContext $context): array
    {
        $analysis = $this->registry->analysisFor($area);
        if ($analysis === null) {
            return [
                'generated_at' => now()->toIso8601String(),
                'cached_for' => 0,
                'payload' => ['panels' => [], 'insights' => [], 'has_errors' => false],
            ];
        }

        return $this->cache->remember(
            'intelligence:'.$area->value,
            $context,
            function () use ($analysis, $context): array {
                $result = $this->room->run($analysis, $context);
                $insights = [];
                try {
                    $insights = $analysis->insights($context, $result['panels']);
                    usort($insights, fn (Insight $a, Insight $b): int => $b->weight() <=> $a->weight());
                } catch (Throwable $e) {
                    report($e);
                    $result['has_errors'] = true;
                }

                return [
                    'panels' => $result['panels'],
                    'insights' => array_map(fn (Insight $i): array => $i->toArray(), $insights),
                    'has_errors' => $result['has_errors'],
                ];
            },
            fn (array $payload): bool => ! $payload['has_errors'],
        );
    }

    /**
     * Where the viewer is looking: focus, the path to it they may see, and the
     * rows one level down. Mirrors the Situation Room frame so both modules
     * drill the same way.
     *
     * @return array<string, mixed>
     */
    public function scopeFrame(SituationContext $context): array
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
     * Filter choices drawn from the scope: the units of the focused organization.
     *
     * @return array{units: list<array<string, mixed>>, feedback_categories: list<mixed>, public_voice: bool}
     */
    public function options(SituationContext $context): array
    {
        $units = [];
        if (($focus = $context->scope->focusOrganizationId) !== null) {
            $units = OrganizationUnit::query()
                ->where('organization_id', $focus)
                ->orderBy('name_en')
                ->limit(500)
                ->get(['id', 'name_en', 'name_am'])
                ->map(fn ($u): array => ['id' => (string) $u->id, 'label' => SituationLabel::names($u->name_en, $u->name_am)])
                ->all();
        }

        return ['units' => $units, 'feedback_categories' => [], 'public_voice' => false];
    }
}
