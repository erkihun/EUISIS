<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Lawyer;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Services\Euisis\CaseDocuments;
use Modules\Court\Application\Services\Euisis\CaseNumbers;
use Modules\Court\Application\Services\Euisis\EuisisClient;
use Modules\Court\Application\Services\Euisis\EuisisException;
use Modules\Court\Application\Services\SecureUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A lawyer files a case for a client (EUISIS ADR 0013 #13). The client is an
 * EUISIS employee, found by employee number; the respondent an EUISIS
 * institution. The lawyer represents the client from the start, on the power
 * of attorney they upload; the client confirms in the EUISIS employee portal,
 * and only then is the case theirs there. The registry reviews it as any filing.
 * A lawyer with several clients in the same matter files once: the first client
 * is the applicant, the others co-applicants, each invited to confirm, and the
 * lawyer represents every one of them on the same power of attorney.
 */
class LawyerCaseController extends Controller
{
    public function create(): Response
    {
        $this->lawyer();

        return Inertia::render('Court/Lawyer/FileCase', [
            'caseTypes' => CaseType::query()->orderBy('name')->get(['id', 'name'])->map(fn (CaseType $type): array => ['id' => (int) $type->id, 'name' => (string) $type->name])->all(),
            'organizations' => DB::table('court_euisis_organizations')->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
                ->orderBy('name_en')->get(['uuid', 'name_en', 'name_am'])
                ->map(fn (object $row): array => ['uuid' => (string) $row->uuid, 'name_en' => (string) $row->name_en, 'name_am' => $row->name_am])->all(),
        ]);
    }

    /** The EUISIS employee with this number: their name, to check before filing. */
    public function client(Request $request, EuisisClient $euisis): JsonResponse
    {
        $this->lawyer();
        $data = $request->validate(['employee_number' => ['required', 'string', 'max:60']]);

        try {
            $employee = $this->employee($euisis, $data['employee_number']);
        } catch (EuisisException) {
            return response()->json(['message' => __('court::lawyer.file.euisis_unavailable')], 503);
        }

        return $employee === null
            ? response()->json(['message' => __('court::lawyer.file.client_not_found')], 404)
            : response()->json(['data' => ['name' => $employee['name'], 'employee_number' => $employee['employee_number']]]);
    }

    public function store(Request $request, EuisisClient $euisis, CaseNumbers $numbers, CaseDocuments $documents, CaseParties $parties): RedirectResponse
    {
        $lawyer = $this->lawyer();
        $data = $request->validate([
            'client_employee_number' => ['required', 'string', 'max:60'],
            'co_client_employee_numbers' => ['nullable', 'array', 'max:20'],
            'co_client_employee_numbers.*' => ['nullable', 'string', 'max:60'],
            'case_type_id' => ['required', 'integer', 'exists:court_case_types,id'],
            'respondent_organization_uuid' => ['required', 'uuid', 'exists:court_euisis_organizations,uuid'],
            'respondent_address' => ['nullable', 'string', 'max:500'],
            'description' => ['required', 'string', 'max:20000'],
            'relief_requested' => ['nullable', 'string', 'max:5000'],
            'power_of_attorney' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'documents' => ['nullable', 'array', 'max:10'],
            'court_documents.*' => ['file', 'mimes:pdf', 'max:10240'],
        ]);

        try {
            $client = $this->employee($euisis, $data['client_employee_number']);
        } catch (EuisisException) {
            throw ValidationException::withMessages(['client_employee_number' => [__('court::lawyer.file.euisis_unavailable')]]);
        }
        if ($client === null) {
            throw ValidationException::withMessages(['client_employee_number' => [__('court::lawyer.file.client_not_found')]]);
        }
        // The other clients in the same matter: each found in EUISIS, none twice, none the applicant.
        $coClients = [];
        foreach ((array) ($data['co_client_employee_numbers'] ?? []) as $i => $number) {
            if (trim((string) $number) === '') {
                continue;
            }
            try {
                $other = $this->employee($euisis, (string) $number);
            } catch (EuisisException) {
                throw ValidationException::withMessages(["co_client_employee_numbers.{$i}" => [__('court::lawyer.file.euisis_unavailable')]]);
            }
            if ($other === null) {
                throw ValidationException::withMessages(["co_client_employee_numbers.{$i}" => [__('court::lawyer.file.client_not_found')]]);
            }
            if ($other['uuid'] === $client['uuid'] || isset($coClients[$other['uuid']])) {
                throw ValidationException::withMessages(["co_client_employee_numbers.{$i}" => [__('court::lawyer.file.client_twice')]]);
            }
            $coClients[$other['uuid']] = $other;
        }
        $respondent = DB::table('court_euisis_organizations')->where('uuid', $data['respondent_organization_uuid'])->first(['uuid', 'name_en']);

        $caseId = DB::transaction(function () use ($data, $client, $coClients, $respondent, $lawyer, $numbers, $documents, $parties, $request): int {
            $caseId = DB::table('court_cases')->insertGetId([
                // The case becomes the client's when they confirm in the EUISIS employee portal.
                'applicant_id' => null,
                'case_number' => $numbers->next((int) $data['case_type_id']),
                'code' => $numbers->code(),
                'title' => $client['name'],
                'respondent_name' => (string) $respondent->name_en,
                'respondent_address' => filled($data['respondent_address'] ?? null) ? trim(strip_tags((string) $data['respondent_address'])) : null,
                'respondent_organization_uuid' => $respondent->uuid,
                'description' => \Modules\Court\Application\Support\Purifier::clean((string) $data['description'], 'cases'),
                'relief_requested' => filled($data['relief_requested'] ?? null) ? \Modules\Court\Application\Support\Purifier::clean((string) $data['relief_requested'], 'cases') : null,
                'case_type_id' => (int) $data['case_type_id'],
                'filing_date' => now()->toDateString(),
                'status' => 'pending',
                'review_status' => 'awaiting_review',
                'source' => 'lawyer',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('court_case_status_logs')->insert(['case_id' => $caseId, 'from_status' => null, 'to_status' => 'pending', 'changed_by_user_id' => null, 'created_at' => now(), 'updated_at' => now()]);

            $lead = $parties->addLead($caseId, null, $client['uuid'], $client['name'], 'lawyer', 'invited');
            $path = app(SecureUploadService::class)->store($request->file('power_of_attorney'), 'powers-of-attorney', 'court_private', ['related_type' => 'court_case', 'related_id' => $caseId]);
            $representative = $parties->addRepresentative($lead, $lawyer, $path, confirmedByParty: false);
            // Filed on the power of attorney: the registry checks it with the case, and may refuse it later.
            $representative->forceFill(['status' => 'accepted'])->save();
            foreach ($coClients as $other) {
                $party = $parties->invite($caseId, $other['uuid'], $other['name'], null, 'lawyer');
                $parties->addRepresentative($party, $lawyer, $path, confirmedByParty: false)->forceFill(['status' => 'accepted'])->save();
            }

            foreach ((array) $request->file('documents', []) as $file) {
                $documents->store($caseId, $file, $file->getClientOriginalName(), __('court::lawyer.file.document_source'));
            }

            return $caseId;
        });

        return redirect()->route('court.applicant.cases.show', $caseId)->with('success', __('court::lawyer.file.filed'));
    }

    private function lawyer(): Applicant
    {
        $lawyer = auth('applicant')->user();
        abort_unless($lawyer instanceof Applicant && CaseParties::isVerifiedLawyer($lawyer), 403);

        return $lawyer;
    }

    /**
     * The active EUISIS employee with this number, or null.
     *
     * @return array{uuid: string, name: string, employee_number: string}|null
     */
    private function employee(EuisisClient $euisis, string $number): ?array
    {
        $number = trim($number);
        foreach ($euisis->employeesByNumber($number) as $row) {
            if (($row['employee_number'] ?? null) === $number && in_array($row['employment_status'] ?? 'active', ['active', null], true)) {
                $name = trim((string) (($row['full_name_en'] ?? null) ?: ($row['full_name'] ?? '')));

                return ['uuid' => (string) $row['id'], 'name' => $name !== '' ? $name : $number, 'employee_number' => $number];
            }
        }

        return null;
    }
}
