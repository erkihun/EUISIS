<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Business;

use App\Enums\AssignmentStatus;
use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationDesignStatus;
use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\ServiceFeedback\PublicFeedbackDataQuality;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationMemo;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationScopeResolver;
use App\Services\SituationRoom\SituationSettings;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Transport\Contracts\TransportDataQuality;

/**
 * Data Quality Intelligence: how far every other business figure can be
 * trusted. Each check is one scope-confined count of records that are
 * missing, contradictory or dangling, with a severity (FAIL: the figure
 * built on it is wrong; WARN: the figure is incomplete), what it affects
 * and where it is fixed. Nothing is corrected here and no record is listed.
 *
 * The public-feedback integrity checks (PublicFeedbackDataQuality) are
 * installation-wide, so they appear only for an unrestricted viewer.
 */
final class DataQualityAnalysis extends IntelligenceAnalysis
{
    use BusinessQueries;

    public const FAIL = 'FAIL';

    public const WARN = 'WARN';

    public function __construct(
        OrganizationRollup $rollup,
        SituationSettings $settings,
        private readonly SituationScopeResolver $resolver,
        private readonly WorkforceAnalysis $workforce,
    ) {
        parent::__construct($rollup, $settings);
    }

    public function panels(SituationContext $context): array
    {
        $panels = [
            'quality_summary' => fn (): SituationPanel => $this->summary($context),
            'quality_checks' => fn (): SituationPanel => $this->table($context),
        ];
        if ($this->resolver->publishedVersionId() === null) {
            $panels['quality_hierarchy'] = fn (): SituationPanel => $this->notice('quality_hierarchy', $this->source('data_quality'), 'NEEDS_POLICY_DECISION_REPORTING_HIERARCHY', 3);
        }
        if ($context->scope->organizationIds === null) {
            $panels['quality_public_feedback'] = fn (): SituationPanel => $this->publicFeedback();
        }
        if ($context->scope->organizationIds === null && Features::enabled('transport')) {
            $panels['quality_transport'] = fn (): SituationPanel => $this->transport();
        }

        return $panels;
    }

    public function headline(SituationContext $context): SituationPanel
    {
        $s = $this->summaryCounts($context);

        return SituationPanel::kpis('quality_headline', $this->title('quality_headline'), $this->source('data_quality'), [
            $this->kpi('checks_failing', $s['failing']),
            $this->kpi('checks_warning', $s['warning']),
            $this->kpi('records_flagged', $s['records']),
        ], $this->hint('quality_headline'));
    }

    public function insights(SituationContext $context, array $panels): array
    {
        $insights = [];
        foreach ($this->checks($context) as $check) {
            if ($check['severity'] !== self::FAIL || $check['count'] <= 0) {
                continue;
            }
            $insights[] = $this->insight('data_quality_fail', $this->severityByCount($check['count']), [
                'name' => trans('intelligence_business.quality.'.$check['code'].'.label', [], 'en'), 'count' => $check['count'],
            ], [
                ['label' => SituationLabel::key(self::PREFIX."quality.{$check['code']}.label"), 'value' => $check['count'], 'href' => $check['href']],
                ['label' => SituationLabel::key(self::PREFIX.'columns.impact'), 'value' => trans('intelligence_business.quality.'.$check['code'].'.impact', [], 'en'), 'href' => null],
            ], href: $check['href'], source: $this->source('data_quality'), metric: $check['code']);
        }

        return $insights;
    }

    /**
     * Every check, memoised per request.
     *
     * @return list<array{code: string, severity: string, count: int, href: ?string}>
     */
    public function checks(SituationContext $context): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:quality:checks'), fn (): array => $this->compute($context));
    }

    /** @return array{failing: int, warning: int, records: int} */
    public function summaryCounts(SituationContext $context): array
    {
        $checks = $this->checks($context);

        return [
            'failing' => count(array_filter($checks, fn (array $c): bool => $c['severity'] === self::FAIL && $c['count'] > 0)),
            'warning' => count(array_filter($checks, fn (array $c): bool => $c['severity'] === self::WARN && $c['count'] > 0)),
            'records' => array_sum(array_column($checks, 'count')),
        ];
    }

    /** @return list<array{code: string, severity: string, count: int, href: ?string}> */
    private function compute(SituationContext $context): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $anomalies = $this->workforce->anomalyCounts($context);
        $gaps = $this->workforce->designGapCounts($context);
        $versionId = $this->resolver->publishedVersionId();
        $employees = $this->link('employees.index');
        $positions = $this->link('positions.index');
        $organizations = $this->link('organizations.index');
        $units = $this->link('organization-units.index');

        $assignments = fn (): Builder => $context->scope->apply(DB::table('employee_assignments as ea'), 'ea.organization_id');
        $establishments = function () use ($context, $today): Builder {
            $q = DB::table('position_establishments as pe')->where('pe.status', EstablishmentStatus::Approved->value)->whereNull('pe.deleted_at')
                ->whereRaw('DATE(pe.effective_from) <= ?', [$today])
                ->where(fn ($w) => $w->whereNull('pe.effective_to')->orWhereRaw('DATE(pe.effective_to) >= ?', [$today]));

            return $context->scope->apply($q, 'pe.organization_id');
        };

        $checks = [
            ['duplicate_current_assignments', self::FAIL, $anomalies['multiple_current_assignments'], $employees],
            ['active_without_assignment', self::WARN, $anomalies['active_without_assignment'], $employees],
            ['positions_over_capacity', self::FAIL, $anomalies['positions_over_capacity'], $positions],
            ['approved_positions_without_occupant', self::WARN, (int) $establishments()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_assignments as ea')
                ->whereColumn('ea.position_id', 'pe.position_id')->where('ea.is_current', true)->where('ea.assignment_status', AssignmentStatus::Active->value))
                ->distinct()->count('pe.position_id'), $positions],
            ['assignments_outside_hierarchy', self::FAIL, $versionId === null ? 0 : (int) $assignments()->where('ea.is_current', true)->where('ea.assignment_status', AssignmentStatus::Active->value)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('organization_closure_paths as cp')->whereColumn('cp.descendant_organization_id', 'ea.organization_id')->where('cp.hierarchy_version_id', $versionId))
                ->count(), $organizations],
            ['positions_without_occupation', self::WARN, (int) $this->workforce->activePositions($context)->whereNull('p.occupation_id')->count(), $positions],
            ['units_without_type', self::WARN, (int) $context->scope->apply(DB::table('organization_units as u')->whereNull('u.deleted_at'), 'u.organization_id')
                ->where(fn ($q) => $q->whereNull('u.organization_unit_type_id')->where(fn ($w) => $w->whereNull('u.unit_type')->orWhere('u.unit_type', '')))->count(), $units],
            ['positions_without_jd', self::WARN, $gaps['without_jd'], $positions],
            ['positions_without_competency', self::WARN, $gaps['without_competency'], $positions],
            ['unmapped_legacy_positions', self::WARN, $this->unmappedLegacyPositions($context, $today), $positions],
            ['qr_without_public_phone', self::WARN, (int) $context->scope->apply(DB::table('organizations as o')->whereNull('o.deleted_at'), 'o.id')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('organization_feedback_qr_codes as q')->whereColumn('q.organization_id', 'o.id')->where('q.status', 'active'))
                ->where(fn ($q) => $q->whereNull('o.public_contact_phone')->orWhere('o.public_contact_phone', ''))->count(), $this->link('service-feedback.organization-qr.index')],
            ['assignments_effective_to_before_from', self::FAIL, (int) $assignments()->whereNotNull('ea.effective_to')->whereColumn('ea.effective_to', '<', 'ea.effective_from')->count(), $employees],
            ['positions_effective_to_before_from', self::FAIL, (int) $this->workforce->activePositions($context)->whereNotNull('p.effective_to')->whereColumn('p.effective_to', '<', 'p.effective_from')->count(), $positions],
            ['establishments_effective_to_before_from', self::FAIL, (int) $context->scope->apply(DB::table('position_establishments as pe')->whereNull('pe.deleted_at'), 'pe.organization_id')
                ->whereNotNull('pe.effective_to')->whereColumn('pe.effective_to', '<', 'pe.effective_from')->count(), $positions],
        ];

        return array_map(fn (array $c): array => ['code' => $c[0], 'severity' => $c[1], 'count' => $c[2], 'href' => $c[3]], $checks);
    }

    /**
     * The LegacyMappingService rule (UNMAPPED_LEGACY: no occupation, no work
     * link, no published profile, no job description in force) in one
     * scope-confined count, so a city-wide view never loads a position row.
     */
    private function unmappedLegacyPositions(SituationContext $context, string $today): int
    {
        return (int) $this->workforce->activePositions($context)
            ->whereNull('p.occupation_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('position_work_links as wl')->whereColumn('wl.position_id', 'p.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('position_profiles as pp')->whereColumn('pp.position_id', 'p.id')->whereNull('pp.deleted_at')->where('pp.status', OrganizationDesignStatus::Published->value))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('job_descriptions as jd')->whereColumn('jd.position_id', 'p.id')->whereNull('jd.deleted_at')
                ->whereIn('jd.status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])
                ->whereRaw('DATE(jd.effective_from) <= ?', [$today])
                ->where(fn ($w) => $w->whereNull('jd.effective_to')->orWhereRaw('DATE(jd.effective_to) >= ?', [$today])))
            ->count();
    }

    private function summary(SituationContext $context): SituationPanel
    {
        $s = $this->summaryCounts($context);

        return SituationPanel::kpis('quality_summary', $this->title('quality_summary'), $this->source('data_quality'), [
            $this->kpi('checks_run', count($this->checks($context))),
            $this->kpi('checks_failing', $s['failing']),
            $this->kpi('checks_warning', $s['warning']),
            $this->kpi('records_flagged', $s['records']),
        ], $this->hint('quality_summary'));
    }

    private function table(SituationContext $context): SituationPanel
    {
        $rows = array_map(fn (array $c): array => [
            'check' => SituationLabel::key(self::PREFIX."quality.{$c['code']}.label"),
            'severity' => $this->value('severity_code', $c['severity']),
            'count' => $c['count'],
            'state' => $c['count'] === 0 ? SituationLabel::key('situationRoom.quality.state_ok') : SituationLabel::key('situationRoom.quality.state_review'),
            'impact' => SituationLabel::key(self::PREFIX."quality.{$c['code']}.impact"),
            'remediation' => SituationLabel::key(self::PREFIX."quality.{$c['code']}.remediation"),
        ], $this->checks($context));

        return SituationPanel::table('quality_checks', $this->title('quality_checks'), $this->hint('quality_checks'), $this->source('data_quality'), [
            $this->column('check', 'label'),
            $this->column('severity', 'label'),
            $this->column('count'),
            $this->column('state', 'label'),
            $this->column('impact', 'label'),
            $this->column('remediation', 'label'),
        ], $rows, span: 3);
    }

    /** Installation-wide transport master-data checks (Transport's TransportDataQuality contract): counts and where each is fixed. */
    private function transport(): SituationPanel
    {
        $rows = array_map(fn (array $c): array => [
            'check' => SituationLabel::key(self::PREFIX."quality.transport_{$c['code']}.label"),
            'severity' => $this->value('severity_code', $c['severity'] === 'critical' ? self::FAIL : self::WARN),
            'count' => max(0, $c['count']),
            'state' => $c['count'] === 0 ? SituationLabel::key('situationRoom.quality.state_ok') : SituationLabel::key('situationRoom.quality.state_review'),
            'impact' => SituationLabel::key(self::PREFIX."quality.transport_{$c['code']}.impact"),
            'remediation' => SituationLabel::key(self::PREFIX."quality.transport_{$c['code']}.remediation"),
        ], app(TransportDataQuality::class)->summary());

        return SituationPanel::table('quality_transport', $this->title('quality_transport'), $this->hint('quality_transport'), $this->source('data_quality'), [
            $this->column('check', 'label'),
            $this->column('severity', 'label'),
            $this->column('count'),
            $this->column('state', 'label'),
            $this->column('impact', 'label'),
            $this->column('remediation', 'label'),
        ], $rows, span: 3);
    }

    /** Installation-wide public-feedback integrity: the FAIL and WARN findings, counts only (never the sample ids). */
    private function publicFeedback(): SituationPanel
    {
        $findings = array_filter(app(PublicFeedbackDataQuality::class)->run(), fn (array $f): bool => in_array($f['severity'], [PublicFeedbackDataQuality::SEVERITY_FAIL, PublicFeedbackDataQuality::SEVERITY_WARN], true));
        $rows = array_map(fn (array $f): array => [
            'check' => $f['label'],
            'severity' => $this->value('severity_code', $f['severity']),
            'count' => $f['count'],
            'state' => $f['count'] === 0 ? SituationLabel::key('situationRoom.quality.state_ok') : SituationLabel::key('situationRoom.quality.state_review'),
        ], array_values($findings));

        return SituationPanel::table('quality_public_feedback', $this->title('quality_public_feedback'), $this->hint('quality_public_feedback'), $this->source('public_submissions'), [
            $this->column('check', 'text'),
            $this->column('severity', 'label'),
            $this->column('count'),
            $this->column('state', 'label'),
        ], $rows, span: 3);
    }
}
