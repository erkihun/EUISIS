<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\DisciplinaryCase;
use App\Models\Grievance;
use App\Models\Organization;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\EmployeeCourtFiling;
use App\Services\CourtMs\EmployeeCourtPortal;
use Illuminate\Http\JsonResponse;
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
            // The employee's decided discipline cases (ADR 0013 #14): a case may challenge one, and its record travels with it.
            'disciplines' => $filing->disciplines($employee)->latest('delivered_at')->limit(50)->get()
                ->map(fn (DisciplinaryCase $c): array => [
                    'id' => (string) $c->getKey(),
                    'case_number' => $c->case_number,
                    'summary' => $c->summary ? mb_substr(strip_tags((string) $c->summary), 0, 200) : null,
                    'status' => $c->status?->value,
                    'delivered_at' => $c->delivered_at?->toIso8601String(),
                ])->values()->all(),
            // The court's register of verified lawyers (ADR 0013 #13): name and licence number only.
            'lawyers' => $portal->lawyers(),
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
            'discipline_ids' => ['nullable', 'array', 'max:20'],
            'discipline_ids.*' => ['uuid'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:pdf', 'max:'.(EmployeeCourtFiling::MAX_DOCUMENT_BYTES / 1024)],
            // Other employees filing with them (each confirms in their own My Portal) and a lawyer (ADR 0013 #13).
            'co_applicants' => ['nullable', 'array', 'max:20'],
            'co_applicants.*' => ['string', 'max:60', 'distinct'],
            'lawyer_license_number' => ['nullable', 'string', 'max:80'],
            'power_of_attorney' => ['nullable', 'required_with:lawyer_license_number', 'file', 'mimes:pdf', 'max:'.(EmployeeCourtFiling::MAX_DOCUMENT_BYTES / 1024)],
        ]);

        $grievanceIds = array_values(array_unique((array) ($data['grievance_ids'] ?? [])));
        if ($grievanceIds !== [] && $filing->grievances($user)->whereKey($grievanceIds)->count() !== count($grievanceIds)) {
            throw ValidationException::withMessages(['grievance_ids' => __('validation.exists', ['attribute' => 'grievance'])]);
        }
        $disciplineIds = array_values(array_unique((array) ($data['discipline_ids'] ?? [])));
        if ($disciplineIds !== [] && $filing->disciplines($employee)->whereKey($disciplineIds)->count() !== count($disciplineIds)) {
            throw ValidationException::withMessages(['discipline_ids' => __('validation.exists', ['attribute' => 'discipline case'])]);
        }
        $coApplicants = [];
        foreach (array_values((array) ($data['co_applicants'] ?? [])) as $i => $number) {
            $colleague = $filing->colleague((string) $number);
            if ($colleague === null || $colleague->is($employee)) {
                throw ValidationException::withMessages(["co_applicants.{$i}" => $colleague === null
                    ? __('employee-portal.court.colleague_not_found', ['number' => trim((string) $number)])
                    : __('employee-portal.court.colleague_is_you')]);
            }
            $coApplicants[] = ['uuid' => (string) $colleague->getKey(), 'name' => $filing->nameOf($colleague)];
        }
        $lawyer = filled($data['lawyer_license_number'] ?? null) ? trim((string) $data['lawyer_license_number']) : null;
        if ($lawyer !== null && ! in_array($lawyer, array_column($portal->lawyers(), 'license_number'), true)) {
            throw ValidationException::withMessages(['lawyer_license_number' => __('employee-portal.court.lawyer_not_listed')]);
        }

        try {
            $case = $filing->file($employee, $user, $data, $grievanceIds, array_values((array) $request->file('files', [])),
                $coApplicants, $lawyer, $lawyer !== null ? $request->file('power_of_attorney') : null, $disciplineIds);
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

    /** The active employee with this number, by name only: the filing form checks a co-applicant before adding them. */
    public function colleague(Request $request, EmployeeCourtFiling $filing): JsonResponse
    {
        abort_if($request->user()?->employee === null, 404);
        $data = $request->validate(['employee_number' => ['required', 'string', 'max:60']]);
        $colleague = $filing->colleague($data['employee_number']);
        if ($colleague === null || $colleague->is($request->user()->employee)) {
            return response()->json(['message' => $colleague === null
                ? __('employee-portal.court.colleague_not_found', ['number' => trim($data['employee_number'])])
                : __('employee-portal.court.colleague_is_you')], 404);
        }

        return response()->json(['data' => ['employee_number' => (string) $colleague->employee_number, 'name' => $filing->nameOf($colleague)]]);
    }
}
