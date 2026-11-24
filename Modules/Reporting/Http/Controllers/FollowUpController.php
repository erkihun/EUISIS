<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Queries\WorkQueues;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\UseCases\ManageFollowUps;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Http\Support\Present;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUp;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;

/** Follow-up actions arising from reports. */
final class FollowUpController extends ReportingController
{
    public function index(Request $request, WorkQueues $queues): Response
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::VIEW);
        $filters = ['status' => in_array($request->query('status'), ['active', ...ManageFollowUps::STATUSES], true) ? $request->query('status') : 'active', 'mine' => $request->boolean('mine')];
        $page = $queues->followUps($actor, $filters);
        $obligations = Obligation::query()->with('cycle.reportType')->whereKey($page->getCollection()->pluck('obligation_id')->unique())->get()->keyBy('id');

        return Inertia::render('modules/reporting/FollowUps', [
            'filters' => $filters,
            'rows' => $page->through(fn (FollowUp $f): array => [
                ...Present::followUp($f),
                'obligation' => isset($obligations[$f->obligation_id]) ? Present::obligation($obligations[$f->obligation_id]) : null,
            ]),
        ]);
    }

    public function store(Request $request, string $obligation, ManageFollowUps $followUps): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'], 'description' => ['required', 'string', 'max:5000'],
            'owner_user_id' => ['nullable', 'integer'], 'priority' => ['required', Rule::in(ManageFollowUps::PRIORITIES)], 'due_at' => ['required', 'date'],
        ]);
        $followUps->create($this->actor($request), $obligation, [...$data, 'due_at' => CarbonImmutable::parse($data['due_at'], 'Africa/Addis_Ababa')->endOfDay()->utc()->toDateTimeImmutable()]);

        return back()->with('success', __('reporting::messages.flash.follow_up_saved'));
    }

    public function update(Request $request, string $followUp, ManageFollowUps $followUps, ReportingSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'progress' => ['required', 'integer', 'min:0', 'max:100'], 'status' => ['required', Rule::in(ManageFollowUps::STATUSES)], 'note' => ['required', 'string', 'max:5000'],
            'evidence' => ['nullable', 'file', 'max:'.(int) $settings->get('max_upload_kb')],
        ]);
        $file = $request->file('evidence');
        $followUps->update($this->actor($request), $followUp, ['progress' => (int) $data['progress'], 'status' => $data['status'], 'note' => $data['note']],
            $file === null ? null : ['path' => $file->getRealPath(), 'name' => $file->getClientOriginalName()]);

        return back()->with('success', __('reporting::messages.flash.follow_up_saved'));
    }
}
