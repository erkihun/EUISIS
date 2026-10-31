<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Court\Domain\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Domain\Models\RespondentResponse;
use Modules\Court\Domain\Models\ApplicantResponseReply;
use Modules\Court\Application\Services\ResponseNotificationService;
use Modules\Court\Application\Services\SecureUploadService;
use Modules\Court\Application\Services\LegalHoldService;
use Inertia\Inertia;

class CaseController extends Controller
{
    /**
     * Admin: list cases with optional search & filters.
     */
    public function index(Request $request)
    {
        // Permission is enforced in routes via perm:cases.view
        $q          = trim($request->string('q')->toString());
        $status     = $request->string('status')->toString();          // pending|active|adjourned|dismissed|closed
        $caseTypeId = $request->integer('case_type_id');

        $assigneeId = $request->integer('assignee_id');
        $from       = $request->date('from'); // yyyy-mm-dd
        $to         = $request->date('to');   // yyyy-mm-dd

        $isReviewer = false;
        if (true) {
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
                'ap.is_lawyer as applicant_is_lawyer',
                'ass.name as assignee_name',
                'reviewer.name as reviewer_name'
            )
            ->selectSub($teamNameSubquery, 'team_name');

        if ($q !== '') {
            $builder->where(function ($w) use ($q) {
                $w->where('c.case_number', 'like', "%{$q}%")
                    ->orWhere('c.title', 'like', "%{$q}%")

                    ->orWhere('ct.name', 'like', "%{$q}%");
            });
        }

        if ($status !== '')   $builder->where('c.status', $status);
        if ($caseTypeId)      $builder->where('c.case_type_id', $caseTypeId);

        if ($assigneeId)      $builder->where('c.assigned_user_id', $assigneeId);
        if ($from)            $builder->whereDate('c.filing_date', '>=', $from->format('Y-m-d'));
        if ($to)              $builder->whereDate('c.filing_date', '<=', $to->format('Y-m-d'));

        $memberScopeIds = $this->teamLeaderAssignmentIds(Auth::user());
        if (!empty($memberScopeIds)) {
            $leaderTeamId = Team::where('team_leader_id', Auth::id())->value('id');
            $builder->where(function ($q) use ($memberScopeIds, $leaderTeamId) {
                $q->whereIn('c.assigned_user_id', $memberScopeIds);
                if ($leaderTeamId) {
                    $q->orWhere('c.assigned_team_id', $leaderTeamId);
                }
            });
        } else {
            $uid = Auth::id();
            $isTeamMember = $uid && DB::table('court_team_user')->where('user_id', $uid)->exists();
            $isLeader = Auth::user()?->hasPermission('cases.assign.member') ?? false;
            $canAssignTeams = Auth::user()?->hasPermission('cases.assign.team') ?? false;

            if ($isTeamMember && !$isLeader && !$canAssignTeams) {
                $builder->where('c.assigned_member_user_id', $uid);
            }
        }

        if (!$isReviewer)     $builder->where('c.review_status', 'accepted');

        $cases = $builder
            ->orderByRaw('COALESCE(c.created_at, c.filing_date) DESC')
            ->paginate(10)
            ->withQueryString();

        // For filter dropdowns
        $types  = DB::table('court_case_types')->orderBy('name')->get(['id', 'name']);

        $users  = DB::table('court_staff')->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        // The register (React, Court/Cases/Index): a non-reviewer sees every case as accepted, as before.
        return Inertia::render('Court/Cases/Index', [
            'cases' => $cases->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_number' => (string) $row->case_number,
                'title' => $row->title,
                'applicant_name' => ($row->applicant_name ?? '') !== '' ? $row->applicant_name : null,
                'applicant_is_lawyer' => (bool) ($row->applicant_is_lawyer ?? false),
                'case_type' => $row->case_type,
                'team_name' => $row->team_name,
                'assignee_name' => $row->assignee_name,
                'status' => (string) $row->status,
                'filing_date' => $row->filing_date ? Carbon::parse($row->filing_date)->toDateString() : null,
                'review_status' => $isReviewer ? (string) ($row->review_status ?? 'accepted') : 'accepted',
                'reviewer_name' => $row->reviewer_name,
            ]),
            'filters' => [
                'q' => $q,
                'status' => $status,
                'case_type_id' => $caseTypeId ? (string) $caseTypeId : '',
                'assignee_id' => $assigneeId ? (string) $assigneeId : '',
                'from' => $from?->format('Y-m-d') ?? '',
                'to' => $to?->format('Y-m-d') ?? '',
            ],
            'statuses' => ['pending', 'active', 'adjourned', 'dismissed', 'closed'],
            'types' => $types,
            'users' => $users,
            'isReviewer' => $isReviewer,
            'canExport' => (bool) (Auth::user()?->hasPermission('reports.export') ?? false),
        ]);
    }

    /**
     * Admin: export filtered list as CSV.
     */
    public function export(Request $request)
    {
        $q          = trim($request->string('q')->toString());
        $status     = $request->string('status')->toString();
        $caseTypeId = $request->integer('case_type_id');

        $assigneeId = $request->integer('assignee_id');
        $from       = $request->date('from');
        $to         = $request->date('to');

        $teamNameSubquery = DB::table('court_teams as t')
            ->join('court_team_user as tu', 'tu.team_id', '=', 't.id')
            ->whereColumn('tu.user_id', 'ass.id')
            ->orderBy('t.name')
            ->limit(1)
            ->select('t.name');

        $builder = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')
            ->leftJoin('court_staff as ass', 'ass.id', '=', 'c.assigned_user_id')
            ->select(
                'c.case_number',
                'c.title',
                'ct.name as case_type',

                'c.status',
                'c.filing_date',
                'ass.name as assignee_name'
            )
            ->selectSub($teamNameSubquery, 'team_name');

        if ($q !== '') {
            $builder->where(function ($w) use ($q) {
                $w->where('c.case_number', 'like', "%{$q}%")
                    ->orWhere('c.title', 'like', "%{$q}%")

                    ->orWhere('ct.name', 'like', "%{$q}%");
            });
        }
        if ($status !== '')   $builder->where('c.status', $status);
        if ($caseTypeId)      $builder->where('c.case_type_id', $caseTypeId);

        if ($assigneeId)      $builder->where('c.assigned_user_id', $assigneeId);
        if ($from)            $builder->whereDate('c.filing_date', '>=', $from->format('Y-m-d'));
        if ($to)              $builder->whereDate('c.filing_date', '<=', $to->format('Y-m-d'));

        $memberScopeIds = $this->teamLeaderAssignmentIds(Auth::user());
        if (!empty($memberScopeIds)) {
            $leaderTeamId = Team::where('team_leader_id', Auth::id())->value('id');
            $builder->where(function ($q) use ($memberScopeIds, $leaderTeamId) {
                $q->whereIn('c.assigned_user_id', $memberScopeIds);
                if ($leaderTeamId) {
                    $q->orWhere('c.assigned_team_id', $leaderTeamId);
                }
            });
        } else {
            $uid = Auth::id();
            $isTeamMember = $uid && DB::table('court_team_user')->where('user_id', $uid)->exists();
            $isLeader = Auth::user()?->hasPermission('cases.assign.member') ?? false;
            $canAssignTeams = Auth::user()?->hasPermission('cases.assign.team') ?? false;

            if ($isTeamMember && !$isLeader && !$canAssignTeams) {
                $builder->where('c.assigned_member_user_id', $uid);
            }
        }

        $rows = $builder->orderBy('c.filing_date')->get();

        $filename = 'cases-export-' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Case #', 'Title', 'Type',  'Status', 'Filed', 'Assignee']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->case_number,
                    $r->title,
                    $r->case_type,

                    ucfirst($r->status),
                    \Illuminate\Support\Carbon::parse($r->filing_date)->format('Y-m-d'),
                    $r->assignee_name,
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Admin: show assign form.
     */
    public function assignForm(int $caseId)
    {
        $case = DB::table('court_cases as c')
            ->leftJoin('court_staff as u', 'u.id', '=', 'c.assigned_user_id')
            ->select('c.*', 'u.name as assignee_name', 'u.email as assignee_email')
            ->where('c.id', $caseId)
            ->first();

        abort_if(!$case, 404);

        $scope = $this->buildAssignmentScope();
        $this->ensureCaseAccess($case);
        $leaderTeam = $scope['leaderTeam'];

        return Inertia::render('Court/Cases/Assign', [
            'case' => [
                'id' => (int) $case->id,
                'case_number' => (string) $case->case_number,
                'title' => $case->title ?? null,
                'status' => (string) ($case->status ?? 'pending'),
                'assigned_user_id' => isset($case->assigned_user_id) ? (int) $case->assigned_user_id : null,
                'assignee_name' => $case->assignee_name ?? null,
                'assignee_email' => $case->assignee_email ?? null,
                'assigned_at' => $this->isoDate($case->assigned_at ?? null),
            ],
            'assignmentMode' => $scope['mode'],
            'canAssignLeaders' => (bool) (Auth::user()?->hasPermission('cases.assign.team') ?? false),
            'leaderTeam' => $leaderTeam ? [
                'id' => (int) $leaderTeam->id,
                'name' => (string) $leaderTeam->name,
                'members' => $leaderTeam->users->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name, 'email' => (string) $u->email])->values()->all(),
            ] : null,
            'teams' => collect($scope['teams'])->filter(fn ($team) => $team->leader !== null)->map(fn ($team) => [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'leader' => ['id' => (int) $team->leader->id, 'name' => (string) $team->leader->name, 'email' => (string) $team->leader->email],
            ])->values()->all(),
        ]);
    }

    /**
     * Admin: update assignment (assign / unassign).
     */
    public function assignUpdate(Request $request, int $caseId)
    {
        $scope = $this->buildAssignmentScope();

        $rules = [
            'assigned_user_id' => [
                'nullable',
                'integer',
                Rule::exists('court_staff', 'id')->where(fn($q) => $q->where('status', 'active')),
            ],
            'unassign' => ['sometimes', 'boolean'],
        ];

        if (!empty($scope['allowedUserIds'])) {
            $rules['assigned_user_id'][] = Rule::in($scope['allowedUserIds']);
        }

        $validated = $request->validate($rules);

        $case = DB::table('court_cases')->where('id', $caseId)->first();
        abort_if(!$case, 404);
        $this->ensureCaseAccess($case);

        $assigning = !$request->boolean('unassign') && !empty($validated['assigned_user_id']);
        $assignedUserId = $assigning ? (int) $validated['assigned_user_id'] : null;
        $assignedTeamId = null;
        $assignedMemberUserId = null;

        if ($assigning) {
            if (($scope['mode'] ?? null) === 'leader') {
                $assignedTeamId = $scope['leaderTeam']?->id;
                $assignedMemberUserId = $assignedUserId;
            } else {
                $assignedTeamId = Team::where('team_leader_id', $assignedUserId)->value('id');
            }
        }

        DB::table('court_cases')
            ->where('id', $caseId)
            ->update([
                'assigned_user_id' => $assignedUserId,
                'assigned_team_id' => $assignedTeamId,
                'assigned_member_user_id' => $assignedMemberUserId,
                'assigned_at'      => $assigning ? Carbon::now() : null,
                'updated_at'       => Carbon::now(),
            ]);

        $this->logCaseAudit($caseId, $assigning ? 'assigned' : 'unassigned', [
            'assigned_user_id' => $assignedUserId,
            'assigned_team_id' => $assignedTeamId,
            'assigned_member_user_id' => $assignedMemberUserId,
        ]);

        return redirect()
            ->route('court.cases.index')
            ->with('success', [
                'key' => $assigning ? 'court::messages.success.assigned' : 'court::messages.success.unassigned',
                'replace' => ['resource' => __('court::messages.resources.case')],
            ]);
    }

    private function buildAssignmentScope(): array
    {
        $user = Auth::user();
        abort_if(!$user, 403, 'Authenticated user required.');
        if (!$user->hasPermission('cases.assign')) {
            throw new HttpResponseException(
                redirect()->route('court.cases.index')
                    ->with('error', 'court::messages.error.case_assignment_denied')
            );
        }

        $leaderTeam = null;
        if ($user->hasPermission('cases.assign.member')) {
            $leaderTeam = Team::with(['users' => fn ($q) => $q->where('status', 'active')->orderBy('name')])
                ->where('team_leader_id', $user->id)
                ->first();

            if ($leaderTeam) {
                return [
                    'mode' => 'leader',
                    'leaderTeam' => $leaderTeam,
                    'teams' => collect(),
                    'allowedUserIds' => $leaderTeam->users->pluck('id')->unique()->values()->all(),
                ];
            }
        }

        if (!$user->hasPermission('cases.assign.team')) {
            throw new HttpResponseException(
                redirect()->route('court.cases.index')
            ->with('error', 'court::messages.error.team_assignment_permission')
            );
        }

        $teams = Team::with(['leader' => fn ($q) => $q->where('status', 'active')])
            ->whereNotNull('team_leader_id')
            ->whereHas('leader', fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')
            ->get();

        $leaderIds = $teams->pluck('team_leader_id')->filter()->unique();

        return [
            'mode' => 'admin',
            'leaderTeam' => null,
            'teams' => $teams,
            'allowedUserIds' => $leaderIds->unique()->values()->all(),
        ];
    }

    /**
     * Admin: show a case (with context).
     * Evidence REMOVED (we use Uploaded Files only). $docs left empty to keep the Blade happy.
     *
     * NOTE: description/relief were already purified on applicant save/update.
     * We expose them as *_html so Blade can render with `{!! $case->description_html !!}` safely.
     */
    public function show(int $id)
    {
        $this->authorizeView();
        $safeGet = function (callable $query, string $segment) {
            try {
                return $query();
            } catch (\Throwable $e) {
                Log::warning('Case show segment failed; using empty dataset.', [
                    'segment' => $segment,
                    'error' => $e->getMessage(),
                ]);
                return collect();
            }
        };

        $case = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')

            ->leftJoin('court_staff as ass', 'ass.id', '=', 'c.assigned_user_id')
            ->leftJoin('court_team_user as tu', 'tu.user_id', '=', 'ass.id')
            ->leftJoin('court_teams as ass_team', 'ass_team.id', '=', 'tu.team_id')
            ->leftJoin('court_applicants as ap', 'ap.id', '=', 'c.applicant_id')
            ->select(
                'c.*',
                'ct.name as case_type',

                'ass.name as assignee_name',
                'ass.email as assignee_email',
                'ass_team.name as assignee_team_name',
                'ap.first_name as applicant_first_name',
                'ap.middle_name as applicant_middle_name',
                'ap.last_name as applicant_last_name',
                DB::raw("TRIM(CONCAT(COALESCE(ap.first_name,''),' ',COALESCE(ap.middle_name,''),' ',COALESCE(ap.last_name,''))) as applicant_name"),
                'ap.is_lawyer as applicant_is_lawyer',
                'ap.email as applicant_email',
                'ap.address as applicant_profile_address',
                'ap.lawyer_document_path as applicant_lawyer_document_path'
            )
            ->where('c.id', $id)
            ->first();

        abort_if(!$case, 404, 'Case not found.');

        // A case awaiting review opens only for reviewers and its assignee, as on the record page (CourtCasePolicy::view).
        $this->authorize('view', \Modules\Court\Domain\Models\CourtCase::query()->findOrFail($id));
        $this->ensureCaseAccess($case);

        // Make HTML fields explicit for Blade raw rendering
        $case->description_html        = (string) ($case->description ?? '');
        $case->relief_requested_html   = (string) ($case->relief_requested ?? '');

        $timeline = Schema::hasTable('court_case_status_logs')
            ? $safeGet(function () use ($id) {
                return DB::table('court_case_status_logs')
                    ->select(
                        'court_case_status_logs.*',
                        DB::raw('NULL AS note') // so $t->note always exists
                    )
                    ->where('case_id', $id)
                    ->orderBy('created_at')
                    ->get();
            }, 'case_status_logs')
            : collect();

        $letters = Schema::hasTable('court_letters')
            ? $safeGet(function () use ($case) {
                return DB::table('court_letters as l')
                    ->leftJoin('court_letter_templates as lt', 'lt.id', '=', 'l.letter_template_id')
                    ->leftJoin('court_staff as u', 'u.id', '=', 'l.user_id')
                    ->select(
                        'l.id',
                        'l.subject',
                        'l.reference_number',
                        'l.approval_status',
                        'l.created_at',
                        'lt.title as template_title',
                        'u.name as author_name'
                    )
                    ->where('l.case_number', $case->case_number)
                    ->orderByDesc('l.created_at')
                    ->get();
            }, 'letters')
            : collect();

        $respondentResponses = Schema::hasTable('court_respondent_responses')
            ? $safeGet(function () use ($case) {
                return DB::table('court_respondent_responses')
                    ->where('case_number', $case->case_number)
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(function ($resp) {
                        $resp->pdf_embed = null;
                        if (empty($resp->pdf_path)) {
                            return $resp;
                        }

                        $content = null;
                        if (Storage::disk('court_private')->exists($resp->pdf_path)) {
                            $content = Storage::disk('court_private')->get($resp->pdf_path);
                        } elseif (Storage::disk('court_public')->exists($resp->pdf_path)) {
                            $content = Storage::disk('court_public')->get($resp->pdf_path);
                        } elseif (file_exists($resp->pdf_path)) {
                            $content = @file_get_contents($resp->pdf_path);
                        }

                        if ($content) {
                            $resp->pdf_embed = [
                                'mime' => 'application/pdf',
                                'data' => base64_encode($content),
                            ];
                        }

                        return $resp;
                    });
            }, 'respondent_responses')
            : collect();

        $canManageResponseReplies = Auth::user()?->hasPermission('cases.response-replies.manage') ?? false;

        $applicantResponseReplies = ($canManageResponseReplies && Schema::hasTable('court_applicant_response_replies'))
            ? $safeGet(function () use ($case) {
                return DB::table('court_applicant_response_replies as arr')
                    ->join('court_respondent_responses as rr', 'rr.id', '=', 'arr.respondent_response_id')
                    ->leftJoin('court_applicants as a', 'a.id', '=', 'arr.applicant_id')
                    ->select(
                        'arr.*',
                        'rr.response_number',
                        'rr.title as respondent_response_title',
                        'a.first_name as applicant_first_name',
                        'a.middle_name as applicant_middle_name',
                        'a.last_name as applicant_last_name'
                    )
                    ->where('arr.case_id', $case->id)
                    ->where('rr.case_number', $case->case_number)
                    ->orderByDesc('arr.created_at')
                    ->get();
            }, 'applicant_response_replies')
            : collect();

        // Uploaded Files (admin + applicant)
        $files = Schema::hasTable('court_case_files')
            ? $safeGet(function () use ($id) {
                return DB::table('court_case_files as f')
                    ->leftJoin('court_applicants as a', 'a.id', '=', 'f.uploaded_by_applicant_id')
                    ->leftJoin('court_staff as u', 'u.id', '=', 'f.uploaded_by_user_id')
                    ->select('f.*', 'a.first_name', 'a.last_name', 'u.name as uploader_name')
                    ->where('f.case_id', $id)
                    ->orderByDesc('f.created_at')
                    ->get();
            }, 'case_files')
            : collect();

        // Messages
        $messages = Schema::hasTable('court_case_messages')
            ? $safeGet(function () use ($id) {
                return DB::table('court_case_messages as m')
                    ->leftJoin('court_applicants as a', 'a.id', '=', 'm.sender_applicant_id')
                    ->leftJoin('court_staff as u', 'u.id', '=', 'm.sender_user_id')
                    ->select('m.*', 'a.first_name', 'a.last_name', 'u.name as admin_name')
                    ->where('m.case_id', $id)
                    ->orderBy('m.created_at')
                    ->get();
            }, 'case_messages')
            : collect();

        // Hearings
        $hearings = Schema::hasTable('court_case_hearings')
            ? $safeGet(function () use ($id) {
                return DB::table('court_case_hearings')
                    ->where('case_id', $id)
                    ->orderByDesc('hearing_at')
                    ->get();
            }, 'case_hearings')
            : collect();

        // Submitted documents – tolerate partial schemas by selecting only existing columns.
        $docs = collect();
        if (Schema::hasTable('court_case_evidences')) {
            $docColumns = ['e.id', 'e.created_at'];
            $hasColumn = fn(string $col) => Schema::hasColumn('court_case_evidences', $col);

            foreach (['title', 'description', 'file_path', 'path', 'mime', 'size', 'type'] as $col) {
                if ($hasColumn($col)) {
                    $docColumns[] = "e.{$col}";
                }
            }

            $docs = $safeGet(function () use ($id, $docColumns, $hasColumn) {
                return DB::table('court_case_evidences as e')
                    ->select($docColumns)
                    ->where('e.case_id', $id)
                    ->when(
                        $hasColumn('type'),
                        fn($q) => $q->where('e.type', 'document')
                    )
                    ->orderBy('e.id')
                    ->get();
            }, 'case_evidences');
        }

        // Witnesses
        $witnesses = Schema::hasTable('court_case_witnesses')
            ? $safeGet(function () use ($id) {
                return DB::table('court_case_witnesses')
                    ->where('case_id', $id)
                    ->orderBy('full_name')
                    ->get();
            }, 'case_witnesses')
            : collect();

        $audits = Schema::hasTable('court_case_audits')
            ? $safeGet(function () use ($id) {
                return DB::table('court_case_audits')
                    ->where('case_id', $id)
                    ->orderByDesc('id')
                    ->limit(200)
                    ->get();
            }, 'case_audits')
            : collect();

        // Enrich audits with actor names
        $userNames = collect();
        $applicantNames = collect();
        $userIds = $audits->where('actor_type', 'user')->pluck('actor_id')->filter()->unique();
        $applicantIds = $audits->where('actor_type', 'applicant')->pluck('actor_id')->filter()->unique();

        if ($userIds->isNotEmpty()) {
            $userNames = DB::table('court_staff')->whereIn('id', $userIds)->pluck('name', 'id');
        }
        if ($applicantIds->isNotEmpty()) {
            $applicantNames = DB::table('court_applicants')
                ->whereIn('id', $applicantIds)
                ->select('id', 'first_name', 'last_name')
                ->get()
                ->mapWithKeys(fn($r) => [$r->id => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''))]);
        }

        foreach ($audits as $a) {
            if ($a->actor_type === 'user' && $a->actor_id) {
                $a->actor_name = $userNames[$a->actor_id] ?? null;
            } elseif ($a->actor_type === 'applicant' && $a->actor_id) {
                $a->actor_name = $applicantNames[$a->actor_id] ?? null;
            } else {
                $a->actor_name = null;
            }
        }

        $letterTemplates = Schema::hasTable('court_letter_templates')
            ? $safeGet(function () {
                return DB::table('court_letter_templates')->orderBy('title')->get();
            }, 'letter_templates')
            : collect();

        $inspectionAssignees = collect();
        if (Auth::user()?->hasPermission('assign.inspections')) {
            $inspectionAssignees = User::query()
                ->where('status', 'active')
                ->whereHas('roles.permissions', fn($q) => $q->where('name', 'inspection-requests.manage'))
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $inspectionRequests = collect();
        if (Schema::hasTable('court_case_inspection_requests')) {
            $inspectionRequests = $safeGet(function () use ($id) {
                $user = Auth::user();
                $canAssignInspections = $user?->hasPermission('assign.inspections') ?? false;
                $canManageInspectionRequests = $user?->hasPermission('inspection-requests.manage') ?? false;

                $query = DB::table('court_case_inspection_requests as cir')
                    ->leftJoin('court_staff as req', 'req.id', '=', 'cir.requested_by_user_id')
                    ->leftJoin('court_staff as assn', 'assn.id', '=', 'cir.assigned_inspector_user_id')
                    ->leftJoin('court_staff as creator', 'creator.id', '=', 'cir.created_by_user_id')
                    ->select(
                        'cir.*',
                        'req.name as requested_by_name',
                        'assn.name as assigned_inspector_name',
                        'creator.name as created_by_name'
                    )
                    ->where('cir.court_case_id', $id)
                    ->orderByDesc('cir.request_date')
                    ->orderByDesc('cir.id');

                if (!$canAssignInspections && !$canManageInspectionRequests) {
                    $query->where(function ($q) use ($user) {
                        $q->where('cir.assigned_inspector_user_id', $user?->id)
                            ->orWhere('cir.requested_by_user_id', $user?->id);
                    });
                }

                return $query->get();
            }, 'case_inspection_requests');
        }

        $inspectionFindings = collect();
        if (Schema::hasTable('court_case_inspection_findings') && Schema::hasTable('court_case_inspection_requests')) {
            $inspectionFindings = $safeGet(function () use ($id) {
                $user = Auth::user();
                $isAdmin = $user?->hasRole('admin') ?? false;

                $query = DB::table('court_case_inspection_findings as cif')
                    ->join('court_case_inspection_requests as cir', 'cir.id', '=', 'cif.case_inspection_request_id')
                    ->leftJoin('court_staff as rec', 'rec.id', '=', 'cif.recorded_by_user_id')
                    ->leftJoin('court_staff as assn', 'assn.id', '=', 'cir.assigned_inspector_user_id')
                    ->select(
                        'cif.*',
                        'cir.subject as request_subject',
                        'cir.status as request_status',
                        'cir.assigned_inspector_user_id',
                        'assn.name as assigned_inspector_name',
                        'rec.name as recorded_by_name'
                    )
                    ->where('cir.court_case_id', $id)
                    ->orderByDesc('cif.finding_date')
                    ->orderByDesc('cif.id');

                if (!$isAdmin) {
                    $query->where('cir.assigned_inspector_user_id', $user?->id);
                }

                return $query->get();
            }, 'case_inspection_findings');
        }

        return Inertia::render('Court/Cases/Show', ['parties' => CasePartiesController::props((int) $case->id, auth()->user())] + $this->caseDetailProps(
            $case,
            $timeline,
            $files,
            $messages,
            $hearings,
            $docs,
            $witnesses,
            $audits,
            $letters,
            $respondentResponses,
            $applicantResponseReplies,
            $letterTemplates,
            $inspectionAssignees,
            $inspectionRequests,
            $inspectionFindings,
        ));
    }

    /**
     * The case detail page's props: the Blade page's rows as plain arrays with
     * ISO 8601 dates, the permission booleans the Blade page checked inline,
     * and the derived values it computed in @php blocks (lock, terms, next
     * letter reference, the EUISIS panel).
     */
    private function caseDetailProps(
        object $case,
        $timeline,
        $files,
        $messages,
        $hearings,
        $docs,
        $witnesses,
        $audits,
        $letters,
        $respondentResponses,
        $applicantResponseReplies,
        $letterTemplates,
        $inspectionAssignees,
        $inspectionRequests,
        $inspectionFindings,
    ): array {
        $user = Auth::user();
        $can = fn (string $permission): bool => (bool) \Modules\Court\Application\Support\CourtPermissions::check($permission);
        $isAdmin = $user?->hasRole('admin') ?? false;

        $hasActiveDecision = false;
        try {
            $hasActiveDecision = \Modules\Court\Domain\Models\Decision::where('court_case_id', $case->id)->where('status', 'published')->exists();
        } catch (\Throwable $e) {
            $hasActiveDecision = false;
        }
        $caseLocked = (($case->status ?? '') === 'closed') && $hasActiveDecision;

        $lawyerName = collect([
            $case->applicant_first_name ?? null,
            $case->applicant_middle_name ?? null,
            $case->applicant_last_name ?? null,
        ])->map(fn ($part) => trim((string) $part))->filter()->implode(' ');
        if ($lawyerName === '') {
            $lawyerName = trim((string) ($case->applicant_name ?? ''));
        }
        $applicantAddress = trim((string) ($case->applicant_address ?? ''));
        if ($applicantAddress === '') {
            $applicantAddress = trim((string) ($case->applicant_profile_address ?? ''));
        }

        $descriptionHtml = (string) ($case->description_html ?? '');
        if ($descriptionHtml === '' && !empty($case->description)) {
            $descriptionHtml = \Modules\Court\Application\Support\Purifier::clean((string) $case->description, 'cases');
        }
        $reliefHtml = null;
        if (!empty($case->relief_requested_html ?? null)) {
            $reliefHtml = \Modules\Court\Application\Support\Purifier::clean($case->relief_requested_html, 'cases');
        } elseif (!empty($case->relief_requested)) {
            $reliefHtml = \Modules\Court\Application\Support\Purifier::clean($case->relief_requested, 'cases');
        }

        // Active Terms & Conditions, printed as a list under the respondent section.
        $termsItems = [];
        try {
            $printTerms = \Modules\Court\Domain\Models\TermsAndCondition::published()->orderByDesc('published_at')->first();
            if ($printTerms && !empty($printTerms->body)) {
                $termsHtml = \Modules\Court\Application\Support\Purifier::clean($printTerms->body, 'cases');
                if (preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $termsHtml, $m) && !empty($m[1])) {
                    $rows = $m[1];
                } elseif (preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $termsHtml, $m) && !empty($m[1])) {
                    $rows = $m[1];
                } else {
                    $rows = preg_split('/<br\s*\/?>|\r\n|\n/i', $termsHtml) ?: [];
                }
                foreach ($rows as $row) {
                    $row = trim(preg_replace('/\s+/', ' ', strip_tags($row, '<strong><b><em><i><u><a>')));
                    if ($row !== '') {
                        $termsItems[] = $row;
                    }
                }
            }
        } catch (\Throwable $e) {
            $termsItems = [];
        }

        // The next letter reference for this case (the compose panel shows it read-only).
        $nextReference = null;
        $caseNumber = (string) ($case->case_number ?? '');
        if ($caseNumber !== '' && Schema::hasTable('court_letters')) {
            $last = DB::table('court_letters')->where('case_number', $caseNumber)->orderBy('id', 'desc')->first();
            $nextSeq = ($last && preg_match('/\/(\d{2})$/', (string) $last->reference_number, $m)) ? intval($m[1]) + 1 : 1;
            $nextReference = $caseNumber . '/' . str_pad((string) $nextSeq, 2, '0', STR_PAD_LEFT);
        }

        $iso = fn ($value): ?string => $this->isoDate($value);
        $today = Carbon::today();

        return [
            'case' => [
                'id' => (int) $case->id,
                'case_number' => $caseNumber,
                'title' => $case->title ?? null,
                'status' => (string) ($case->status ?? 'pending'),
                'review_status' => (string) ($case->review_status ?? 'accepted'),
                'review_note' => $case->review_note ?? null,
                'case_type' => $case->case_type ?? null,
                'filing_date' => $iso($case->filing_date ?? null),
                'created_at' => $iso($case->created_at ?? null),
                'updated_at' => $iso($case->updated_at ?? null),
                'assigned_at' => $iso($case->assigned_at ?? null),
                'assigned_user_id' => isset($case->assigned_user_id) ? (int) $case->assigned_user_id : null,
                'assignee_name' => $case->assignee_name ?? null,
                'assignee_team_name' => $case->assignee_team_name ?? null,
                'applicant_display_name' => trim((string) ($case->title ?? '')),
                'lawyer_name' => $lawyerName,
                'submitted_by_lawyer' => !empty($case->applicant_is_lawyer) && $lawyerName !== '',
                'has_lawyer_document' => !empty($case->applicant_lawyer_document_path),
                'applicant_address' => $applicantAddress,
                'applicant_email' => $case->applicant_email ?? null,
                'respondent_name' => $case->respondent_name ?? null,
                'respondent_address' => $case->respondent_address ?? null,
                'description_html' => $descriptionHtml,
                'relief_html' => $reliefHtml,
            ],
            'can' => [
                'editStatus' => $can('cases.edit'),
                'assign' => $can('cases.assign.team') || $can('cases.assign.member') || $can('cases.assign'),
                'review' => $can('cases.review'),
                'manageResponseReplies' => $can('cases.response-replies.manage'),
                'writeLetter' => $can('letters.create'),
                'viewLetters' => $can('letters.view'),
                // The button opens the bench notes list, which needs both (BenchNoteController).
                'manageBench' => $can('bench-notes.manage') && $can('bench-notes.view'),
                'viewHearings' => $can('hearing.view'),
                'createHearings' => $can('hearing.create'),
                'updateHearings' => $can('hearing.update'),
                'deleteHearings' => $can('hearing.delete'),
                'viewFiles' => $can('file.view'),
                'createFiles' => $can('file.create'),
                'updateFiles' => $can('file.update'),
                'deleteFiles' => $can('file.delete'),
                'manageInspectionRequests' => $can('inspection-requests.manage'),
                'manageInspectionFindings' => $can('inspection-findings.manage'),
                'createMessage' => $can('message.create'),
                'isAdmin' => $isAdmin,
            ],
            'caseLocked' => $caseLocked,
            'statuses' => ['pending', 'active', 'dismissed', 'closed'],
            'currentUserInitial' => mb_strtoupper(mb_substr((string) ($user?->name ?? 'A'), 0, 1)),
            'termsItems' => $termsItems,
            'nextLetterReference' => $nextReference,
            'timeline' => collect($timeline)->map(fn ($row) => [
                'id' => (int) $row->id,
                'from_status' => $row->from_status ?? null,
                'to_status' => $row->to_status ?? null,
                'note' => $row->note ?? null,
                'created_at' => $iso($row->created_at ?? null),
            ])->values()->all(),
            'hearings' => collect($hearings)->map(function ($h) use ($iso, $today) {
                $locked = false;
                try {
                    $locked = Carbon::parse($h->hearing_at)->startOfDay()->lte($today);
                } catch (\Throwable $e) {
                    $locked = false;
                }

                return [
                    'id' => (int) $h->id,
                    'hearing_at' => $iso($h->hearing_at ?? null),
                    'type' => $h->type ?? null,
                    'location' => $h->location ?? null,
                    'notes' => $h->notes ?? null,
                    'date_locked' => $locked,
                ];
            })->values()->all(),
            'files' => collect($files)->map(fn ($f) => [
                'id' => (int) $f->id,
                'label' => $f->label ?? null,
                'name' => $f->label ?? basename((string) ($f->path ?? '')),
                'mime' => $f->mime ?? null,
                'size' => (int) ($f->size ?? 0),
                'created_at' => $iso($f->created_at ?? null),
                'uploaded_by' => ($f->uploader_name ?? null) ?: (trim(($f->first_name ?? '') . ' ' . ($f->last_name ?? '')) ?: null),
            ])->values()->all(),
            'messages' => collect($messages)->map(function ($m) use ($iso) {
                $fromAdmin = !is_null($m->sender_user_id);
                $fromApplicant = !is_null($m->sender_applicant_id);
                $who = $fromAdmin
                    ? (($m->admin_name ?? null) ?: __('court::cases.messages.court_staff'))
                    : ($fromApplicant ? trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? '')) : __('court::cases.messages_section.system'));

                return [
                    'id' => (int) $m->id,
                    'body' => (string) ($m->body ?? ''),
                    'from_admin' => $fromAdmin,
                    'sender_name' => $who,
                    'created_at' => $iso($m->created_at ?? null),
                ];
            })->values()->all(),
            'docs' => collect($docs)->map(function ($d) use ($iso) {
                $filePath = $d->file_path ?? $d->path ?? null;

                return [
                    'id' => (int) $d->id,
                    'title' => trim((string) ($d->title ?? '')) ?: ($filePath ? basename((string) $filePath) : null),
                    'description_html' => !empty($d->description) ? \Modules\Court\Application\Support\Purifier::clean((string) $d->description, 'cases') : null,
                    'mime' => $d->mime ?? null,
                    'size' => isset($d->size) ? (int) $d->size : null,
                    'created_at' => $iso($d->created_at ?? null),
                    'has_file' => !empty($filePath),
                ];
            })->values()->all(),
            'witnesses' => collect($witnesses)->map(fn ($w) => [
                'id' => (int) $w->id,
                'full_name' => (string) ($w->full_name ?? ''),
                'phone' => $w->phone ?? null,
                'email' => $w->email ?? null,
                'address' => $w->address ?? null,
                'notes' => $w->notes ?? null,
            ])->values()->all(),
            'audits' => $this->auditRows($audits, $iso),
            'letters' => collect($letters)->map(fn ($l) => [
                'id' => (int) $l->id,
                'subject' => $l->subject ?? null,
                'reference_number' => $l->reference_number ?? null,
                'approval_status' => strtolower((string) ($l->approval_status ?? 'pending')),
                'template_title' => $l->template_title ?? null,
                'author_name' => $l->author_name ?? null,
                'created_at' => $iso($l->created_at ?? null),
            ])->values()->all(),
            'letterTemplates' => collect($letterTemplates)->map(function ($tpl) {
                $placeholders = $tpl->placeholders ?? [];
                if (is_string($placeholders)) {
                    $decoded = json_decode($placeholders, true);
                    $placeholders = $decoded ?: [];
                }

                return [
                    'id' => (int) $tpl->id,
                    'title' => (string) ($tpl->title ?? ''),
                    'body' => (string) ($tpl->body ?? ''),
                    'category' => $tpl->category ?? null,
                    'placeholders' => array_values(array_map('strval', is_array($placeholders) ? $placeholders : [])),
                ];
            })->values()->all(),
            'respondentResponses' => collect($respondentResponses)->map(fn ($r) => [
                'id' => (int) $r->id,
                'title' => $r->title ?? null,
                'case_number' => $r->case_number ?? null,
                'response_number' => isset($r->response_number) && $r->response_number !== '' ? (string) $r->response_number : null,
                'description_html' => !empty($r->description) ? \Modules\Court\Application\Support\Purifier::clean((string) $r->description, 'cases') : '',
                'review_status' => (string) ($r->review_status ?? 'awaiting_review'),
                'review_note' => $r->review_note ?? null,
                'pdf_url' => !empty($r->pdf_path) ? route('court.respondent-responses.download', ['response' => $r->id, 'inline' => 1]) : null,
                'created_at' => $iso($r->created_at ?? null),
            ])->values()->all(),
            'applicantResponseReplies' => collect($applicantResponseReplies)->map(fn ($reply) => [
                'id' => (int) $reply->id,
                'respondent_response_id' => (int) $reply->respondent_response_id,
                'response_number' => $reply->response_number ?? null,
                'applicant_name' => trim(($reply->applicant_first_name ?? '') . ' ' . ($reply->applicant_middle_name ?? '') . ' ' . ($reply->applicant_last_name ?? '')),
                'description' => (string) ($reply->description ?? ''),
                'review_status' => (string) ($reply->review_status ?? 'awaiting_review'),
                'review_note' => $reply->review_note ?? null,
                'pdf_url' => !empty($reply->pdf_path)
                    ? route('court.admin.applicant-response-replies.download', ['case' => $case->id, 'response' => $reply->respondent_response_id, 'reply' => $reply->id, 'inline' => 1])
                    : null,
                'created_at' => $iso($reply->created_at ?? null),
            ])->values()->all(),
            'inspectionAssignees' => collect($inspectionAssignees)->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name])->values()->all(),
            'inspectionRequests' => collect($inspectionRequests)->map(fn ($req) => [
                'id' => (int) $req->id,
                'subject' => $req->subject ?? null,
                'status' => (string) ($req->status ?? ''),
                'request_date' => $iso($req->request_date ?? null),
                'assigned_inspector_name' => $req->assigned_inspector_name ?? null,
                'created_by_name' => $req->created_by_name ?? null,
            ])->values()->all(),
            'inspectionFindings' => collect($inspectionFindings)->map(fn ($f) => [
                'id' => (int) $f->id,
                'title' => $f->title ?? null,
                'severity' => (string) ($f->severity ?? ''),
                'finding_date' => $iso($f->finding_date ?? null),
                'request_subject' => $f->request_subject ?? null,
                'assigned_inspector_name' => $f->assigned_inspector_name ?? null,
                'recorded_by_name' => $f->recorded_by_name ?? null,
                'accepted' => !empty($f->accepted_at),
                'has_attachment' => !empty($f->attachment_path),
                'attachment_name' => $f->attachment_original_name ?? null,
                'details' => $f->details ?? null,
            ])->values()->all(),
            'euisis' => $this->euisisPanelProps($case, $can('cases.edit')),
            'euisisRecord' => $this->euisisRecordProps($case),
        ];
    }

    /**
     * Flow 5: the grievances an employee attached when filing from the EUISIS
     * employee portal, with the responses and decisions they received there.
     * Null for a case that did not come from EUISIS and carries no record.
     */
    private function euisisRecordProps(object $case): ?array
    {
        $filed = ($case->source ?? null) === 'euisis_employee';
        if (!Schema::hasTable('court_case_euisis_grievances')) {
            return $filed ? ['filed_from_euisis' => true, 'grievances' => []] : null;
        }

        $decode = fn (?string $json): array => array_values(array_filter((array) json_decode((string) $json, true), 'is_array'));
        $grievances = DB::table('court_case_euisis_grievances')
            ->where('court_case_id', $case->id)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'reference' => $row->reference,
                'subject' => $row->subject,
                'category' => $row->category,
                'status' => $row->status,
                'description' => $row->description,
                'submitted_at' => $row->submitted_at ? Carbon::parse($row->submitted_at)->format('Y-m-d H:i') : null,
                'closed_at' => $row->closed_at ? Carbon::parse($row->closed_at)->format('Y-m-d H:i') : null,
                'responses' => array_map(fn (array $r): array => [
                    'body' => (string) ($r['body'] ?? ''),
                    'at' => $r['at'] ?? null,
                ], $decode($row->responses)),
                'decisions' => array_map(fn (array $d): array => [
                    'number' => $d['number'] ?? null,
                    'type' => $d['type'] ?? null,
                    'text' => (string) ($d['text'] ?? ''),
                    'at' => $d['at'] ?? null,
                ], $decode($row->decisions)),
            ])
            ->values()
            ->all();

        if (!$filed && $grievances === []) {
            return null;
        }

        return ['filed_from_euisis' => $filed, 'grievances' => $grievances];
    }

    /** The EUISIS panel the Blade page rendered through <x-euisis.case-panel>; null when it would not render. */
    private function euisisPanelProps(object $case, bool $canLink): ?array
    {
        try {
            $panel = new \Modules\Court\Http\View\Components\Euisis\CasePanel((int) $case->id);
        } catch (\Throwable $e) {
            Log::warning('EUISIS case panel unavailable', ['case' => $case->id, 'error' => $e->getMessage()]);

            return null;
        }
        if (!$panel->shouldRender()) {
            return null;
        }

        $referral = $panel->referral;
        $organization = $panel->organization;
        $employee = $panel->employee;
        $hasApplicant = ($panel->case?->applicant_id ?? null) !== null;
        $lastRun = $panel->lastRun;

        return [
            'referral' => $referral ? [
                'grievance_reference' => $referral->grievance_reference ?? null,
                'tribunal_case_number' => $referral->tribunal_case_number ?? null,
                'complainant_name' => $referral->complainant_name ?? null,
                'complainant_contact' => collect([$referral->complainant_phone ?? null, $referral->complainant_email ?? null])->filter()->implode(' · ') ?: null,
                'referring_officer' => $referral->referring_officer ?? null,
                'received_at' => $referral->created_at?->format('Y-m-d H:i'),
            ] : null,
            'organization' => $organization ? [
                'name' => $organization->displayName(),
                'code' => $organization->code ?? null,
                'active' => $organization->isActive(),
                'status' => $organization->status ?? null,
            ] : null,
            'employee' => $employee ? [
                'name' => $employee->displayName(),
                'number' => $employee->employee_number ?? null,
                'status' => $employee->status ?? null,
                'position' => $panel->position?->displayTitle(),
                'organization' => $panel->employeeOrganization?->displayName(),
            ] : null,
            // Linked (Flow 4/5) although the directory cache does not have the record yet.
            'employee_uuid' => $panel->employeeUuid,
            'has_applicant' => $hasApplicant,
            'can_link' => $canLink,
            'can_link_employee' => $canLink && $panel->enabled && ($hasApplicant || $referral !== null),
            'enabled' => $panel->enabled,
            'has_organizations' => $panel->hasOrganizations,
            'organizations' => ($canLink && $panel->hasOrganizations)
                ? $panel->organizations()->map(fn ($option) => [
                    'uuid' => (string) $option->uuid,
                    'name' => $option->displayName(),
                    'active' => $option->isActive(),
                    'status' => $option->status ?? null,
                ])->values()->all()
                : [],
            'selected_organization_uuid' => $organization?->uuid,
            'last_sync' => $lastRun ? Carbon::parse($lastRun)->diffForHumans() : null,
            'last_error' => $panel->lastError,
            'notice' => session('euisis_status'),
        ];
    }

    /** A date value from the database as ISO 8601, or null when it is empty or unparseable. */
    private function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Registrar/Reviewer: accept, return for correction, or reject a submitted case.
     */
    public function reviewDecision(Request $request, int $id)
    {
        $case = DB::table('court_cases')->where('id', $id)->first();
        abort_if(!$case, 404, 'Case not found.');
        $this->ensureCaseAccess($case);

        $data = $request->validate([
            'decision' => ['required', 'in:accept,return,reject'],
            'note'     => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = $data['decision'];
        $note     = trim((string) ($data['note'] ?? ''));

        if (in_array($decision, ['return', 'reject'], true) && $note === '') {
            return back()->withErrors(['note' => __('court::messages.error.return_note_required')])->withInput();
        }

        $newStatus = match ($decision) {
            'accept' => 'accepted',
            'return' => 'returned', // needs applicant correction
            'reject' => 'rejected',
        };

        $updatePayload = [
            'review_status'       => $newStatus,
            'review_note'         => $note !== '' ? $note : null,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at'         => now(),
            'updated_at'          => now(),
        ];

        // When review is accepted from "Review Actions", activate the case automatically.
        if ($decision === 'accept') {
            $updatePayload['status'] = 'active';
        }

        DB::table('court_cases')->where('id', $id)->update($updatePayload);

        // The activation is a status change like any other: it goes on the case's status timeline.
        if (isset($updatePayload['status']) && (string) $case->status !== $updatePayload['status']) {
            DB::table('court_case_status_logs')->insert([
                'case_id'            => $id,
                'from_status'        => $case->status,
                'to_status'          => $updatePayload['status'],
                'changed_by_user_id' => Auth::id(),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        $this->logCaseAudit($id, 'review_decision', [
            'decision' => $newStatus,
            'note'     => $note ?: null,
        ]);

        $this->notifyApplicantOfReview($case, $newStatus, $note);
        ResponseNotificationService::notifyCaseReviewDecision($id, $newStatus, $note);

        return back()->with('success', [
            'key' => 'court::messages.success.review_updated_to',
            'replace' => ['status' => __('court::messages.review_status_values.' . $newStatus)],
        ]);
    }

    /**
     * POST alias (legacy) for reviewDecision; expects case_id/id in the payload.
     */
    public function review(Request $request)
    {
        $caseId = $request->integer('case_id') ?: $request->integer('id');
        abort_if(!$caseId, 400, 'Case id is required.');

        return $this->reviewDecision($request, $caseId);
    }

    /**
     * Admin: accept or return a respondent response with an optional reason note.
     */
    public function reviewRespondentResponse(Request $request, int $responseId)
    {
        $response = RespondentResponse::find($responseId);
        abort_if(!$response, 404, __('court::respondent.response_not_found'));
        $responseCase = DB::table('court_cases')->where('case_number', $response->case_number)->first();
        abort_if(!$responseCase, 404);
        $this->ensureCaseAccess($responseCase);

        $data = $request->validate([
            'decision'    => ['required', 'in:accept,return'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = $data['decision'];
        $note = trim((string) ($data['review_note'] ?? ''));

        if ($decision === 'return' && $note === '') {
            return back()->withErrors(['review_note' => __('court::respondent.review_note_required')])->withInput();
        }

        $newStatus = $decision === 'accept' ? 'accepted' : 'returned';

        $response->fill([
            'review_status' => $newStatus,
            'review_note' => $note !== '' ? $note : null,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
        ])->save();

        ResponseNotificationService::notifyRespondentResponseReviewed($response, $newStatus, $note);

        return back()->with('success', __('court::respondent.response_review_updated'));
    }

    /**
     * Admin: accept or return an applicant response-of-response.
     */
    public function reviewApplicantResponseReply(Request $request, int $replyId)
    {
        $reply = ApplicantResponseReply::find($replyId);
        abort_if(!$reply, 404, __('court::respondent.response_reply_not_found'));
        $replyCase = DB::table('court_cases')->where('id', $reply->case_id)->first();
        abort_if(!$replyCase, 404);
        $this->ensureCaseAccess($replyCase);

        $data = $request->validate([
            'decision'    => ['required', 'in:accept,return'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = $data['decision'];
        $note = trim((string) ($data['review_note'] ?? ''));

        if ($decision === 'return' && $note === '') {
            return back()->withErrors(['review_note' => __('court::respondent.response_reply_note_required')])->withInput();
        }

        $newStatus = $decision === 'accept' ? 'accepted' : 'returned';

        $reply->fill([
            'review_status' => $newStatus,
            'review_note' => $note !== '' ? $note : null,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
        ])->save();

        $this->logCaseAudit((int) $reply->case_id, 'response_reply_reviewed', [
            'reply_id' => $replyId,
            'decision' => $newStatus,
            'note' => $note !== '' ? $note : null,
        ]);

        ResponseNotificationService::notifyResponseReplyReviewed($reply, $newStatus, $note);

        return back()->with('success', __('court::respondent.response_reply_review_updated'));
    }

    /**
     * Admin: update case status + log timeline + email applicant.
     */
    public function updateStatus(Request $request, int $id)
    {
        $allowed = ['pending', 'active', 'adjourned', 'dismissed', 'closed'];

        $data = $request->validate([
            'status' => ['required', 'in:' . implode(',', $allowed)],
            'note'   => ['nullable', 'string', 'max:2000'],
        ]);

        $case = DB::table('court_cases')->where('id', $id)->first();
        abort_if(!$case, 404);
        $this->ensureCaseAccess($case);

        $old = $case->status;
        $new = $data['status'];

        if ($old === 'closed' && $new !== $old) {
            throw ValidationException::withMessages([
                'status' => __('court::cases.status.closed_locked'),
            ]);
        }

        if ($old === $new) {
            return back()->with('success', [
                'key' => 'court::messages.success.unchanged',
                'replace' => [
                    'resource' => __('court::messages.resources.case'),
                    'value' => __('court::cases.status.' . $new),
                ],
            ]);
        }

        $updated = DB::table('court_cases')
            ->where('id', $id)
            ->where('status', $old)
            ->where('status', '!=', 'closed')
            ->update([
                'status'     => $new,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            $currentStatus = DB::table('court_cases')->where('id', $id)->value('status');

            throw ValidationException::withMessages([
                'status' => $currentStatus === 'closed'
                    ? __('court::cases.status.closed_locked')
                    : __('court::cases.status.changed_retry'),
            ]);
        }

        // Email applicant (best-effort) — uses applicant_notification_prefs
        try {
            if (!empty($case->applicant_id)) {
                $to = DB::table('court_applicants')->where('id', $case->applicant_id)->value('email');
                if ($to) {
                    $prefs = DB::table('court_applicant_notification_prefs')->where('applicant_id', $case->applicant_id)->first();
                    $wants = !$prefs || ($prefs->email_status ?? true);

                    if ($wants) {
                        Mail::to($to)->send(
                            new \Modules\Court\Application\Mail\CaseStatusChangedMail($case, $old, $new, $data['note'] ?? null)
                        );
                    }
                } else {
                    Log::info('Status mail skipped (no applicant email)', ['case_id' => $case->id]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed sending case status email', ['case_id' => $case->id, 'error' => $e->getMessage()]);
        }

        // Log timeline
        DB::table('court_case_status_logs')->insert([
            'case_id'            => $id,
            'from_status'        => $old,
            'to_status'          => $new,
            'changed_by_user_id' => Auth::id(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->logCaseAudit($id, 'status_updated', [
            'from' => $old,
            'to'   => $new,
            'note' => $data['note'] ?? null,
        ]);

        // Optional: visible note to thread
        if (!empty($data['note'])) {
            DB::table('court_case_messages')->insert([
                'case_id'             => $id,
                'sender_user_id'      => Auth::id(),
                'sender_applicant_id' => null,
                'body'                => __('court::cases.notifications.status_changed_note', [
                    'status' => __('court::cases.status.' . $new),
                    'note' => $data['note'],
                ]),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        ResponseNotificationService::notifyCaseStatusChanged($id, $old, $new, $data['note'] ?? null);

        return back()->with('success', [
            'key' => 'court::messages.success.status_updated',
            'replace' => [
                'resource' => __('court::messages.resources.case'),
                'status' => __('court::cases.status.' . $new),
            ],
        ]);
    }

    /**
     * Admin → Applicant message (and email).
     */
    public function postAdminMessage(Request $request, int $id)
    {
        $case = $this->writableCase($id, 'message.create');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        DB::table('court_case_messages')->insert([
            'case_id'             => $id,
            'sender_user_id'      => Auth::id(),
            'sender_applicant_id' => null,
            'body'                => $data['body'],
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // Notify applicant by email (best-effort)
        try {
            if (!empty($case->applicant_id)) {
                $to = DB::table('court_applicants')->where('id', $case->applicant_id)->value('email');
                if ($to) {
                    $prefs = DB::table('court_applicant_notification_prefs')->where('applicant_id', $case->applicant_id)->first();
                    $wants = !$prefs || ($prefs->email_message ?? true);

                    if ($wants) {
                        $preview = mb_strimwidth($data['body'], 0, 180, '…');
                        Mail::to($to)->send(new \Modules\Court\Application\Mail\CaseMessageMail($case, __('court::cases.court_staff'), $preview));
                    }
                } else {
                    Log::info('Message mail skipped (no applicant email)', ['case_id' => $id]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Admin message email failed', ['case_id' => $id, 'error' => $e->getMessage()]);
        }

        $this->logCaseAudit($id, 'message_posted', [
            'by'   => 'admin',
            'body' => mb_strimwidth($data['body'], 0, 200, '...'),
        ]);

        ResponseNotificationService::notifyCaseMessagePosted($id, __('court::cases.court_staff'), $data['body']);

        return back()->with('success', [
            'key' => 'court::messages.success.sent',
            'replace' => ['resource' => __('court::messages.resources.message')],
        ]);
    }

    /**
     * Admin: create hearing and email applicant.
     */
    public function storeHearing(Request $request, int $case)
    {
        $c = $this->writableCase($case, 'hearing.create');

        $data = $request->validate([
            'hearing_at' => ['required', 'date'], // expect normalized datetime string (Y-m-d H:i:s)
            'type'       => ['nullable', 'string', 'max:255'],
            'location'   => ['nullable', 'string', 'max:255'],
            'notes'      => ['nullable', 'string'],
        ]);
        $hearingDate = Carbon::parse($data['hearing_at'])->toDateString();
        $alreadyExists = DB::table('court_case_hearings')
            ->where('case_id', $case)
            ->whereDate('hearing_at', $hearingDate)
            ->exists();
        if ($alreadyExists) {
            return back()
                ->withErrors(['hearing_at' => __('court::messages.error.hearing_duplicate_date')])
                ->withInput();
        }

        $hearingId = DB::table('court_case_hearings')->insertGetId([
            'case_id'            => $case,
            'hearing_at'         => $data['hearing_at'],
            'type'               => $data['type'] ?? null,
            'location'           => $data['location'] ?? null,
            'notes'              => $data['notes'] ?? null,
            'created_by_user_id' => Auth::id(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->logCaseAudit($case, 'hearing_created', [
            'hearing_id' => $hearingId,
            'when'       => $data['hearing_at'],
        ]);

        // Email applicant (best-effort)
        try {
            if (!empty($c->applicant_id)) {
                $to = DB::table('court_applicants')->where('id', $c->applicant_id)->value('email');
                if ($to) {
                    $prefs = DB::table('court_applicant_notification_prefs')->where('applicant_id', $c->applicant_id)->first();
                    $wants = !$prefs || ($prefs->email_hearing ?? true);

                    if ($wants) {
                        $hearing = DB::table('court_case_hearings')->where('id', $hearingId)->first();
                        Mail::to($to)->send(new \Modules\Court\Application\Mail\CaseHearingScheduledMail($c, $hearing));
                    }
                } else {
                    Log::info('Hearing mail skipped (no applicant email)', ['case_id' => $case]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Hearing mail failed', ['case_id' => $case, 'error' => $e->getMessage()]);
        }

        ResponseNotificationService::notifyCaseHearingCreated($hearingId);

        return back()->with('success', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.hearing')],
        ]);
    }

    /**
     * Admin: update hearing.
     */
    public function updateHearing(Request $request, int $hearing)
    {
        $h = DB::table('court_case_hearings')->where('id', $hearing)->first();
        abort_if(!$h, 404);
        $this->writableCase((int) $h->case_id, 'hearing.update');
        $existingHearingDate = Carbon::parse($h->hearing_at)->startOfDay();
        if ($existingHearingDate->lte(Carbon::today())) {
            return back()->with('error', 'court::messages.error.past_hearing_edit_locked');
        }

        $data = $request->validate([
            'hearing_at' => ['required', 'date'],
            'type'       => ['nullable', 'string', 'max:255'],
            'location'   => ['nullable', 'string', 'max:255'],
            'notes'      => ['nullable', 'string'],
        ]);
        $hearingDate = Carbon::parse($data['hearing_at'])->toDateString();
        $alreadyExists = DB::table('court_case_hearings')
            ->where('case_id', $h->case_id)
            ->whereDate('hearing_at', $hearingDate)
            ->where('id', '!=', $hearing)
            ->exists();
        if ($alreadyExists) {
            return back()
                ->withErrors(['hearing_at' => __('court::messages.error.hearing_duplicate_date')])
                ->withInput();
        }

        DB::table('court_case_hearings')->where('id', $hearing)->update([
            'hearing_at' => $data['hearing_at'],
            'type'       => $data['type'] ?? null,
            'location'   => $data['location'] ?? null,
            'notes'      => $data['notes'] ?? null,
            'updated_at' => now(),
        ]);

        $this->logCaseAudit($h->case_id, 'hearing_updated', [
            'hearing_id' => $hearing,
            'when'       => $data['hearing_at'],
        ]);

        ResponseNotificationService::notifyCaseHearingUpdated($hearing);

        return back()->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.hearing')],
        ]);
    }

    /**
     * Admin: delete hearing.
     */
    public function deleteHearing(int $hearing)
    {
        $row = DB::table('court_case_hearings')->where('id', $hearing)->first();
        abort_if(!$row, 404);
        $this->writableCase((int) $row->case_id, 'hearing.delete');
        $existingHearingDate = Carbon::parse($row->hearing_at)->startOfDay();
        if ($existingHearingDate->lte(Carbon::today())) {
            return back()->with('error', 'court::messages.error.past_hearing_delete_locked');
        }

        $caseId = $row->case_id;
        DB::table('court_case_hearings')->where('id', $hearing)->delete();
        if ($caseId) {
            $this->logCaseAudit($caseId, 'hearing_deleted', ['hearing_id' => $hearing]);
        }
        ResponseNotificationService::notifyCaseHearingDeleted($row);

        return back()->with('success', [
            'key' => 'court::messages.success.removed',
            'replace' => ['resource' => __('court::messages.resources.hearing')],
        ]);
    }

    /**
     * Uploaded Files (Evidence merged here) — store.
     */
    public function storeFile(Request $request, int $case, SecureUploadService $uploads)
    {
        $this->writableCase($case, 'file.create');

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'file'  => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'], // 10MB
        ]);

        $stored = $uploads->store($request->file('file'), 'case_files', 'court_private', [
            'related_type' => 'court_case',
            'related_id' => $case,
            'user_id' => Auth::id(),
        ]);

        DB::table('court_case_files')->insert([
            'case_id'                   => $case,
            'label'                     => $data['label'] ?? null,
            'path'                      => $stored,
            'mime'                      => $request->file('file')->getClientMimeType(),
            'size'                      => $request->file('file')->getSize(),
            'uploaded_by_user_id'       => Auth::id(),
            'uploaded_by_applicant_id'  => null,
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);

        $this->logCaseAudit($case, 'file_uploaded', [
            'label' => $data['label'] ?? null,
            'path'  => $stored,
        ]);

        return back()->with('success', [
            'key' => 'court::messages.success.uploaded',
            'replace' => ['resource' => __('court::messages.resources.file')],
        ]);
    }

    /**
     * Uploaded Files — delete.
     */
    public function deleteFile(int $case, int $file, LegalHoldService $legalHolds)
    {
        $this->writableCase($case, 'file.delete');
        $row =DB::table('court_case_files')->where('id', $file)->where('case_id', $case)->first();
        abort_if(!$row, 404);

        $legalHolds->assertFileMayBeDeleted((string) $row->path);

        if (!empty($row->path)) {
            $this->deleteStoredFile($row->path);
        }
        DB::table('court_case_files')->where('id', $file)->delete();

        $this->logCaseAudit($row->case_id, 'file_deleted', [
            'file_id' => $file,
            'label'   => $row->label ?? null,
        ]);

        return back()->with('success', [
            'key' => 'court::messages.success.removed',
            'replace' => ['resource' => __('court::messages.resources.file')],
        ]);
    }

    /**
     * View a submitted document (case_evidences).
     */
    public function viewDocument(int $caseId, int $docId)
    {
        $this->authorizeView();

        // A submitted document is part of the case file: same team scope as the case page.
        $case = DB::table('court_cases')->where('id', $caseId)->first();
        abort_if(!$case, 404, 'Case not found.');
        $this->ensureCaseAccess($case);

        $doc = DB::table('court_case_evidences')
            ->where('id', $docId)
            ->where('case_id', $caseId)
            ->first();

        abort_if(!$doc, 404, 'Document not found.');

        $filePath = $doc->file_path ?? $doc->path ?? null;
        abort_if(!$filePath, 404, 'Document file missing.');

        $disk = $this->resolveFileDisk($filePath);
        abort_if(!$disk, 404, 'Stored file missing.');

        $downloadName = ($doc->title ?? basename($filePath)) ?: basename($filePath);
        $mime = $doc->mime ?? $disk->mimeType($filePath) ?? 'application/octet-stream';

        return $disk->response($filePath, $downloadName, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . basename($downloadName) . '"',
        ]);
    }

    private function resolveFileDisk(string $path): ?\Illuminate\Contracts\Filesystem\Filesystem
    {
        $private = Storage::disk('court_private');
        if ($private->exists($path)) {
            return $private;
        }

        $public = Storage::disk('court_public');
        if ($public->exists($path)) {
            return $public;
        }

        return null;
    }

    private function deleteStoredFile(string $path): void
    {
        $disk = $this->resolveFileDisk($path);
        if ($disk) {
            $disk->delete($path);
        }
    }

    /**
     * Witnesses — create.
     */
    public function storeWitness(Request $request, int $case)
    {
        $this->writableCase($case);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone'     => ['nullable', 'string', 'max:60'],
            'email'     => ['nullable', 'email', 'max:150'],
            'address'   => ['nullable', 'string', 'max:255'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ]);

        DB::table('court_case_witnesses')->insert([
            'case_id'            => $case,
            'full_name'          => $data['full_name'],
            'phone'              => $data['phone'] ?? null,
            'email'              => $data['email'] ?? null,
            'address'            => $data['address'] ?? null,
            'notes'              => $data['notes'] ?? null,
            'created_by_user_id' => Auth::id(),
            'updated_by_user_id' => Auth::id(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        return back()->with('success', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.witness')],
        ]);
    }

    /**
     * Witnesses — update.
     */
    public function updateWitness(Request $request, int $case, int $witness)
    {
        $this->writableCase($case);
        $exists =DB::table('court_case_witnesses')
            ->where('id', $witness)
            ->where('case_id', $case)
            ->exists();
        abort_if(!$exists, 404);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone'     => ['nullable', 'string', 'max:60'],
            'email'     => ['nullable', 'email', 'max:150'],
            'address'   => ['nullable', 'string', 'max:255'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ]);

        DB::table('court_case_witnesses')->where('id', $witness)->update([
            'full_name'          => $data['full_name'],
            'phone'              => $data['phone'] ?? null,
            'email'              => $data['email'] ?? null,
            'address'            => $data['address'] ?? null,
            'notes'              => $data['notes'] ?? null,
            'updated_by_user_id' => Auth::id(),
            'updated_at'         => now(),
        ]);

        return back()->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.witness')],
        ]);
    }

    /**
     * Witnesses — delete.
     */
    public function deleteWitness(int $case, int $witness)
    {
        $this->writableCase($case);
        DB::table('court_case_witnesses')
            ->where('id', $witness)
            ->where('case_id', $case)
            ->delete();

        return back()->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.witness')],
        ]);
    }

    /**
     * Team leaders only reach their team's cases and plain team members only
     * the cases assigned to them; everyone else with cases.view sees all.
     */
    /** Team leaders reach their team's cases, plain team members only theirs (CaseScope). */
    private function ensureCaseAccess(object $case): void
    {
        \Modules\Court\Application\Support\CaseScope::ensure($case);
    }

    /** A published decision on a closed case freezes the file. */
    private function isCaseLocked(object $case): bool
    {
        return \Modules\Court\Application\Support\CaseScope::isLocked($case);
    }

    /**
     * Case a staff member may change: it exists, is within their scope, is not
     * locked by a published decision, and (when given) they hold $permission.
     * The page hides these actions already; this is the server-side check.
     */
    private function writableCase(int $caseId, ?string $permission = null): object
    {
        $case = DB::table('court_cases')->where('id', $caseId)->first();
        abort_if(!$case, 404);
        abort_if($permission !== null && !(Auth::user()?->hasPermission($permission) ?? false), 403);
        $this->ensureCaseAccess($case);

        if ($this->isCaseLocked($case)) {
            throw new HttpResponseException(back()->with('error', __('court::cases.show.actions_locked')));
        }

        return $case;
    }

    /**
     * Gate for admin view (uses your helper).
     */
    private function authorizeView(): void
    {
        if (!\Modules\Court\Application\Support\CourtPermissions::check('cases.view')) {
            abort(403, 'You do not have permission: cases.view');
        }
    }

    private function teamLeaderAssignmentIds(?\Modules\Court\Domain\Models\CourtStaffMember $user): array
    {
        if (!$user) {
            return [];
        }

        $isLeader = $user->hasPermission('cases.assign.member');
        $hasAdminAssign = $user->hasPermission('cases.assign.team');

        if (!$isLeader || $hasAdminAssign) {
            return [];
        }

        $leaderTeam = Team::with(['users' => fn ($q) => $q->where('status', 'active')->orderBy('name')])
            ->where('team_leader_id', $user->id)
            ->first();

        $ids = collect([$user->id]);

        if ($leaderTeam) {
            $ids = $ids->merge($leaderTeam->users->pluck('id'));
        }

        return $ids->filter()->unique()->values()->all();
    }

    /**
     * Send a review decision note to the applicant (message + optional email).
     */
    private function notifyApplicantOfReview(object $case, string $reviewStatus, ?string $note): void
    {
        if (empty($case->applicant_id)) {
            return;
        }

        $decisionText = match ($reviewStatus) {
            'accepted' => __('court::cases.review_status.accepted'),
            'returned' => __('court::cases.review_status.returned'),
            'rejected' => __('court::cases.review_status.rejected'),
            default    => $reviewStatus,
        };

        $body = __('court::cases.notifications.case_reviewed', [
            'case' => $case->case_number,
            'decision' => $decisionText,
        ]);
        if ($note !== '') {
            $body .= "\n\n" . __('court::cases.notifications.reviewer_notes') . ":\n{$note}";
        }

        DB::table('court_case_messages')->insert([
            'case_id'             => $case->id,
            'sender_user_id'      => Auth::id(),
            'sender_applicant_id' => null,
            'body'                => $body,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // Avoid blocking review submissions when SMTP config is incomplete.
        $mailer = (string) config('mail.default');
        $fromAddress = (string) config('mail.from.address');
        $smtpHost = (string) config('mail.mailers.smtp.host');
        $smtpUser = (string) config('mail.mailers.smtp.username');
        $smtpPass = (string) config('mail.mailers.smtp.password');
        if ($mailer === 'smtp' && ($fromAddress === '' || $smtpHost === '' || $smtpUser === '' || $smtpPass === '')) {
            Log::warning('Review email skipped due to incomplete SMTP config', ['case_id' => $case->id]);
            return;
        }

        try {
            $to = DB::table('court_applicants')->where('id', $case->applicant_id)->value('email');
            if ($to) {
                $prefs = DB::table('court_applicant_notification_prefs')->where('applicant_id', $case->applicant_id)->first();
                $wants = !$prefs || ($prefs->email_message ?? true);

                if ($wants) {
                    $preview = mb_strimwidth($body, 0, 180, '...');
                    Mail::to($to)->send(new \Modules\Court\Application\Mail\CaseMessageMail($case, __('court::cases.court_staff'), $preview));
                }
            } else {
                Log::info('Review note email skipped (no applicant email)', ['case_id' => $case->id]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed sending review decision email', ['case_id' => $case->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The audit trail as the case page shows it: each entry's recorded details as labelled
     * lines in the reader's language, ids resolved to the bench and user names, statuses and
     * decisions translated, the hearing time as a date the page formats. `meta` keeps the raw
     * record for an action the labels do not know yet.
     *
     * @return list<array<string, mixed>>
     */
    private function auditRows($audits, callable $iso): array
    {
        $metas = collect($audits)->mapWithKeys(fn ($a) => [(int) $a->id => is_array($m = json_decode((string) ($a->meta ?? ''), true)) ? $m : []]);
        $ids = fn (string ...$keys) => $metas->flatMap(fn (array $m) => array_map(fn ($k) => $m[$k] ?? null, $keys))->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values();
        $teams = Schema::hasTable('court_teams') ? DB::table('court_teams')->whereIn('id', $ids('assigned_team_id'))->pluck('name', 'id') : collect();
        $users = DB::table('court_staff')->whereIn('id', $ids('assigned_user_id', 'assigned_member_user_id'))->pluck('name', 'id');
        $label = fn (string $key) => __("court::case_audit.fields.{$key}");
        $known = fn (string $group, $value) => ($text = __("{$group}.{$value}")) !== "{$group}.{$value}" ? $text : (string) $value;
        $blank = fn ($value) => $value === null || $value === '' || $value === 'NULL';

        return collect($audits)->map(function ($a) use ($iso, $metas, $teams, $users, $label, $known, $blank) {
            $meta = $metas[(int) $a->id];
            $details = [];
            foreach ($meta as $key => $value) {
                if ($blank($value) || is_array($value) || in_array($key, ['path', 'hearing_id'], true)) {
                    continue;
                }
                $line = match ($key) {
                    'assigned_team_id' => [$label('team'), $teams[(int) $value] ?? "#{$value}"],
                    'assigned_user_id' => [$label('judge'), $users[(int) $value] ?? "#{$value}"],
                    'assigned_member_user_id' => [$label('member'), $users[(int) $value] ?? "#{$value}"],
                    'from', 'to' => [$label($key), $known('court::cases.status', $value)],
                    'decision' => [$label('decision'), $known('court::cases.review_status', $value)],
                    'by' => [$label('by'), $known('court::case_audit.by', $value)],
                    'when' => [$label('when'), (string) $value, 'date'],
                    'respondent', 'note', 'body', 'label', 'sections' => [$label($key), (string) $value],
                    default => [Str::headline((string) $key), (string) $value],
                };
                $details[] = ['label' => $line[0], 'value' => $line[1], 'kind' => $line[2] ?? 'text'];
            }

            return [
                'id' => (int) $a->id,
                'action' => (string) ($a->action ?? ''),
                'actor_type' => $a->actor_type ?? null,
                'actor_id' => $a->actor_id ?? null,
                'actor_name' => $a->actor_name ?? null,
                'details' => $details,
                'meta' => $meta !== [] ? json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'created_at' => $iso($a->created_at ?? null),
            ];
        })->values()->all();
    }

    /**
     * Record a case audit trail entry.
     */
    private function logCaseAudit(int $caseId, string $action, array $meta = []): void
    {
        try {
            DB::table('court_case_audits')->insert([
                'case_id'    => $caseId,
                'action'     => $action,
                'actor_type' => Auth::check() ? 'user' : 'system',
                'actor_id'   => Auth::id(),
                'meta'       => empty($meta) ? null : json_encode($meta),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log case audit', ['case_id' => $caseId, 'action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
