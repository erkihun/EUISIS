<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\AdministrativeTribunalCase;
use App\Services\Grievances\GrievanceAudit;
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
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('grievances.tribunal'), 403);

        $query = AdministrativeTribunalCase::query()
            ->with(['grievance:id,reference_number,organization_id,category_id,status', 'grievance.organization:id,name_en,name_am', 'grievance.category:id,name_en,name_am', 'assignedToUser:id,name'])
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

    public function show(Request $request, AdministrativeTribunalCase $administrativeTribunalCase): Response
    {
        abort_unless($request->user()->can('grievances.tribunal'), 403);

        $administrativeTribunalCase->load([
            'grievance:id,reference_number,organization_id,category_id,employee_id,status,subject,submitted_at',
            'grievance.organization:id,name_en,name_am', 'grievance.category:id,name_en,name_am',
            'grievance.employee:id,full_name,name_en,employee_number',
            'assignedToUser:id,name', 'createdByUser:id,name',
        ]);

        return Inertia::render('AdministrativeTribunal/Show', [
            'case' => $administrativeTribunalCase,
            'can' => ['update' => $request->user()->can('grievances.tribunal')],
        ]);
    }

    public function update(Request $request, AdministrativeTribunalCase $administrativeTribunalCase, GrievanceAudit $audit): RedirectResponse
    {
        abort_unless($request->user()->can('grievances.tribunal'), 403);

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
