<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\Organization;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\EmployeeCourtFiling;
use App\Services\CourtMs\EmployeeCourtPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Portal > My court cases > File a case (court-ms Flow 5). Only an employee
 * registered in EUISIS files, and only against an institution registered in
 * EUISIS; the case goes to court-ms with the grievances it concerns.
 */
class EmployeeCourtFilingController extends Controller
{
    /** court-ms refusals the page explains (courtCases.portal.errors.*); anything else reads as "cannot be reached". */
    private const KNOWN_ERRORS = ['linked_to_another_employee', 'identity_mismatch', 'employee_record_incomplete', 'phone_in_use', 'account_inactive'];

    public function create(Request $request, EmployeeCourtPortal $portal, EmployeeCourtFiling $filing): Response|RedirectResponse
    {
        $user = $request->user();
        $employee = $user?->employee;
        if ($employee === null || ! $portal->enabled()) {
            return to_route('employee.court-cases');
        }

        return Inertia::render('Employee/CourtCaseFile', [
            // One id per form: sending it twice files the case once.
            'filingId' => (string) Str::uuid(),
            'caseTypes' => $portal->caseTypes(),
            'organizations' => $filing->organizations()->orderBy('name_en')->get(['id', 'name_en', 'name_am'])
                ->map(fn (Organization $o): array => ['id' => (string) $o->getKey(), 'name_en' => $o->name_en, 'name_am' => $o->name_am])->values()->all(),
            'defaultOrganizationId' => $filing->ownOrganizationId($employee),
            'grievances' => $filing->grievances($user)->latest('submitted_at')->limit(50)->get()
                ->map(fn (Grievance $g): array => [
                    'id' => (string) $g->getKey(),
                    'reference_number' => $g->reference_number,
                    'subject' => $g->subject,
                    'status' => $g->status->value,
                    'submitted_at' => $g->submitted_at?->toIso8601String(),
                    'documents' => $filing->documentCount($g, $user),
                ])->values()->all(),
            'limits' => ['documents' => EmployeeCourtFiling::MAX_DOCUMENTS, 'documentMb' => EmployeeCourtFiling::MAX_DOCUMENT_BYTES / 1024 / 1024],
            // Why court-ms refused the last attempt, once (courtCases.portal.errors.*).
            'error' => $request->session()->get('court_error'),
        ]);
    }

    public function store(Request $request, EmployeeCourtPortal $portal, EmployeeCourtFiling $filing): RedirectResponse
    {
        $user = $request->user();
        $employee = $user?->employee;
        abort_if($employee === null || ! $portal->enabled(), 404);

        $data = $request->validate([
            'filing_id' => ['required', 'uuid'],
            'case_type_id' => ['required', 'integer', Rule::in(array_column($portal->caseTypes(), 'id'))],
            'description' => ['required', 'string', 'max:20000'],
            'relief_requested' => ['nullable', 'string', 'max:5000'],
            // Only an institution registered (and active) in EUISIS may be sued.
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'respondent_address' => ['nullable', 'string', 'max:500'],
            'grievance_ids' => ['nullable', 'array', 'max:20'],
            'grievance_ids.*' => ['uuid'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:pdf', 'max:'.(EmployeeCourtFiling::MAX_DOCUMENT_BYTES / 1024)],
        ]);

        $grievanceIds = array_values(array_unique((array) ($data['grievance_ids'] ?? [])));
        if ($grievanceIds !== [] && $filing->grievances($user)->whereKey($grievanceIds)->count() !== count($grievanceIds)) {
            throw ValidationException::withMessages(['grievance_ids' => __('validation.exists', ['attribute' => 'grievance'])]);
        }

        try {
            $case = $filing->file($employee, $user, $data, $grievanceIds, array_values((array) $request->file('files', [])));
        } catch (CourtMsException $e) {
            if ($e->status === 422 && $e->errors !== []) {
                // A court-ms rule the form did not catch: shown on the form, never as a crash.
                throw ValidationException::withMessages(['form' => (string) collect($e->errors)->flatten()->first()]);
            }
            $code = in_array($e->errorCode, self::KNOWN_ERRORS, true) ? $e->errorCode : 'unavailable';

            return back()->withInput()->with('court_error', $code);
        }

        // The court cases page confirms it with the case number (courtCases.portal.filed).
        return to_route('employee.court-cases')->with('court_filed', $case['case_number']);
    }
}
