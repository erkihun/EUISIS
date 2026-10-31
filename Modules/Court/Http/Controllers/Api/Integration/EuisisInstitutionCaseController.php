<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Api\Integration;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Application\Services\AppealRecordBundle;
use Modules\Court\Application\Services\AppealRecords;
use Modules\Court\Application\Services\CaseParties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flow 7 (docs/euisis-integration.md, EUISIS ADR 0013 #14): the respondent side.
 * When a case is filed against an EUISIS institution, however it was filed (from
 * the EUISIS employee portal, by a lawyer, or by the registry), EUISIS reads it
 * here and gives it to the officer who follows that institution's court cases.
 * The institution then follows the case and, once it is closed, may ask for the
 * record to take to the appellate court. Every call names the institution
 * (`organization_uuid`): a case against another institution is not found.
 */
class EuisisInstitutionCaseController extends Controller
{
    /** Cases against EUISIS institutions filed after a cursor (the case id), oldest first. */
    public function newCases(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'min:1', 'max:200']]);
        $since = (int) ($data['since'] ?? 0);
        $limit = (int) ($data['limit'] ?? 100);

        $rows = DB::table('court_cases as c')->leftJoin('court_case_types as t', 't.id', '=', 'c.case_type_id')
            ->whereNotNull('c.respondent_organization_uuid')->where('c.id', '>', $since)
            ->orderBy('c.id')->limit($limit + 1)
            ->get(['c.id', 'c.case_number', 'c.title', 'c.respondent_organization_uuid', 'c.respondent_name', 'c.filing_date', 'c.status', 'c.review_status', 'c.source', 't.name as case_type']);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $parties = DB::table('court_case_applicants')->whereIn('case_id', $rows->pluck('id')->all())->whereIn('status', ['active', 'admitted', 'invited'])
            ->orderBy('id')->get(['case_id', 'name', 'role'])->groupBy('case_id');

        return response()->json([
            'data' => $rows->map(fn (object $row): array => [
                'cursor' => (int) $row->id,
                'case_id' => (int) $row->id,
                'case_number' => (string) $row->case_number,
                'organization_uuid' => (string) $row->respondent_organization_uuid,
                'respondent' => $row->respondent_name,
                'applicants' => ($parties->get($row->id) ?? collect())->pluck('name')->values()->all() ?: array_values(array_filter([(string) $row->title])),
                'case_type' => $row->case_type,
                'filed_at' => $row->filing_date ? Carbon::parse($row->filing_date)->toDateString() : null,
                'status' => (string) $row->status,
                'review_status' => (string) $row->review_status,
                'source' => $row->source,
            ])->values()->all(),
            'next_cursor' => (int) ($rows->last()->id ?? $since),
            'has_more' => $hasMore,
        ]);
    }

    /** One case as the respondent institution follows it: its course, hearings, decision and appeals. */
    public function show(Request $request, int $case, AppealRecords $appeals): JsonResponse
    {
        $row = $this->case($request, $case);
        $iso = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;
        $decision = $appeals->appealableDecision($case)
            ?? Decision::query()->where('court_case_id', $case)->orderByDesc('decision_date')->get()->first(fn (Decision $decision): bool => $decision->isDownloadableByParties());

        return response()->json(['data' => [
            'case' => [
                'id' => (int) $row->id,
                'case_number' => (string) $row->case_number,
                'case_type' => DB::table('court_case_types')->where('id', $row->case_type_id)->value('name'),
                'status' => (string) $row->status,
                'review_status' => (string) $row->review_status,
                'filed_at' => $row->filing_date ? Carbon::parse($row->filing_date)->toDateString() : null,
                'appealed_at' => $iso($row->appealed_at),
                'appeal_outcome' => $row->appeal_outcome,
                'merged_into' => $row->merged_into_case_id ? DB::table('court_cases')->where('id', $row->merged_into_case_id)->value('case_number') : null,
            ],
            'applicants' => DB::table('court_case_applicants')->where('case_id', $case)->whereIn('status', ['active', 'admitted'])->orderBy('id')->get(['name', 'role'])
                ->map(fn (object $party): array => ['name' => (string) $party->name, 'role' => (string) $party->role])->values()->all(),
            'hearings' => DB::table('court_case_hearings')->where('case_id', $case)->orderBy('hearing_at')->get(['hearing_at', 'type', 'location'])
                ->map(fn (object $hearing): array => ['at' => $iso($hearing->hearing_at), 'type' => $hearing->type, 'location' => $hearing->location])->values()->all(),
            'decision' => $decision ? ['title' => $decision->name ?: $decision->case_number, 'decided_at' => $decision->decision_date?->toDateString()] : null,
            'appeal' => [
                'appealable' => $appeals->appealableDecision($case) !== null,
                'requests' => $appeals->forSide($case, 'respondent'),
                'released' => $appeals->released($case, 'respondent'),
            ],
        ]]);
    }

    /** The institution asks for the record to take to the appellate court; the registry releases it. */
    public function appeal(Request $request, int $case, AppealRecords $appeals): JsonResponse
    {
        $row = $this->case($request, $case);
        $data = $request->validate([
            'appellant_name' => ['nullable', 'string', 'max:255'],
            'grounds' => ['required', 'string', 'max:10000'],
        ]);
        $appeal = $appeals->request($case, 'respondent', trim((string) ($data['appellant_name'] ?? '')) ?: (string) $row->respondent_name,
            trim(strip_tags($data['grounds'])), 'euisis_institution', (string) $row->respondent_organization_uuid);

        return response()->json(['data' => ['ok' => true, 'appeal_number' => (string) $appeal->appeal_number, 'status' => (string) $appeal->status]], 201);
    }

    /** The appeal record, once the registry released it to the institution. */
    public function appealRecord(Request $request, int $case, AppealRecords $appeals, AppealRecordBundle $bundle): Response
    {
        $this->case($request, $case);
        if (! $appeals->released($case, 'respondent')) {
            return response()->json(['message' => __('court::appeal_records.errors.not_released'), 'error_code' => 'not_released'], 403);
        }
        $courtCase = CourtCase::query()->findOrFail($case);

        return response($bundle->build($courtCase), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$bundle->filename($courtCase).'"',
        ]);
    }

    /** The case, when it is against the institution named in the call. */
    private function case(Request $request, int $case): object
    {
        $data = $request->validate(['organization_uuid' => ['required', 'uuid']]);
        $row = DB::table('court_cases')->where('id', $case)->where('respondent_organization_uuid', $data['organization_uuid'])->first();
        abort_if($row === null, 404);
        app(CaseParties::class)->ensureLead($case);

        return $row;
    }
}
