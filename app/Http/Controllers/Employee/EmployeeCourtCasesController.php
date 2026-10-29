<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\EmployeeCourtPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Portal > My court cases (court-ms Flows 4 to 6): the employee's tribunal
 * cases and court notices, read from court-ms. Each case opens here
 * (EmployeeCourtCaseController) and is filed here (EmployeeCourtFilingController);
 * court-ms's own applicant portal is closed.
 */
class EmployeeCourtCasesController extends Controller
{
    public function index(Request $request, EmployeeCourtPortal $portal): Response
    {
        $employee = $request->user()?->employee;

        if ($employee === null || ! $portal->enabled()) {
            return Inertia::render('Employee/CourtCases', [
                'state' => $employee === null ? 'no_employee' : 'disabled',
                'summary' => null,
                'error' => null,
                'filed' => null,
                'parties' => null,
                'lawyers' => [],
                'done' => null,
            ]);
        }

        $summary = $portal->summary($employee);
        $parties = $summary === null ? null : $portal->parties($employee);
        // An employee joining a colleague's case may name a lawyer: the court's register, fetched only then.
        $namesLawyer = collect($parties['invitations'] ?? [])->contains(fn (array $invitation): bool => ($invitation['lawyer'] ?? null) === null);

        return Inertia::render('Employee/CourtCases', [
            'state' => match (true) {
                $summary === null => 'unavailable',
                ! ($summary['linked'] ?? false) => 'not_linked',
                default => 'linked',
            },
            'summary' => ($summary['linked'] ?? false) ? [
                'counts' => (array) ($summary['counts'] ?? []),
                'cases' => array_values((array) ($summary['cases'] ?? [])),
                'notices' => array_values((array) ($summary['notices'] ?? [])),
            ] : null,
            // Why court-ms refused the last step, once.
            'error' => $request->session()->get('court_error'),
            // The case number of a case just filed from My Portal (Flow 5).
            'filed' => $request->session()->get('court_filed'),
            // Invitations to join a case and applications to intervene (ADR 0013 #13), even before
            // the employee has a court account: answering an invitation opens it.
            'parties' => $parties,
            'lawyers' => $namesLawyer ? $portal->lawyers() : [],
            // The step just done (courtCases.portal.done.*).
            'done' => $request->session()->get('court_done'),
        ]);
    }

    /** Marks one court notice, or all of them, as read (court-ms Flow 6). */
    public function readNotices(Request $request, EmployeeCourtPortal $portal): RedirectResponse
    {
        $data = $request->validate([
            'all' => ['nullable', 'boolean'],
            'id' => ['required_without:all', 'nullable', 'string', 'max:60', 'regex:/^[a-z_]+:\d+$/'],
        ]);
        $employee = $request->user()?->employee;
        abort_if($employee === null || ! $portal->enabled(), 404);

        try {
            $portal->workspace($employee, 'notices/read', (bool) ($data['all'] ?? false) ? ['all' => true] : ['id' => $data['id']], changes: true);
        } catch (CourtMsException) {
            return back()->with('court_error', 'unavailable');
        }

        return back();
    }
}
