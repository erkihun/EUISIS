<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Api\Integration;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CaseApplicant;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Services\Euisis\EmployeeCourtAccess;
use Modules\Court\Application\Services\Euisis\EmployeeCourtAccessException;
use Modules\Court\Application\Services\SecureUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Flow 6, several parties (docs/euisis-integration.md, EUISIS ADR 0013 #13): what
 * the EUISIS employee portal shows and does about the parties of a case. An
 * employee answers an invitation to join a case (as a co-applicant, or as the
 * applicant of a case their lawyer filed), applies to intervene in another
 * employee's case, and picks a lawyer from the court's register when filing.
 * The employee is named in the body; answering or applying opens their court
 * account from their EUISIS record, as filing does.
 */
class EuisisCasePartiesController extends Controller
{
    /** The employee's invitations and intervention applications. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_uuid' => ['required', 'uuid']]);
        $iso = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;

        $rows = DB::table('court_case_applicants as p')
            ->join('court_cases as c', 'c.id', '=', 'p.case_id')
            ->leftJoin('court_case_types as t', 't.id', '=', 'c.case_type_id')
            ->leftJoin('court_applicants as by', 'by.id', '=', 'p.invited_by_applicant_id')
            ->where('p.euisis_employee_uuid', $data['employee_uuid'])
            ->where(fn ($q) => $q->where('p.status', 'invited')->orWhere('p.role', CaseApplicant::ROLE_INTERVENER))
            ->orderByDesc('p.created_at')
            ->limit(50)
            ->get(['p.id', 'p.role', 'p.status', 'p.decision_note', 'p.decided_at', 'p.created_at', 'p.source', 'c.id as case_id', 'c.case_number',
                'c.respondent_name', 'c.status as case_status', 't.name as case_type', 'by.first_name', 'by.middle_name', 'by.last_name']);

        $lawyers = DB::table('court_case_representatives as r')->join('court_applicants as a', 'a.id', '=', 'r.lawyer_applicant_id')
            ->whereIn('r.case_applicant_id', $rows->pluck('id')->all())
            ->get(['r.case_applicant_id', 'a.first_name', 'a.middle_name', 'a.last_name', 'a.lawyer_license_number'])
            ->groupBy('case_applicant_id');

        $card = fn (object $row): array => [
            'id' => (int) $row->id,
            'case_id' => (int) $row->case_id,
            'case_number' => (string) $row->case_number,
            'case_type' => $row->case_type,
            'respondent' => $row->respondent_name,
            'role' => (string) $row->role,
            'status' => (string) $row->status,
            'invited_by' => CaseParties::fullName($row) ?: null,
            'lawyer' => ($lawyer = $lawyers->get($row->id)?->first()) ? ['name' => CaseParties::fullName($lawyer), 'license_number' => $lawyer->lawyer_license_number] : null,
            'decision_note' => $row->decision_note,
            'decided_at' => $iso($row->decided_at),
            'created_at' => $iso($row->created_at),
        ];

        return response()->json(['data' => [
            'invitations' => $rows->where('status', 'invited')->map($card)->values()->all(),
            'interventions' => $rows->where('role', CaseApplicant::ROLE_INTERVENER)->where('status', '!=', 'invited')->map($card)->values()->all(),
        ]]);
    }

    /** The employee joins a case they were invited to, or declines. */
    /**
     * The employee joins or declines. Joining, they may name a lawyer of their own from the
     * register (licence number) with the power of attorney; the registry checks it.
     */
    public function respond(Request $request, int $party, EmployeeCourtAccess $access, CaseParties $parties): JsonResponse
    {
        $data = $request->validate([
            ...$this->identityRules(),
            'accept' => ['required', 'boolean'],
            'lawyer_license_number' => ['nullable', 'string', 'max:60'],
            'power_of_attorney' => ['nullable', 'required_with:lawyer_license_number', 'file', 'mimes:pdf', 'max:10240'],
        ], ['power_of_attorney.required_with' => __('court::case_parties.errors.power_of_attorney_required')]);
        $row = CaseApplicant::query()->where('id', $party)->where('euisis_employee_uuid', $data['employee']['uuid'])->first();
        if ($row === null) {
            return $this->problem($request, 404, 'not_found', 'No such invitation for this employee.');
        }
        $accept = filter_var($data['accept'], FILTER_VALIDATE_BOOLEAN);
        $lawyer = null;
        if ($accept && filled($data['lawyer_license_number'] ?? null)) {
            $lawyer = Applicant::query()->where('is_lawyer', true)->where('lawyer_license_number', trim((string) $data['lawyer_license_number']))->first();
            if ($lawyer === null || ! CaseParties::isVerifiedLawyer($lawyer)) {
                return $this->problem($request, 422, 'unknown_lawyer', __('court::case_parties.errors.lawyer_not_verified'), ['lawyer_license_number' => [__('court::case_parties.errors.lawyer_not_verified')]]);
            }
        }

        try {
            $applicant = $access->findOrOpen($data['employee']);
        } catch (EmployeeCourtAccessException $e) {
            return $this->problem($request, 409, $e->errorCode, $e->getMessage());
        }
        $path = $lawyer !== null
            ? app(SecureUploadService::class)->store($request->file('power_of_attorney'), 'powers-of-attorney', 'court_private', ['related_type' => 'court_case', 'related_id' => $row->case_id])
            : null;
        $parties->respond($row, $applicant, $accept, $lawyer, $path);

        return response()->json(['data' => ['ok' => true, 'case_id' => (int) $row->case_id, 'status' => $row->fresh()->status]]);
    }

    /** The employee applies to intervene in a case, with their grounds and a PDF; the judge decides. */
    public function intervene(Request $request, EmployeeCourtAccess $access, CaseParties $parties): JsonResponse
    {
        $data = $request->validate([
            ...$this->identityRules(),
            'case_number' => ['required', 'string', 'max:60'],
            'statement' => ['required', 'string', 'max:5000'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);
        $caseId = DB::table('court_cases')->where('case_number', trim((string) $data['case_number']))->value('id');
        if ($caseId === null) {
            return $this->problem($request, 422, 'unknown_case', __('court::case_parties.errors.unknown_case'), ['case_number' => [__('court::case_parties.errors.unknown_case')]]);
        }

        try {
            $applicant = $access->findOrOpen($data['employee']);
        } catch (EmployeeCourtAccessException $e) {
            return $this->problem($request, 409, $e->errorCode, $e->getMessage());
        }
        $party = $parties->requestIntervention((int) $caseId, $applicant, trim(strip_tags((string) $data['statement'])));
        if ($request->hasFile('document')) {
            $party->forceFill(['attachment_path' => app(SecureUploadService::class)->store($request->file('document'), 'interventions', 'court_private', ['related_type' => 'court_case', 'related_id' => $caseId])])->save();
        }

        return response()->json(['data' => ['ok' => true, 'id' => (int) $party->id, 'status' => $party->status]], 201);
    }

    /** The court's register of verified lawyers, for the filing form: name and licence number only. */
    public function lawyers(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $q = trim((string) ($data['q'] ?? ''));

        $lawyers = Applicant::query()->where('is_lawyer', true)->where('is_active', true)->whereNotNull('lawyer_verified_at')
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('lawyer_license_number', 'like', "%{$q}%")
                ->orWhere('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")))
            ->orderBy('first_name')->limit(50)
            ->get(['first_name', 'middle_name', 'last_name', 'lawyer_license_number']);

        return response()->json(['data' => $lawyers->map(fn (Applicant $lawyer): array => [
            'name' => CaseParties::fullName($lawyer),
            'license_number' => (string) $lawyer->lawyer_license_number,
        ])->values()->all()]);
    }

    /** @return array<string, list<string>> */
    private function identityRules(): array
    {
        return [
            'employee' => ['required', 'array'],
            'employee.uuid' => ['required', 'uuid'],
            'employee.national_id' => ['nullable', 'string', 'max:32'],
            'employee.email' => ['nullable', 'email', 'max:255'],
            'employee.first_name' => ['required', 'string', 'max:100'],
            'employee.middle_name' => ['nullable', 'string', 'max:100'],
            'employee.last_name' => ['required', 'string', 'max:100'],
            'employee.gender' => ['nullable', 'string', 'max:20'],
            'employee.phone' => ['nullable', 'string', 'max:30'],
            'employee.position' => ['nullable', 'string', 'max:150'],
            'employee.organization_name' => ['nullable', 'string', 'max:255'],
            'employee.address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @param  array<string, list<string>>  $errors */
    private function problem(Request $request, int $status, string $code, string $message, array $errors = []): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message, 'error_code' => $code, 'errors' => $errors ?: null, 'request_id' => $request->attributes->get('request_id'),
        ], fn ($value) => $value !== null), $status);
    }
}
