<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationPermissions;
use App\Services\SituationRoom\SituationScopeResolver;
use Illuminate\Support\Facades\DB;

/**
 * Data Quality: how far the numbers elsewhere in the room can be trusted.
 * Each check is a count of records in scope that are missing or
 * contradictory, against the base it is drawn from. Nothing is corrected
 * here; each check names the module where it is fixed.
 */
final class DataQualityAnalytics extends SituationAnalytics
{
    public function panels(SituationContext $context): array
    {
        return [
            'quality_checks' => fn (): SituationPanel => $this->checks($context),
        ];
    }

    /** @return list<array{check: string, base: string, count: int, of: int, permission: string}> */
    public function results(SituationContext $context): array
    {
        $checks = [];
        $headcount = WorkforceQueries::headcount($context);
        // One current assignment per employee (unique partial index), so a row count is a people count.
        $distinct = fn ($query): int => (int) $query->count();

        if ($context->can(SituationPermissions::WORKFORCE)) {
            [$gender, $genderBindings] = WorkforceQueries::genderExpression();
            [$age, $ageBindings] = WorkforceQueries::ageBandExpression();
            $checks[] = ['check' => 'gender_missing', 'count' => $distinct(WorkforceQueries::assignments($context)->whereRaw("{$gender} = 'unknown'", $genderBindings)), 'of' => $headcount];
            $checks[] = ['check' => 'birth_date_missing_or_implausible', 'count' => $distinct(WorkforceQueries::assignments($context)->whereRaw("{$age} = 'unknown'", $ageBindings)), 'of' => $headcount];
            $checks[] = ['check' => 'phone_missing', 'count' => $distinct(WorkforceQueries::assignments($context)->where(fn ($q) => $q->whereNull('e.phone')->orWhere('e.phone', ''))), 'of' => $headcount];
            $checks[] = ['check' => 'photo_missing', 'count' => $distinct(WorkforceQueries::assignments($context)->where(fn ($q) => $q->whereNull('e.photo_path')->orWhere('e.photo_path', ''))), 'of' => $headcount];
            $checks[] = ['check' => 'employment_type_missing', 'count' => $distinct(WorkforceQueries::assignments($context)->where(fn ($q) => $q->whereNull('e.employment_type')->orWhere('e.employment_type', ''))), 'of' => $headcount];
            $checks[] = ['check' => 'assignment_without_position', 'count' => $distinct(WorkforceQueries::assignments($context)->whereNull('ea.position_id')), 'of' => $headcount];
            $checks[] = ['check' => 'multiple_current_assignments', 'count' => (int) DB::query()->fromSub(
                WorkforceQueries::assignments($context)->selectRaw('ea.employee_id')->groupBy('ea.employee_id')->havingRaw('COUNT(*) > 1'), 'm')->count(), 'of' => $headcount];

            $capacity = WorkforceQueries::capacityByOrganization($context);
            $positions = array_sum(array_column($capacity, 'positions'));
            $checks[] = ['check' => 'positions_over_capacity', 'count' => array_sum(array_map(fn (array $r): int => $r['over'] > 0 ? 1 : 0, $capacity)), 'of' => count($capacity)];
            $checks[] = ['check' => 'positions_without_establishment', 'count' => $this->positionsWithoutEstablishment($context), 'of' => $positions];
        }

        if ($context->can(SituationPermissions::PUBLIC_VOICE)) {
            $public = DB::table('employee_service_feedback as f')->where('f.status', '!=', 'hidden');
            $context->scope->apply($public, 'f.organization_id');
            $nonFeedback = (clone $public)->whereIn('f.type', ['suggestion', 'complaint']);
            $tips = (clone $public)->where('f.type', 'suggestion');
            $checks[] = ['check' => 'submissions_without_category', 'count' => (clone $nonFeedback)->whereNull('f.category_id')->count(), 'of' => (clone $nonFeedback)->count()];
            $checks[] = ['check' => 'tip_offs_unclassified_legacy', 'count' => (clone $tips)->whereNull('f.tip_type')->count(), 'of' => (clone $tips)->count()];
        }

        $versionId = app(SituationScopeResolver::class)->publishedVersionId();
        $organizations = DB::table('organizations as o')->whereNull('o.deleted_at');
        $context->scope->apply($organizations, 'o.id');
        $organizationCount = (clone $organizations)->count();
        $checks[] = ['check' => 'organizations_not_in_hierarchy', 'count' => $versionId === null ? $organizationCount : (clone $organizations)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('organization_closure_paths as cp')
                ->whereColumn('cp.descendant_organization_id', 'o.id')->where('cp.hierarchy_version_id', $versionId))
            ->count(), 'of' => $organizationCount];

        return $checks;
    }

    private function checks(SituationContext $context): SituationPanel
    {
        $rows = array_map(fn (array $c): array => [
            'check' => SituationLabel::key("situationRoom.quality.{$c['check']}"),
            'count' => $c['count'],
            'of' => $c['of'],
            'share' => $this->percent($c['count'], $c['of']),
            'state' => $c['count'] === 0 ? SituationLabel::key('situationRoom.quality.state_ok') : SituationLabel::key('situationRoom.quality.state_review'),
            'fix_in' => SituationLabel::key("situationRoom.quality.fix.{$c['check']}"),
        ], $this->results($context));

        return SituationPanel::table('quality_checks', $this->title('quality_checks'), $this->hint('quality_checks'), 'situationRoom.sources.quality', [
            ['key' => 'check', 'label' => SituationLabel::key('situationRoom.columns.check'), 'type' => 'label'],
            ['key' => 'count', 'label' => SituationLabel::key('situationRoom.columns.records'), 'type' => 'number'],
            ['key' => 'of', 'label' => SituationLabel::key('situationRoom.columns.of'), 'type' => 'number'],
            ['key' => 'share', 'label' => SituationLabel::key('situationRoom.columns.share'), 'type' => 'percent'],
            ['key' => 'state', 'label' => SituationLabel::key('situationRoom.columns.state'), 'type' => 'label'],
            ['key' => 'fix_in', 'label' => SituationLabel::key('situationRoom.columns.fix_in'), 'type' => 'label'],
        ], $rows, span: 3);
    }

    private function positionsWithoutEstablishment(SituationContext $context): int
    {
        $today = now()->toDateString();
        $query = DB::table('positions as p')->whereNull('p.deleted_at')->where('p.is_active', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('position_establishments as pe')
                ->whereColumn('pe.position_id', 'p.id')->where('pe.status', 'approved')->whereNull('pe.deleted_at')
                ->whereRaw('DATE(pe.effective_from) <= ?', [$today])
                ->where(fn ($w) => $w->whereNull('pe.effective_to')->orWhereRaw('DATE(pe.effective_to) >= ?', [$today])));
        $context->scope->apply($query, 'p.organization_id');

        return $query->count();
    }
}
