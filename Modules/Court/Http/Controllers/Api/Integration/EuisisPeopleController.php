<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Api\Integration;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\IntegrationClient;
use Modules\Court\Application\Services\Euisis\EmployeeCourtAccess;
use Modules\Court\Application\Services\Euisis\EmployeeCourtAccessException;
use Modules\Court\Application\Services\Euisis\EmployeeFilingService;
use Modules\Court\Application\Support\EuisisParties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Flow 4 (docs/euisis-integration.md): the EUISIS employee portal's court
 * section. Both calls are POSTs so a national ID never sits in a URL or an
 * access log.
 */
class EuisisPeopleController extends Controller
{
    /** The employee's court summary, or `linked: false` when they have no court account yet. */
    public function summary(Request $request, EmployeeCourtAccess $access): JsonResponse
    {
        $data = $request->validate([...$this->identityRules(), 'locale' => ['nullable', 'string', 'in:en,am']]);
        $employee = $data['employee'];
        // Notices are worded in the language the employee reads EUISIS in, not court-ms's default.
        if (isset($data['locale'])) {
            app()->setLocale($data['locale']);
        }

        try {
            $applicant = $access->find($employee);
        } catch (EmployeeCourtAccessException $e) {
            return $this->refuse($request, 409, $e);
        }

        return response()->json(['data' => $applicant === null
            ? ['linked' => false]
            : ['linked' => true, ...$access->summary($applicant)]]);
    }

    /** A one-time URL that signs the employee into the applicant portal (opening their court account on first use). */
    public function signInTicket(Request $request, EmployeeCourtAccess $access): JsonResponse
    {
        // Flow 6: with the portal in EUISIS there is nothing here to sign in to.
        if (EuisisParties::portalClosed()) {
            return response()->json([
                'message' => 'Court cases are managed in the EUISIS employee portal.',
                'error_code' => 'portal_in_euisis',
                'request_id' => $request->attributes->get('request_id'),
            ], 410);
        }

        $data = $request->validate([
            ...$this->identityRules(),
            'employee.first_name' => ['required', 'string', 'max:100'],
            'employee.middle_name' => ['nullable', 'string', 'max:100'],
            'employee.last_name' => ['required', 'string', 'max:100'],
            'employee.gender' => ['nullable', 'string', 'max:20'],
            'employee.phone' => ['nullable', 'string', 'max:30'],
            'employee.position' => ['nullable', 'string', 'max:150'],
            'employee.organization_name' => ['nullable', 'string', 'max:255'],
            'employee.address' => ['nullable', 'string', 'max:255'],
            'target' => ['required', 'string', 'max:40', 'regex:/^(dashboard|cases|new_case|notifications|profile|case:\d+)$/'],
        ]);

        try {
            $applicant = $access->findOrOpen($data['employee']);
            $ticket = $access->issueTicket($applicant, $data['target'], $this->client($request));
        } catch (EmployeeCourtAccessException $e) {
            return $this->refuse($request, $e->errorCode === 'unknown_target' ? 404 : 409, $e);
        }

        return response()->json(['data' => $ticket], 201);
    }

    /**
     * Flow 5: a case the employee files from the EUISIS employee portal, against an EUISIS
     * institution, with the grievances it concerns (`grievances`, JSON: each with its
     * responses and decisions) and PDF documents. `201` the first time, `200` with the same
     * case after (idempotent on `filing_id`).
     */
    public function fileCase(Request $request, EmployeeFilingService $filings): JsonResponse
    {
        $data = $request->validate([
            ...$this->identityRules(),
            'employee.first_name' => ['required', 'string', 'max:100'],
            'employee.middle_name' => ['nullable', 'string', 'max:100'],
            'employee.last_name' => ['required', 'string', 'max:100'],
            'employee.gender' => ['nullable', 'string', 'max:20'],
            'employee.phone' => ['nullable', 'string', 'max:30'],
            'employee.position' => ['nullable', 'string', 'max:150'],
            'employee.organization_name' => ['nullable', 'string', 'max:255'],
            'employee.address' => ['nullable', 'string', 'max:255'],
            'filing_id' => ['required', 'uuid'],
            'case_type_id' => ['required', 'integer', 'exists:court_case_types,id'],
            'description' => ['required', 'string', 'max:20000'],
            'relief_requested' => ['nullable', 'string', 'max:5000'],
            'respondent' => ['required', 'array'],
            // Only an EUISIS institution may be sued (checked against the directory court-ms mirrors).
            'respondent.organization_uuid' => ['required', 'uuid', function (string $attribute, mixed $value, \Closure $fail): void {
                $mirrored = DB::table('court_euisis_organizations')->exists();
                if ($mirrored && ! DB::table('court_euisis_organizations')->where('uuid', $value)->exists()) {
                    $fail('The respondent is not an institution registered in EUISIS.');
                }
            }],
            'respondent.name' => ['required', 'string', 'max:255'],
            'court::respondent.address' => ['nullable', 'string', 'max:500'],
            'grievances' => ['nullable', 'json'],
            // The employee's EUISIS discipline cases the case concerns, in the grievances' form (EUISIS ADR 0013 #14).
            'disciplines' => ['nullable', 'json'],
            'documents' => ['nullable', 'array', 'max:20'],
            'court_documents.*' => ['file', 'mimes:pdf', 'max:10240'],
            'document_titles' => ['nullable', 'array', 'max:20'],
            'document_titles.*' => ['nullable', 'string', 'max:255'],
            'document_sources' => ['nullable', 'array', 'max:20'],
            'document_sources.*' => ['nullable', 'string', 'max:120'],
            // Other employees filing with the applicant: each confirms in the EUISIS employee portal.
            'co_applicants' => ['nullable', 'array', 'max:20'],
            'co_applicants.*.uuid' => ['required', 'uuid', 'distinct', 'different:employee.uuid'],
            'co_applicants.*.name' => ['required', 'string', 'max:255'],
            // A lawyer from the court's register of verified lawyers, with the power of attorney.
            'lawyer_license_number' => ['nullable', 'string', 'max:80', function (string $attribute, mixed $value, \Closure $fail): void {
                if (Applicant::query()->where('is_lawyer', true)->where('is_active', true)->whereNotNull('lawyer_verified_at')
                    ->where('lawyer_license_number', trim((string) $value))->doesntExist()) {
                    $fail(__('court::case_parties.errors.lawyer_not_verified'));
                }
            }],
            'power_of_attorney' => ['nullable', 'required_with:lawyer_license_number', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $grievances = $this->grievances((string) ($data['grievances'] ?? '[]'));
        if ($grievances === null) {
            return response()->json(['message' => 'The grievances are not in the expected form.', 'errors' => ['grievances' => ['invalid']], 'request_id' => $request->attributes->get('request_id')], 422);
        }
        $data['grievances'] = $grievances;
        $disciplines = $this->grievances((string) ($data['disciplines'] ?? '[]'));
        if ($disciplines === null) {
            return response()->json(['message' => 'The discipline cases are not in the expected form.', 'errors' => ['disciplines' => ['invalid']], 'request_id' => $request->attributes->get('request_id')], 422);
        }
        $data['disciplines'] = $disciplines;

        $titles = (array) $request->input('document_titles', []);
        $sources = (array) $request->input('document_sources', []);
        $documents = [];
        foreach ((array) $request->file('documents', []) as $i => $file) {
            $documents[] = [
                'file' => $file,
                'title' => trim((string) ($titles[$i] ?? '')) ?: $file->getClientOriginalName(),
                'source' => trim((string) ($sources[$i] ?? '')) ?: 'EUISIS employee portal',
            ];
        }

        try {
            [$case, $created] = $filings->file($data, $documents, $request->file('power_of_attorney'));
        } catch (EmployeeCourtAccessException $e) {
            return $this->refuse($request, 409, $e);
        }

        return response()->json(['data' => [
            'case_id' => (int) $case->id,
            'case_number' => (string) $case->case_number,
            'review_status' => (string) $case->review_status,
            'status' => (string) $case->status,
        ]], $created ? 201 : 200);
    }

    /**
     * The grievances sent with a filing: at most 20, each with a UUID and a subject.
     *
     * @return list<array<string, mixed>>|null null when not in the expected form
     */
    private function grievances(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || count($decoded) > 20) {
            return null;
        }
        foreach ($decoded as $grievance) {
            if (! is_array($grievance) || ! is_string($grievance['uuid'] ?? null) || ! Str::isUuid($grievance['uuid'])
                || ! is_string($grievance['subject'] ?? null) || trim($grievance['subject']) === '') {
                return null;
            }
        }

        return array_values($decoded);
    }

    /** @return array<string, list<string>> */
    private function identityRules(): array
    {
        return [
            'employee' => ['required', 'array'],
            'employee.uuid' => ['required', 'uuid'],
            'employee.national_id' => ['nullable', 'string', 'max:32'],
            'employee.email' => ['nullable', 'email', 'max:255'],
        ];
    }

    private function client(Request $request): ?IntegrationClient
    {
        $client = $request->attributes->get('integration_client');

        return $client instanceof IntegrationClient ? $client : null;
    }

    private function refuse(Request $request, int $status, EmployeeCourtAccessException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error_code' => $e->errorCode,
            'request_id' => $request->attributes->get('request_id'),
        ], $status);
    }
}
