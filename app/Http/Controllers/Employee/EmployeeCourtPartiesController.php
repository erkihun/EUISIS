<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\EmployeeCourtPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Portal > My court cases: the other parties to a case (court-ms, ADR 0013 #13).
 * An employee named as a co-applicant, or the client of a lawyer who filed for
 * them, confirms here before the case is theirs; an employee applies here to
 * intervene in another employee's case, and only the judge of the case decides.
 */
class EmployeeCourtPartiesController extends Controller
{
    /** court-ms refusals the pages explain (courtCases.portal.errors.*); anything else reads as "cannot be reached". */
    private const KNOWN_ERRORS = ['linked_to_another_employee', 'identity_mismatch', 'employee_record_incomplete', 'phone_in_use', 'account_inactive', 'not_found'];

    public function __construct(private readonly EmployeeCourtPortal $portal) {}

    /**
     * The employee joins the case they were invited to, or declines. Joining, they may
     * name a lawyer from the court's register, with the power of attorney the registry checks.
     */
    public function respond(Request $request, int $party): RedirectResponse
    {
        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'lawyer_license_number' => ['nullable', 'string', 'max:60'],
            'power_of_attorney' => ['nullable', 'required_with:lawyer_license_number', 'file', 'mimes:pdf', 'max:10240'],
        ]);
        $employee = $this->employee($request);
        $accept = (bool) $data['accept'];
        $lawyer = $accept && filled($data['lawyer_license_number'] ?? null) ? trim((string) $data['lawyer_license_number']) : null;
        $document = $lawyer !== null ? $request->file('power_of_attorney') : null;

        try {
            $result = $this->portal->respondToInvitation($employee, $party, $accept, $lawyer,
                $document !== null ? ['name' => $document->getClientOriginalName() ?: 'power-of-attorney.pdf', 'contents' => (string) $document->get()] : null);
        } catch (CourtMsException $e) {
            if ($e->status === 422) {
                throw ValidationException::withMessages($e->errors !== [] ? $e->errors : ['lawyer_license_number' => [$e->getMessage()]]);
            }

            return $this->failed($e);
        }

        // Joined: the case is now theirs to follow here.
        return $accept && ($result['case_id'] ?? null)
            ? to_route('employee.court-cases.show', (int) $result['case_id'])->with('court_done', 'invitationAccepted')
            : to_route('employee.court-cases')->with('court_done', 'invitationDeclined');
    }

    public function intervene(Request $request): Response
    {
        $this->employee($request);

        return Inertia::render('Employee/CourtCaseIntervene', [
            'caseNumber' => (string) $request->query('case', ''),
            'error' => $request->session()->get('court_error'),
        ]);
    }

    /** The employee applies to intervene, with their grounds and an optional PDF. */
    public function storeIntervention(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'case_number' => ['required', 'string', 'max:60'],
            'statement' => ['required', 'string', 'max:5000'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);
        $employee = $this->employee($request);
        $document = $request->file('document');

        try {
            $this->portal->intervene($employee, trim($data['case_number']), $data['statement'],
                $document !== null ? ['name' => $document->getClientOriginalName() ?: 'document.pdf', 'contents' => (string) $document->get()] : null);
        } catch (CourtMsException $e) {
            if ($e->status === 422) {
                throw ValidationException::withMessages($e->errors !== [] ? $e->errors : ['case_number' => [$e->getMessage()]]);
            }

            return $this->failed($e, back: true);
        }

        return to_route('employee.court-cases')->with('court_done', 'interventionSent');
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;
        abort_if($employee === null || ! $this->portal->enabled(), 404);

        return $employee;
    }

    private function failed(CourtMsException $e, bool $back = false): RedirectResponse
    {
        if ($e->status === 422) {
            throw ValidationException::withMessages($e->errors !== [] ? $e->errors : ['form' => [$e->getMessage()]]);
        }
        $target = $back ? back()->withInput() : to_route('employee.court-cases');

        return $target->with('court_error', in_array($e->errorCode, self::KNOWN_ERRORS, true) ? $e->errorCode : 'unavailable');
    }
}
