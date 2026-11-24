<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\UseCases\ConsolidateReports;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Http\Support\Filters;
use Modules\Reporting\Http\Support\Present;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\Consolidation;
use Modules\Reporting\Infrastructure\Persistence\Models\ConsolidationSource;
use Modules\Reporting\Infrastructure\Persistence\Models\MetricCapture;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;

/** Consolidated (parent-level) reports compiled from approved submissions. */
final class ConsolidationController extends ReportingController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::CONSOLIDATE);
        $page = Consolidation::query()->with('cycle.reportType')
            ->when(! $actor->unrestricted, fn ($q) => $q->whereIn('parent_organization_id', $actor->organizationIds))
            ->orderByDesc('created_at')->paginate(20);

        return Inertia::render('modules/reporting/Consolidations', [
            'rows' => $page->through(fn (Consolidation $c): array => $this->present($c)),
            'options' => Filters::options(),
        ]);
    }

    public function store(Request $request, ConsolidateReports $consolidate): RedirectResponse
    {
        $data = $request->validate(['cycle_id' => ['required', 'uuid'], 'parent_organization_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'max:250']]);
        $consolidation = $consolidate->start($this->actor($request), $data['cycle_id'], $data['parent_organization_id'], $data['title']);

        return redirect()->route('reporting.consolidations.show', $consolidation->id);
    }

    public function show(Request $request, string $consolidation, ConsolidateReports $consolidate): Response
    {
        $actor = $this->actor($request);
        $record = Consolidation::query()->with('cycle.reportType')->findOrFail($consolidation);
        $consolidate->authorize($actor, $record->parent_organization_id);
        $sources = ConsolidationSource::query()->where('consolidation_id', $record->id)->get();
        $obligations = Obligation::query()->whereKey($sources->pluck('obligation_id'))->orderBy('organization_code')->get()->keyBy('id');

        return Inertia::render('modules/reporting/ConsolidationShow', [
            'consolidation' => [...$this->present($record), 'executive_summary' => $record->executive_summary, 'missing' => $record->missing ?? [], 'lock_version' => $record->lock_version],
            'sources' => $sources->filter(fn (ConsolidationSource $s): bool => isset($obligations[$s->obligation_id]))->map(fn (ConsolidationSource $s): array => [
                'obligation_id' => $s->obligation_id, 'submission_id' => $s->submission_id, 'version_no' => $s->version_no, 'reference_no' => $s->reference_no,
                'organization' => ['code' => $obligations[$s->obligation_id]->unit_code ?? $obligations[$s->obligation_id]->organization_code, 'name_en' => $obligations[$s->obligation_id]->subjectName('en'), 'name_am' => $obligations[$s->obligation_id]->subjectName('am')],
            ])->sortBy('organization.code')->values()->all(),
            'metrics' => MetricCapture::query()->where('owner_type', 'consolidation')->where('owner_id', $record->id)->get()
                ->map(fn (MetricCapture $m): array => ['source' => $m->source, 'definition' => $m->definition, 'value' => $m->value, 'unit' => $m->unit, 'captured_at' => $m->captured_at->toIso8601String()])->all(),
            'artifacts' => Artifact::query()->where('owner_type', 'consolidation')->where('owner_id', $record->id)->get()->map(fn (Artifact $a): array => Present::artifact($a))->all(),
        ]);
    }

    public function update(Request $request, string $consolidation, ConsolidateReports $consolidate): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:250'], 'executive_summary' => ['nullable', 'string', 'max:600000'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $consolidate->saveSummary($this->actor($request), $consolidation, $data['title'], $data['executive_summary'] ?? null, (int) $data['lock_version']);

        return back()->with('success', __('reporting::messages.flash.saved'));
    }

    public function compile(Request $request, string $consolidation, ConsolidateReports $consolidate): RedirectResponse
    {
        $consolidate->compile($this->actor($request), $consolidation);

        return back()->with('success', __('reporting::messages.flash.compiled'));
    }

    /** @return array<string, mixed> */
    private function present(Consolidation $c): array
    {
        return [
            'id' => $c->id, 'title' => $c->title, 'status' => $c->status, 'indicators' => $c->indicators,
            'parent' => ['id' => $c->parent_organization_id, 'name_en' => $c->parent_name_en, 'name_am' => $c->parent_name_am],
            'report_type' => ['name_en' => $c->cycle?->reportType?->name_en, 'name_am' => $c->cycle?->reportType?->name_am],
            'period' => ['label_en' => $c->cycle?->period_label_en, 'label_am' => $c->cycle?->period_label_am],
            'created_by_name' => $c->created_by_name, 'compiled_at' => $c->compiled_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
