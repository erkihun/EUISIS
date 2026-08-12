<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeTribunalCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AdministrativeTribunalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();

        if (! $user->hasAnyPermission(['grievances.tribunal', 'grievances.manage'])) {
            abort(403);
        }

        $query = AdministrativeTribunalCase::query()
            ->with(['grievance.organization', 'grievance.category', 'assignedToUser'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return Inertia::render('AdministrativeTribunal/Index', [
            'cases' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('status'),
            'statuses' => ['open', 'hearing', 'decided', 'closed'],
        ]);
    }

    public function show(AdministrativeTribunalCase $administrativeTribunalCase): Response
    {
        $user = Auth::user();

        if (! $user->hasAnyPermission(['grievances.tribunal', 'grievances.manage'])) {
            abort(403);
        }

        $administrativeTribunalCase->load([
            'grievance.organization', 'grievance.category',
            'grievance.employee', 'grievance.responses',
            'assignedToUser', 'createdByUser',
        ]);

        return Inertia::render('AdministrativeTribunal/Show', [
            'case' => $administrativeTribunalCase,
            'can' => ['update' => true],
        ]);
    }

    public function update(Request $request, AdministrativeTribunalCase $administrativeTribunalCase): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasAnyPermission(['grievances.tribunal', 'grievances.manage'])) {
            abort(403);
        }

        $data = $request->validate([
            'status' => ['required', 'string', 'in:open,hearing,decided,closed'],
            'decision_summary' => ['nullable', 'string'],
            'hearing_date' => ['nullable', 'date'],
            'decision_date' => ['nullable', 'date'],
        ]);

        $administrativeTribunalCase->update($data);

        return to_route('tribunal-cases.show', $administrativeTribunalCase)
            ->with('flash', ['message' => __('grievances.tribunalCaseUpdated'), 'type' => 'success']);
    }
}
