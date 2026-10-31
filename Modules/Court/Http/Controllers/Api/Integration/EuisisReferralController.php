<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Api\Integration;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\EuisisCaseUpdate;
use Modules\Court\Domain\Models\EuisisReferral;
use Modules\Court\Domain\Models\IntegrationClient;
use Modules\Court\Application\Services\Euisis\CaseUpdateRecorder;
use Modules\Court\Application\Services\Euisis\ReferralService;
use Modules\Court\Application\Support\EuisisParties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The machine API EUISIS calls (docs/euisis-integration.md, Flows 2 and 3):
 * case types to choose from, referrals that open a case in intake review,
 * and the list of changes to referred cases since a cursor.
 */
class EuisisReferralController extends Controller
{
    public function caseTypes(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('court_case_types')->orderBy('name')->get(['id', 'name', 'prefix'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name, 'prefix' => $t->prefix])->all(),
        ]);
    }

    public function store(Request $request, ReferralService $referrals): JsonResponse
    {
        $data = $request->validate([
            'referral_id' => ['required', 'uuid'],
            'grievance_reference' => ['nullable', 'string', 'max:80'],
            'tribunal_case_number' => ['nullable', 'string', 'max:80'],
            'case_type_id' => ['required', 'integer', 'exists:court_case_types,id'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string', 'max:10000'],
            'relief_requested' => ['nullable', 'string', 'max:5000'],
            'complainant' => ['required', 'array'],
            'complainant.name' => ['required', 'string', 'max:255'],
            // With EUISIS parties only, the complainant is an EUISIS employee and the respondent an EUISIS institution.
            'complainant.employee_uuid' => [EuisisParties::only() ? 'required' : 'nullable', 'uuid'],
            'complainant.phone' => ['nullable', 'string', 'max:40'],
            'complainant.email' => ['nullable', 'email', 'max:255'],
            'respondent' => ['required', 'array'],
            'respondent.name' => ['required', 'string', 'max:255'],
            'respondent.organization_uuid' => [EuisisParties::only() ? 'required' : 'nullable', 'uuid'],
            'court::respondent.address' => ['nullable', 'string', 'max:500'],
            'referring_officer' => ['nullable', 'string', 'max:255'],
            'documents' => ['nullable', 'array', 'max:10'],
            'court_documents.*' => ['file', 'mimes:pdf', 'max:10240'],
            'document_titles' => ['nullable', 'array', 'max:10'],
            'document_titles.*' => ['nullable', 'string', 'max:255'],
        ]);

        $titles = (array) $request->input('document_titles', []);
        $documents = [];
        foreach ((array) $request->file('documents', []) as $i => $file) {
            $documents[] = ['file' => $file, 'title' => (string) ($titles[$i] ?? $file->getClientOriginalName())];
        }

        /** @var IntegrationClient $client */
        $client = $request->attributes->get('integration_client');
        [$referral, $created] = $referrals->receive($client, $data, $documents);

        return response()->json(['data' => $this->present($referral)], $created ? 201 : 200);
    }

    public function show(string $referralId): JsonResponse
    {
        $referral = EuisisReferral::query()->with('courtCase')->where('referral_uuid', $referralId)->first();
        if ($referral === null) {
            return response()->json(['message' => 'No referral with this id.', 'error_code' => 'referral_not_found'], 404);
        }

        return response()->json(['data' => $this->present($referral)]);
    }

    public function updates(Request $request, CaseUpdateRecorder $recorder): JsonResponse
    {
        $data = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $since = (int) ($data['since'] ?? 0);
        $limit = (int) ($data['limit'] ?? 100);

        $recorder->capture();

        $rows = EuisisCaseUpdate::query()->with('referral:id,referral_uuid')
            ->where('id', '>', $since)->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map(fn (EuisisCaseUpdate $u): array => [
                'cursor' => $u->id,
                'referral_id' => $u->referral?->referral_uuid,
                'case_number' => $u->case_number,
                'review_status' => $u->review_status,
                'review_reason' => $u->review_reason,
                'status' => $u->status,
                'hearing_at' => $u->next_hearing_at?->format('Y-m-d H:i:s'),
                'decision_date' => $u->decision_date?->toDateString(),
                'decision_summary' => $u->decision_summary,
                'changed' => $u->changed,
                'occurred_at' => $u->created_at?->toIso8601String(),
            ])->values()->all(),
            'next_cursor' => $rows->isEmpty() ? $since : (int) $rows->last()->id,
            'has_more' => $hasMore,
        ]);
    }

    /** @return array<string, mixed> */
    private function present(EuisisReferral $referral): array
    {
        $case = $referral->courtCase;

        return [
            'referral_id' => $referral->referral_uuid,
            'case_id' => $referral->court_case_id,
            'case_number' => $case?->case_number,
            'code' => $case?->code,
            'review_status' => $case?->review_status,
            'status' => $case?->status,
            'received_at' => $referral->created_at?->toIso8601String(),
            'admin_url' => route('court.cases.show', $referral->court_case_id),
        ];
    }
}
