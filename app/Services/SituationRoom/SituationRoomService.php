<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Models\User;
use App\Services\SituationRoom\Analytics\CafeteriaAnalytics;
use App\Services\SituationRoom\Analytics\CaseSummaryAnalytics;
use App\Services\SituationRoom\Analytics\ComplaintsAnalytics;
use App\Services\SituationRoom\Analytics\CriticalAttentionAnalytics;
use App\Services\SituationRoom\Analytics\DailyOperationsAnalytics;
use App\Services\SituationRoom\Analytics\DataQualityAnalytics;
use App\Services\SituationRoom\Analytics\FeedbackAnalytics;
use App\Services\SituationRoom\Analytics\FieldWorkAnalytics;
use App\Services\SituationRoom\Analytics\MovementAnalytics;
use App\Services\SituationRoom\Analytics\OverviewAnalytics;
use App\Services\SituationRoom\Analytics\PerformanceAnalytics;
use App\Services\SituationRoom\Analytics\ServiceDeliveryAnalytics;
use App\Services\SituationRoom\Analytics\SituationAnalytics;
use App\Services\SituationRoom\Analytics\StructureAnalytics;
use App\Services\SituationRoom\Analytics\TipOffAnalytics;
use App\Services\SituationRoom\Analytics\TrendsAnalytics;
use App\Services\SituationRoom\Analytics\WorkforceAnalytics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

/**
 * Entry point of the Situation Room: builds the viewer's context, runs a
 * category's panels and assembles the wall.
 *
 * Each panel runs on its own: an exception becomes one ERROR card (and is
 * reported), never a failed page, and a payload with an ERROR card is not
 * cached. Scope is resolved once per request, before any panel runs.
 */
final class SituationRoomService
{
    /** @var array<string, class-string<SituationAnalytics>> */
    public const ANALYTICS = [
        'overview' => OverviewAnalytics::class,
        'workforce' => WorkforceAnalytics::class,
        'structure' => StructureAnalytics::class,
        'service_delivery' => ServiceDeliveryAnalytics::class,
        'complaints' => ComplaintsAnalytics::class,
        'feedback' => FeedbackAnalytics::class,
        'tip_offs' => TipOffAnalytics::class,
        'daily_operations' => DailyOperationsAnalytics::class,
        'performance' => PerformanceAnalytics::class,
        'movement' => MovementAnalytics::class,
        'field_work' => FieldWorkAnalytics::class,
        'cafeteria' => CafeteriaAnalytics::class,
        'cases' => CaseSummaryAnalytics::class,
        'critical_attention' => CriticalAttentionAnalytics::class,
        'trends' => TrendsAnalytics::class,
        'data_quality' => DataQualityAnalytics::class,
    ];

    /** Panels that name people: never on a wall, never in a payload without their grant. */
    public const DETAIL_PANEL_SUFFIX = '_employees';

    public function __construct(
        private readonly Container $container,
        private readonly SituationScopeResolver $resolver,
        private readonly SituationSettings $settings,
        private readonly SituationCache $cache,
    ) {}

    /** @throws AuthorizationException when the requested focus organization is outside the viewer's scope */
    public function context(User $user, Request $request): SituationContext
    {
        $filters = SituationFilters::fromRequest($request, $this->settings->defaultPeriod());
        $scope = $this->resolver->resolve($user, $filters->get('organization_id'));

        return new SituationContext($user, $scope, $filters, $this->settings->topN());
    }

    /**
     * @return array{generated_at: string, cached_for: int, payload: array{panels: list<array<string, mixed>>, has_errors: bool}}
     */
    public function category(SituationCategory $category, SituationContext $context): array
    {
        return $this->cache->remember(
            'category:'.$category->value,
            $context,
            fn (): array => $this->run($this->analytics($category), $context),
            fn (array $payload): bool => ! $payload['has_errors'],
        );
    }

    /**
     * The wall: for each wall-eligible category the viewer may see, its
     * headline figures and up to two charts. Aggregates only — tables and
     * every panel that names a person are left out whatever the grants.
     *
     * @return array{generated_at: string, cached_for: int, payload: array{screens: list<array{category: string, panels: list<array<string, mixed>>}>, has_errors: bool}}
     */
    public function wall(SituationContext $context): array
    {
        return $this->cache->remember('wall', $context, function () use ($context): array {
            $screens = [];
            $errors = false;

            foreach (SituationCategory::visibleFor($context->user) as $category) {
                if (! $category->wallEligible()) {
                    continue;
                }

                $result = $this->run($this->analytics($category), $context, fn (string $key, \Closure|callable $panel): bool => ! str_ends_with($key, self::DETAIL_PANEL_SUFFIX));
                $errors = $errors || $result['has_errors'];
                $kpis = array_values(array_filter($result['panels'], fn (array $p): bool => $p['type'] === 'kpis'));
                $charts = array_values(array_filter($result['panels'], fn (array $p): bool => in_array($p['type'], SituationPanel::CHARTS, true)));
                $screens[] = ['category' => $category->value, 'panels' => [...array_slice($kpis, 0, 1), ...array_slice($charts, 0, 2)]];
            }

            return ['screens' => $screens, 'has_errors' => $errors];
        }, fn (array $payload): bool => ! $payload['has_errors']);
    }

    public function analytics(SituationCategory $category): SituationAnalytics
    {
        return $this->container->make(self::ANALYTICS[$category->value]);
    }

    /**
     * Run every panel of an analytics class, each isolated.
     *
     * @param  (callable(string, callable): bool)|null  $only
     * @return array{panels: list<array<string, mixed>>, has_errors: bool}
     */
    public function run(SituationAnalytics $analytics, SituationContext $context, ?callable $only = null): array
    {
        $panels = [];
        $errors = false;

        foreach ($analytics->panels($context) as $key => $build) {
            if ($only !== null && ! $only($key, $build)) {
                continue;
            }

            try {
                $panels[] = $build()->toArray();
            } catch (Throwable $e) {
                report($e);
                $errors = true;
                $panels[] = SituationPanel::notice($key, "situationRoom.panels.{$key}.title", null, 'situationRoom.sources.none', 'ERROR')->toArray();
            }
        }

        return ['panels' => $panels, 'has_errors' => $errors];
    }

    /** Whether a computed payload includes an employee-level table (for the audit trail). */
    public static function showsEmployeeBreakdown(array $panels): bool
    {
        foreach ($panels as $panel) {
            if (str_ends_with((string) $panel['key'], self::DETAIL_PANEL_SUFFIX) && $panel['type'] === 'table') {
                return true;
            }
        }

        return false;
    }
}
