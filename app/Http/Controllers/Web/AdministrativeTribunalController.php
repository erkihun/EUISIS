<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\AdministrativeTribunalCase;
use App\Services\CourtMs\CourtMsClient;
use App\Services\CourtMs\CourtMsReferrals;
use App\Services\CourtMs\CourtMsStatusSync;
use App\Services\Grievances\GrievanceAudit;
use App\Services\Grievances\GrievanceCaseAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrative Tribunal register. Cases arrive here when a grievance is
 * routed to an external authority flagged as the tribunal (configured route,
 * never hard-coded). Only holders of grievances.tribunal work this register.
 */
class AdministrativeTribunalController extends Controller
{
    public function index(Request $request, GrievanceCaseAccessService $access): Response
    {
        abort_unless($request->user()->can('grievances.tribunal'), 403);

        $query = AdministrativeTribunalCase::query()
            ->with(['grievance:id,reference_number,organization_id,category_id,status', 'grievance.organization:id,name_en,name_am', 'grievance.category:id,name_en,name_am', 'assignedToUser:id,name'])
            ->whereHas('grievance', fn ($grievances) => $access->constrainAssigned($grievances, $request->user()))
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        return Inertia::render('AdministrativeTribunal/Index', [
            'cases' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('status'),
            'statuses' => ['open', 'hearing', 'decided', 'closed'],
        ]);
    }

    public function show(Request $request, AdministrativeTribunalCase $administrativeTribunalCase, GrievanceCaseAccessService $access): Response
    {
        abort_unless($request->user()->can('grievances.tribunal')
            && $administrativeTribunalCase->grievance !== null
            && $access->canView($request->user(), $administrativeTribunalCase->grievance), 403);

        // Decided on the whole grievance record, before it is reloaded with the page's columns only.
        $canRefer = $request->user()->can('grievances.tribunal') && $access->handlesCurrent($request->user(), $administrativeTribunalCase->grievance);
        $administrativeTribunalCase->load([
            'grievance:id,reference_number,organization_id,category_id,employee_id,status,subject,description,submitted_at',
            'grievance.organization:id,name_en,name_am', 'grievance.category:id,code,name_en,name_am',
            'grievance.employee:id,full_name,name_en,employee_number,phone,email',
            'assignedToUser:id,name', 'createdByUser:id,name', 'referredByUser:id,name',
        ]);

        return Inertia::render('AdministrativeTribunal/Show', [
            'case' => $administrativeTribunalCase,
            'can' => ['update' => $request->user()->can('grievances.tribunal'), 'refer' => $canRefer],
            'courtMs' => $this->courtMsFrame($administrativeTribunalCase, $canRefer),
        ]);
    }

    /** Queues the referral of this tribunal case to court-ms (ADR 0013, Flow 2). */
    public function refer(Request $request, AdministrativeTribunalCase $administrativeTribunalCase, GrievanceCaseAccessService $access, CourtMsClient $client, CourtMsReferrals $referrals): RedirectResponse
    {
        abort_unless($request->user()->can('grievances.tribunal')
            && $administrativeTribunalCase->grievance !== null
            && $access->handlesCurrent($request->user(), $administrativeTribunalCase->grievance), 403);
        abort_unless($client->enabled(), 409, __('grievances.courtMs.disabled'));

        // A failed referral may be corrected and sent again; one sent or queued may not.
        $data = $request->validate([
            'case_type_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string', 'max:10000'],
            'relief_requested' => ['nullable', 'string', 'max:5000'],
            'respondent_name' => ['required', 'string', 'max:255'],
            'complainant_phone' => ['nullable', 'string', 'max:40'],
            'complainant_email' => ['nullable', 'email', 'max:255'],
            'evidence_ids' => ['nullable', 'array', 'max:'.(int) config('court_ms.max_documents', 10)],
            'evidence_ids.*' => ['uuid'],
        ]);

        $referrals->queue($administrativeTribunalCase->loadMissing('grievance'), $request->user(), $data);

        return to_route('tribunal-cases.show', $administrativeTribunalCase)
            ->with('flash', ['message' => __('grievances.courtMs.queued'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function courtMsFrame(AdministrativeTribunalCase $case, bool $canRefer): array
    {
        $client = app(CourtMsClient::class);
        $referrals = app(CourtMsReferrals::class);
        $sync = app(CourtMsStatusSync::class);
        $enabled = $client->enabled();
        $mayRefer = $enabled && $canRefer && ! $case->isReferred();

        return [
            'enabled' => $enabled,
            'admin_url' => $case->court_case_id !== null && filled(config('court_ms.url'))
                ? config('court_ms.url').'/admin/cases/'.$case->court_case_id : null,
            'case_types' => $mayRefer ? $client->caseTypes() : [],
            'defaults' => $mayRefer ? $referrals->defaults($case) : null,
            'evidence' => $mayRefer ? $referrals->attachableEvidence($case)->map(fn ($e) => [
                'id' => $e->id, 'title' => $e->title ?: $e->original_name, 'size_bytes' => (int) $e->size_bytes,
            ])->values()->all() : [],
            'last_poll_at' => $enabled ? $sync->state(CourtMsStatusSync::LAST_RUN_KEY) : null,
            'last_poll_error' => $enabled ? $sync->state(CourtMsStatusSync::LAST_ERROR_KEY) : null,
        ];
    }

    public function update(Request $request, AdministrativeTribunalCase $administrativeTribunalCase, GrievanceAudit $audit, GrievanceCaseAccessService $access): RedirectResponse
    {
        abort_unless($request->user()->can('grievances.tribunal')
            && $administrativeTribunalCase->grievance !== null
            && $access->handlesCurrent($request->user(), $administrativeTribunalCase->grievance), 403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:open,hearing,decided,closed'],
            'decision_summary' => ['nullable', 'string', 'max:10000'],
            'hearing_date' => ['nullable', 'date'],
            'decision_date' => ['nullable', 'date'],
        ]);

        $old = $administrativeTribunalCase->only(['status', 'hearing_date', 'decision_date']);
        $administrativeTribunalCase->update($data);
        $audit->record(AuditEventType::GrievanceConfigurationChanged, $request->user(), $administrativeTribunalCase,
            ['tribunal_status' => $data['status'], 'hearing_date' => $data['hearing_date'] ?? null, 'decision_date' => $data['decision_date'] ?? null],
            collect($old)->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)->all());

        return to_route('tribunal-cases.show', $administrativeTribunalCase)
            ->with('flash', ['message' => __('grievances.tribunalCaseUpdated'), 'type' => 'success']);
    }
}
