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
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * My Portal > My court cases (court-ms Flow 4): the employee's tribunal cases,
 * read from court-ms, and the way into the court portal for what they do there
 * (reply, follow), already signed in. Cases are filed in EUISIS (Flow 5,
 * EmployeeCourtFilingController).
 */
class EmployeeCourtCasesController extends Controller
{
    /** court-ms error codes the page explains; anything else reads as "cannot be reached". */
    private const KNOWN_ERRORS = ['linked_to_another_employee', 'identity_mismatch', 'employee_record_incomplete', 'phone_in_use', 'account_inactive', 'unknown_target'];

    public function index(Request $request, EmployeeCourtPortal $portal): Response
    {
        $employee = $request->user()?->employee;

        if ($employee === null || ! $portal->enabled()) {
            return Inertia::render('Employee/CourtCases', [
                'state' => $employee === null ? 'no_employee' : 'disabled',
                'summary' => null,
                'error' => null,
                'filed' => null,
            ]);
        }

        $summary = $portal->summary($employee);

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
            // Why the last attempt to open the court portal was refused, once.
            'error' => $request->session()->get('court_error'),
            // The case number of a case just filed from My Portal (Flow 5).
            'filed' => $request->session()->get('court_filed'),
        ]);
    }

    /** Asks court-ms for a one-time sign-in link to `target` and sends the browser there. */
    public function open(Request $request, EmployeeCourtPortal $portal): SymfonyResponse|RedirectResponse
    {
        $target = (string) $request->validate(['target' => ['required', 'string', 'max:40']])['target'];
        abort_unless(EmployeeCourtPortal::validTarget($target), 422);

        $employee = $request->user()?->employee;
        abort_if($employee === null || ! $portal->enabled(), 404);

        try {
            $url = $portal->signInUrl($employee, $target);
        } catch (CourtMsException $e) {
            $code = in_array($e->errorCode, self::KNOWN_ERRORS, true) ? $e->errorCode : 'unavailable';
            if ($request->expectsJson()) {
                return response()->json(['error_code' => $code], $code === 'unavailable' ? 503 : 409);
            }

            // The page explains the code in the person's language (courtCases.portal.errors.*).
            return redirect()->route('employee.court-cases')->with('court_error', $code);
        }

        // The page opens a tab and points it at the link it asks for; a visit is sent there directly.
        if ($request->expectsJson()) {
            return response()->json(['url' => $url]);
        }

        return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
    }
}
