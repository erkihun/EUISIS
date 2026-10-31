<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\AppealRecordBundle;
use Modules\Court\Application\Services\AppealRecords;
use Modules\Court\Application\Services\CaseParties;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Court\Application\Support\Purifier;

class RecordController extends Controller
{
    /** The case statuses the list filters by, in the Blade page's order. */
    private const STATUSES = ['pending', 'active', 'adjourned', 'dismissed', 'closed'];

    /**
     * The case records register (React, Court/Records/Index).
     */
    public function index(Request $request): Response
    {
        $q = trim($request->string('q')->toString());
        $status = $request->string('status')->toString();
        $caseTypeId = $request->integer('case_type_id');
        $assigneeId = $request->integer('assignee_id');
        $from = $request->date('from');
        $to = $request->date('to');
        // appealed: at the appellate court; requested: a party asked and the registry has yet to answer; none: never appealed.
        $appeal = in_array($request->string('appeal')->toString(), ['appealed', 'requested', 'none'], true) ? $request->string('appeal')->toString() : '';

        $isReviewer = false;
        if (function_exists('userHasPermission')) {
            $isReviewer = (bool) \Modules\Court\Application\Support\CourtPermissions::check('cases.review');
        } elseif (Auth::user()) {
            $isReviewer = (bool) Auth::user()->can('cases.review');
        }

        $teamNameSubquery = DB::table('court_teams as t')
            ->join('court_team_user as tu', 'tu.team_id', '=', 't.id')
            ->whereColumn('tu.user_id', 'ass.id')
            ->orderBy('t.name')
            ->limit(1)
            ->select('t.name');

        $builder = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')
            ->leftJoin('court_applicants as ap', 'ap.id', '=', 'c.applicant_id')
            ->leftJoin('court_staff as ass', 'ass.id', '=', 'c.assigned_user_id')
            ->leftJoin('court_staff as reviewer', 'reviewer.id', '=', 'c.reviewed_by_user_id')
            ->select(
                'c.*',
                'ct.name as case_type',
                DB::raw("TRIM(CONCAT(COALESCE(ap.first_name,''),' ',COALESCE(ap.middle_name,''),' ',COALESCE(ap.last_name,''))) as applicant_name"),
                'ass.name as assignee_name',
                'reviewer.name as reviewer_name',
                DB::raw('(SELECT COUNT(*) FROM court_decisions d WHERE d.court_case_id = c.id) as decisions_count'),
                DB::raw("(SELECT COUNT(*) FROM court_appeals apr WHERE apr.court_case_id = c.id AND apr.status IN ('submitted', 'under_review')) as appeal_requests")
            )
            ->selectSub($teamNameSubquery, 'team_name');

        if ($appeal === 'appealed') {
            $builder->whereNotNull('c.appealed_at');
        } elseif ($appeal === 'requested') {
            $builder->whereExists(fn ($q) => $q->selectRaw('1')->from('court_appeals as apf')->whereColumn('apf.court_case_id', 'c.id')->whereIn('apf.status', ['submitted', 'under_review']));
        } elseif ($appeal === 'none') {
            $builder->whereNull('c.appealed_at')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('court_appeals as apn')->whereColumn('apn.court_case_id', 'c.id'));
        }

        if ($q !== '') {
            $builder->where(function ($inner) use ($q) {
                $inner->where('c.case_number', 'like', "%{$q}%")
                    ->orWhere('c.title', 'like', "%{$q}%")
                    ->orWhere('ct.name', 'like', "%{$q}%");
            });
        }

        if ($status !== '') {
            $builder->where('c.status', $status);
        }
        if ($caseTypeId) {
            $builder->where('c.case_type_id', $caseTypeId);
        }
        if ($assigneeId) {
            $builder->where('c.assigned_user_id', $assigneeId);
        }
        if ($from) {
            $builder->whereDate('c.filing_date', '>=', $from->format('Y-m-d'));
        }
        if ($to) {
            $builder->whereDate('c.filing_date', '<=', $to->format('Y-m-d'));
        }

        $memberScopeIds = $this->teamLeaderAssignmentIds(Auth::user());
        if (!empty($memberScopeIds)) {
            $leaderTeamId = Team::where('team_leader_id', Auth::id())->value('id');
            $builder->where(function ($inner) use ($memberScopeIds, $leaderTeamId) {
                $inner->whereIn('c.assigned_user_id', $memberScopeIds);
                if ($leaderTeamId) {
                    $inner->orWhere('c.assigned_team_id', $leaderTeamId);
                }
            });
        } else {
            $userId = Auth::id();
            $isTeamMember = $userId && DB::table('court_team_user')->where('user_id', $userId)->exists();
            $isLeader = Auth::user()?->hasPermission('cases.assign.member') ?? false;
            $canAssignTeams = Auth::user()?->hasPermission('cases.assign.team') ?? false;

            if ($isTeamMember && !$isLeader && !$canAssignTeams) {
                $builder->where('c.assigned_member_user_id', $userId);
            }
        }

        if (!$isReviewer) {
            $builder->where('c.review_status', 'accepted');
        }

        $cases = $builder
            ->orderByRaw('COALESCE(c.created_at, c.filing_date) DESC')
            ->paginate(10)
            ->withQueryString()
            ->through(function (object $row) use ($isReviewer): array {
                $reviewStatus = $row->review_status ?? 'accepted';

                return [
                    'id' => (int) $row->id,
                    'case_number' => (string) $row->case_number,
                    'title' => $row->title,
                    'applicant_name' => $row->applicant_name !== null && $row->applicant_name !== '' ? $row->applicant_name : null,
                    'case_type' => $row->case_type,
                    'team_name' => $row->team_name,
                    'assignee_name' => $row->assignee_name,
                    'status' => (string) $row->status,
                    'filing_date' => $row->filing_date ? Carbon::parse($row->filing_date)->toDateString() : null,
                    // A non-reviewer always sees "accepted", as on the Blade page.
                    'review_status' => $isReviewer ? (string) $reviewStatus : 'accepted',
                    'reviewer_name' => $row->reviewer_name,
                    'decisions_count' => (int) ($row->decisions_count ?? 0),
                    // The appeal record exists once the case has a decision or is closed/dismissed.
                    'has_appeal_record' => (int) ($row->decisions_count ?? 0) > 0 || in_array($row->status ?? '', ['closed', 'dismissed'], true),
                    // At the appellate court since (EUISIS ADR 0013 #14), its outcome, and requests waiting for the registry.
                    'appealed_at' => $row->appealed_at ? Carbon::parse($row->appealed_at)->toIso8601String() : null,
                    'appeal_outcome' => $row->appeal_outcome,
                    'appeal_requests' => (int) ($row->appeal_requests ?? 0),
                ];
            });

        $types = DB::table('court_case_types')->orderBy('name')->get(['id', 'name'])
            ->map(fn (object $type): array => ['id' => (int) $type->id, 'name' => (string) $type->name])->all();
        $users = User::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])->all();

        return Inertia::render('Court/Records/Index', [
            'cases' => $cases,
            'filters' => [
                'q' => $q,
                'status' => $status,
                'case_type_id' => $caseTypeId ? (string) $caseTypeId : '',
                'assignee_id' => $assigneeId ? (string) $assigneeId : '',
                'from' => $from ? $from->format('Y-m-d') : '',
                'to' => $to ? $to->format('Y-m-d') : '',
                'appeal' => $appeal,
            ],
            'statuses' => self::STATUSES,
            'types' => $types,
            'users' => $users,
            'isReviewer' => $isReviewer,
        ]);
    }

    /**
     * One case's consolidated record, section by section (React, Court/Records/Show).
     */
    public function show(CourtCase $case, AppealRecords $appeals): Response
    {
        $this->authorizeRecordAccess($case);

        return Inertia::render('Court/Records/Show', [
            ...$this->recordPageProps($case, $this->recordData($case, withEvidenceEmbed: false)),
            ...$this->proceedingProps($case),
            'appeal' => $this->appealProps($case, $appeals),
        ]);
    }

    public function pdf(CourtCase $case)
    {
        $this->authorizeRecordAccess($case);
        $data = $this->recordData($case);
        $safeCase = Str::of($case->case_number ?? $case->id)->replace(['/', '\\'], '-')->slug('-');
        $data['pdfFilename'] = 'case-record-' . ($safeCase ?: $case->id) . '.pdf';

        return view('court::admin.recordes.record-pdf', $data);
    }

    /**
     * The appeal record (Modules\Court\Application\Services\AppealRecordBundle): one PDF of the whole
     * proceeding, from the EUISIS grievance or discipline case to the decision
     * and the appeal, with the documents as annexes. Staff may read it at any
     * time; a party gets it once the registry releases it.
     */
    public function appealPdf(CourtCase $case, AppealRecordBundle $bundle)
    {
        $this->authorizeRecordAccess($case);

        return response($bundle->build($case), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$bundle->filename($case).'"',
        ]);
    }

    /**
     * What the record page adds to the case file (EUISIS ADR 0013 #13 and #14): the
     * EUISIS proceeding the case started from, the parties on the applicant's side
     * and the replies the court accepted.
     *
     * @return array<string, mixed>
     */
    private function proceedingProps(CourtCase $case): array
    {
        $iso = fn ($value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;
        app(CaseParties::class)->ensureLead((int) $case->id);
        $lawyers = DB::table('court_case_representatives as r')->join('court_applicants as a', 'a.id', '=', 'r.lawyer_applicant_id')
            ->where('r.case_id', $case->id)->where('r.status', 'accepted')
            ->get(['r.case_applicant_id', 'a.first_name', 'a.middle_name', 'a.last_name', 'a.lawyer_license_number'])->groupBy('case_applicant_id');

        return [
            'origins' => DB::table('court_case_euisis_grievances')->where('court_case_id', $case->id)->orderBy('submitted_at')->get()
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'kind' => (string) ($row->kind ?? 'grievance'),
                    'reference' => $row->reference,
                    'subject' => (string) $row->subject,
                    'category' => $row->category,
                    'status' => $row->status,
                    'description' => $row->description,
                    'submitted_at' => $iso($row->submitted_at),
                    'closed_at' => $iso($row->closed_at),
                    'responses' => array_values((array) json_decode((string) $row->responses, true)),
                    'decisions' => array_values((array) json_decode((string) $row->decisions, true)),
                ])->values()->all(),
            'parties' => DB::table('court_case_applicants')->where('case_id', $case->id)->whereIn('status', ['active', 'admitted', 'invited'])
                ->orderByRaw("CASE role WHEN 'lead' THEN 0 WHEN 'co_applicant' THEN 1 ELSE 2 END")->orderBy('id')->get()
                ->map(fn (object $party): array => [
                    'id' => (int) $party->id,
                    'name' => (string) $party->name,
                    'role' => (string) $party->role,
                    'status' => (string) $party->status,
                    'lawyers' => ($lawyers->get($party->id) ?? collect())->map(fn (object $lawyer): array => ['name' => CaseParties::fullName($lawyer), 'license_number' => $lawyer->lawyer_license_number])->values()->all(),
                ])->values()->all(),
            'replies' => DB::table('court_applicant_response_replies')->where('case_id', $case->id)->where('review_status', 'accepted')->orderBy('created_at')->get()
                ->map(fn (object $reply): array => [
                    'id' => (int) $reply->id,
                    'response_id' => (int) $reply->respondent_response_id,
                    'description' => $reply->description ? \Modules\Court\Application\Support\Purifier::clean((string) $reply->description, 'default') : null,
                    'created_at' => $iso($reply->created_at),
                ])->values()->all(),
        ];
    }

    /**
     * The appeal panel (EUISIS ADR 0013 #14): whether a party may appeal, every
     * request on the case, and what this person may do about them.
     *
     * @return array<string, mixed>
     */
    private function appealProps(CourtCase $case, AppealRecords $appeals): array
    {
        $iso = fn ($value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;
        $user = Auth::user();
        $can = fn (string $permission): bool => (bool) ($user?->hasPermission($permission) ?? false);

        return [
            'appealable' => $appeals->appealableDecision((int) $case->id) !== null,
            'appealedAt' => $iso($case->appealed_at),
            'outcome' => $case->appeal_outcome,
            'rows' => DB::table('court_appeals as a')->leftJoin('court_staff as r', 'r.id', '=', 'a.released_by_user_id')->leftJoin('court_staff as d', 'd.id', '=', 'a.decided_by_user_id')
                ->where('a.court_case_id', $case->id)->orderByDesc('a.id')
                ->get(['a.*', 'r.name as released_by', 'd.name as decided_by'])
                ->map(fn (object $appeal): array => [
                    'id' => (int) $appeal->id,
                    'appeal_number' => (string) $appeal->appeal_number,
                    'status' => (string) $appeal->status,
                    'side' => $appeal->appellant_type,
                    'appellant' => $appeal->appellant_name,
                    'via' => $appeal->requested_via,
                    'grounds' => $appeal->grounds,
                    'note' => $appeal->decision_notes,
                    'requested_at' => $iso($appeal->submitted_at),
                    'released_at' => $iso($appeal->released_at),
                    'released_by' => $appeal->released_by,
                    'decided_at' => $iso($appeal->decided_at),
                    'decided_by' => $appeal->decided_by,
                    'outcome' => $appeal->outcome,
                    'outcome_date' => $appeal->outcome_date,
                    'appellate_court' => $appeal->appellate_court,
                    'appellate_case_number' => $appeal->appellate_case_number,
                    'outcome_notes' => $appeal->outcome_notes,
                    'outcome_document_url' => $appeal->outcome_document_path ? route('court.appeals.outcome-document', $appeal->id) : null,
                ])->values()->all(),
            'can' => [
                'request' => $can('appeals.create'),
                'release' => $can('appeals.decide'),
                'outcome' => $can('appeals.decide'),
            ],
        ];
    }

    /**
     * The record page's props: the Blade page's rows as plain arrays with ISO
     * 8601 dates, formatted text cleaned once more on the way out, and the
     * derived values the Blade page computed in its @php block.
     */
    private function recordPageProps(CourtCase $case, array $data): array
    {
        $iso = fn ($value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;
        $date = fn ($value): ?string => $value ? Carbon::parse($value)->toDateString() : null;
        $clean = fn (?string $html): ?string => $html !== null && $html !== '' ? \Modules\Court\Application\Support\Purifier::clean($html, 'default') : null;

        // The Blade page listed the case's submitted documents, or, when it had none, the case files in their place.
        $submittedDocuments = collect($case->submitted_documents ?? []);
        $otherDocuments = collect($data['files'] ?? []);
        if ($submittedDocuments->isEmpty() && $otherDocuments->isNotEmpty()) {
            $submittedDocuments = $otherDocuments;
            $otherDocuments = collect();
        }
        $document = fn ($doc): array => [
            'id' => isset($doc->id) ? (int) $doc->id : null,
            'label' => $doc->label ?? $doc->title ?? null,
            'path' => $doc->path ?? $doc->file_path ?? null,
            'created_at' => $iso($doc->created_at ?? null),
        ];

        $decision = $data['decision'];
        $applicant = $case->applicant;

        return [
            'case' => [
                'id' => (int) $case->id,
                'case_number' => $case->case_number,
                'title' => $case->title,
                'status' => $case->status,
                'case_type' => $case->caseType?->name ?? ($case->case_type ?? null),
                'filing_date' => $date($case->filing_date),
                'first_hearing_date' => $date($case->first_hearing_date),
                'applicant_name' => $applicant?->full_name ?: ($case->applicant_name ?? null),
                'applicant_email' => $applicant?->email,
                'applicant_phone' => $applicant?->phone,
                'respondent_name' => $case->respondent_name,
                'respondent_address' => $case->respondent_address,
                'description' => $clean($case->description),
                'relief_requested' => $clean($case->relief_requested),
            ],
            'generatedAt' => now()->toIso8601String(),
            'benchNotes' => collect($data['benchNotes'])->map(fn ($note): array => [
                'id' => (int) $note->id,
                'title' => $note->title ?? null,
                'note' => $clean($note->note ?? $note->body ?? null),
                'author' => $note->author ?? null,
                'created_at' => $iso($note->created_at ?? null),
            ])->values()->all(),
            'submittedDocuments' => $submittedDocuments->map($document)->values()->all(),
            'otherDocuments' => $otherDocuments->map($document)->values()->all(),
            'witnesses' => collect($data['witnesses'])->map(fn ($witness): array => [
                'id' => (int) $witness->id,
                'full_name' => $witness->full_name ?? null,
                'phone' => $witness->phone ?? null,
                'email' => $witness->email ?? null,
                'address' => $witness->address ?? null,
            ])->values()->all(),
            'respondentResponses' => collect($data['respondentResponses'])->map(fn ($response): array => [
                'id' => (int) $response->id,
                'title' => $response->title ?? null,
                'case_number' => $response->case_number ?? null,
                'description' => $response->description ? Str::limit(strip_tags((string) $response->description), 260) : null,
                'pdf_path' => $response->pdf_path ?? null,
                'download_url' => $response->download_url ?? null,
                'created_at' => $iso($response->created_at ?? null),
            ])->values()->all(),
            'letters' => collect($data['letters'])->map(fn ($letter): array => [
                'id' => (int) $letter->id,
                'subject' => $letter->subject ?? null,
                'reference_number' => $letter->reference_number ?? null,
                'approval_status' => $letter->approval_status ?? null,
                'author_name' => $letter->author_name ?? null,
                'body' => $clean($letter->body ?? null),
                'created_at' => $iso($letter->created_at ?? null),
            ])->values()->all(),
            'hearings' => collect($data['hearings'])->map(fn ($hearing): array => [
                'id' => (int) $hearing->id,
                'hearing_at' => $iso($hearing->hearing_at ?? null),
                'type' => $hearing->type ?? null,
                'location' => $hearing->location ?? null,
                'judge_notes' => isset($hearing->judge_notes) && $hearing->judge_notes !== '' ? strip_tags((string) $hearing->judge_notes) : null,
                'notes' => isset($hearing->notes) && $hearing->notes !== '' ? strip_tags((string) $hearing->notes) : null,
            ])->values()->all(),
            'decision' => $decision ? [
                'id' => (int) $decision->id,
                'title' => $decision->title ?? null,
                'content' => $clean($decision->decision_content ?? $decision->body ?? null),
                'created_at' => $iso($decision->created_at ?? null),
            ] : null,
            'evidences' => collect($data['evidences'])->map(fn ($evidence): array => [
                'id' => (int) $evidence->id,
                'title' => $evidence->title ?? null,
                'description' => $evidence->description ?? null,
                'created_at' => $iso($evidence->created_at ?? null),
            ])->values()->all(),
            'filesCount' => collect($data['files'])->count() + collect($data['evidences'])->count(),
            'hasAppealRecord' => $decision !== null || in_array($case->status ?? '', ['closed', 'dismissed'], true),
        ];
    }

    private function recordData(CourtCase $case, bool $withEvidenceEmbed = true): array
    {
        $letters = DB::table('court_letters as l')
            ->leftJoin('court_staff as u', 'u.id', '=', 'l.user_id')
            ->select('l.*', 'u.name as author_name')
            ->where('l.case_number', $case->case_number)
            ->where('l.approval_status', 'approved')
            ->orderByDesc('l.created_at')
            ->get();

        $respondentResponses = DB::table('court_respondent_responses')
            ->where('case_number', $case->case_number)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($resp) {
                $resp->pdf_embed = null;
                $resp->download_url = $resp->id ? route('court.respondent-responses.download', $resp->id) : null;
                return $resp;
            });

        $hearings = DB::table('court_case_hearings')
            ->where('case_id', $case->id)
            ->orderBy('hearing_at')
            ->get();

        $benchNotes = DB::table('court_bench_notes as b')
            ->leftJoin('court_staff as u', 'u.id', '=', 'b.user_id')
            ->leftJoin('court_staff as j1', 'j1.id', '=', 'b.judge_one_id')
            ->leftJoin('court_staff as j2', 'j2.id', '=', 'b.judge_two_id')
            ->leftJoin('court_staff as j3', 'j3.id', '=', 'b.judge_three_id')
            ->select(
                'b.*',
                'u.name as author',
                'j1.name as judge_one_name',
                'j1.signature_path as judge_one_signature',
                DB::raw('COALESCE(j1.position, "") as judge_one_title'),
                'j2.name as judge_two_name',
                'j2.signature_path as judge_two_signature',
                DB::raw('COALESCE(j2.position, "") as judge_two_title'),
                'j3.name as judge_three_name',
                'j3.signature_path as judge_three_signature',
                DB::raw('COALESCE(j3.position, "") as judge_three_title')
            )
            ->where('b.case_id', $case->id)
            ->orderByDesc('b.created_at')
            ->get();

        $decision = DB::table('court_decisions')
            ->where('court_case_id', $case->id)
            ->orderByDesc('decision_date')
            ->orderByDesc('id')
            ->first();

        $files = DB::table('court_case_files')
            ->where('case_id', $case->id)
            ->orderByDesc('created_at')
            ->get();

        $evidences = DB::table('court_case_evidences')
            ->where('case_id', $case->id)
            ->orderByDesc('created_at')
            ->get();

        // Only the PDF embeds the first evidence file; reading it is wasted work for the page.
        $firstEvidenceEmbed = null;
        foreach ($withEvidenceEmbed ? $evidences : [] as $ev) {
            $path = $ev->file_path ?? $ev->path ?? null;
            if (!$path) {
                continue;
            }

            $size = (int) ($ev->size ?? 0);
            if ($size > 1500000) {
                continue;
            }

            $content = null;
            if (Storage::disk('court_private')->exists($path)) {
                $content = Storage::disk('court_private')->get($path);
            } elseif (Storage::disk('court_public')->exists($path)) {
                $content = Storage::disk('court_public')->get($path);
            } elseif (file_exists($path)) {
                $content = @file_get_contents($path);
            }

            if ($content) {
                $mime = $ev->mime ?? 'application/pdf';
                $firstEvidenceEmbed = [
                    'mime' => $mime,
                    'data' => base64_encode($content),
                    'title' => $ev->title ?? 'Document',
                ];
                break;
            }
        }

        $messages = DB::table('court_case_messages as m')
            ->leftJoin('court_staff as u', 'u.id', '=', 'm.sender_user_id')
            ->leftJoin('court_applicants as a', 'a.id', '=', 'm.sender_applicant_id')
            ->select(
                'm.id',
                'm.body',
                'm.created_at',
                'm.sender_user_id',
                'm.sender_applicant_id',
                'u.name as user_name',
                DB::raw("TRIM(COALESCE(a.first_name,'') || ' ' || COALESCE(a.middle_name,'') || ' ' || COALESCE(a.last_name,'')) as applicant_name")
            )
            ->where('m.case_id', $case->id)
            ->orderByDesc('m.created_at')
            ->get();

        $statusLogs = DB::table('court_case_status_logs')
            ->where('case_id', $case->id)
            ->orderBy('created_at')
            ->get();

        $witnesses = DB::table('court_case_witnesses')
            ->where('case_id', $case->id)
            ->orderBy('id')
            ->get();

        $assignedUser = null;
        $assignedTeams = collect();
        if (!empty($case->assigned_user_id)) {
            $assignedUser = DB::table('court_staff')->where('id', $case->assigned_user_id)->first();
            $assignedTeams = DB::table('court_team_user as tu')
                ->join('court_teams as t', 't.id', '=', 'tu.team_id')
                ->where('tu.user_id', $case->assigned_user_id)
                ->pluck('t.name');
        }

        $closedAt = optional(
            $statusLogs->filter(function ($log) {
                return in_array($log->to_status, ['closed', 'dismissed'], true);
            })->sortByDesc('created_at')->first()
        )->created_at;

        $case->loadMissing([
            'applicant:id,first_name,middle_name,last_name,email,phone',
            'caseType:id,name,prefix',
        ]);

        return [
            'case' => $case,
            'letters' => $letters,
            'respondentResponses' => $respondentResponses,
            'hearings' => $hearings,
            'benchNotes' => $benchNotes,
            'decision' => $decision,
            'files' => $files,
            'evidences' => $evidences,
            'messages' => $messages,
            'statusLogs' => $statusLogs,
            'witnesses' => $witnesses,
            'assignedUser' => $assignedUser,
            'assignedTeams' => $assignedTeams,
            'closedAt' => $closedAt,
            'firstEvidenceEmbed' => $firstEvidenceEmbed,
        ];
    }

    /**
     * The record shows the whole case file, so it follows the case page's rules: the
     * case policy (unaccepted cases only for reviewers and the assignee) plus the
     * team scope that limits the record list.
     */
    private function authorizeRecordAccess(CourtCase $case): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && Gate::forUser($user)->allows('view', $case), 403);
        // The team scope of the record list and the case page (CaseScope).
        abort_unless(\Modules\Court\Application\Support\CaseScope::allows($user, $case), 403);
    }

    private function teamLeaderAssignmentIds(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $isLeader = $user->hasPermission('cases.assign.member');
        $hasAdminAssign = $user->hasPermission('cases.assign.team');

        if (!$isLeader || $hasAdminAssign) {
            return [];
        }

        $leaderTeam = Team::with(['users' => fn ($query) => $query->where('status', 'active')->orderBy('name')])
            ->where('team_leader_id', $user->id)
            ->first();

        $ids = collect([$user->id]);

        if ($leaderTeam) {
            $ids = $ids->merge($leaderTeam->users->pluck('id'));
        }

        return $ids->filter()->unique()->values()->all();
    }
}
