<?php

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;

use Modules\Court\Domain\Models\ApplicantTermAcceptance;
use Modules\Court\Domain\Models\TermsAndCondition;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Modules\Court\Application\Services\CaseParties;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Court\Application\Mail\CaseMessageMail;
use Modules\Court\Application\Mail\ApplicantReceiptMail;
use Modules\Court\Application\Services\ResponseNotificationService;
use Modules\Court\Application\Services\SecureUploadService;
use Modules\Court\Application\Services\LegalHoldService;
use Modules\Court\Application\Support\Purifier;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\EuisisOrganization;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class ApplicantCaseController extends Controller
{
    /**
     * List only the signed-in applicant's cases
     */
    public function index(Request $request)
    {
        $me = auth('applicant')->id();

        $cases = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')

            ->select(
                'c.id',
                'c.case_number',
                'c.title',
                'c.status',
                'c.review_status',
                'c.review_note',
                'c.reviewed_at',
                'c.filing_date',
                'ct.name as case_type',

            )
            // Always the signed-in applicant's own cases (a schema check used to list every case when it failed).
            ->whereIn('c.id', CaseParties::caseIdsFor((int) $me))
            ->orderByDesc('c.created_at')
            ->paginate(10);

        // React page Court/Applicant/Cases/Index; a case without a review state reads as accepted, as on the Blade list.
        return Inertia::render('Court/Applicant/Cases/Index', [
            'cases' => $cases->through(fn (object $c): array => [
                'id' => (int) $c->id,
                'case_number' => (string) $c->case_number,
                'title' => $c->title,
                'case_type' => $c->case_type,
                'status' => (string) $c->status,
                'review_status' => (string) ($c->review_status ?? 'accepted'),
                'review_note' => $c->review_note,
                'filing_date' => $this->isoDay($c->filing_date ?? null),
                'can_delete' => $c->status === 'pending' && ($c->review_status ?? null) !== 'accepted',
            ]),
        ]);
    }

    /**
     * New case form
     */
    public function create()
    {
        // With EUISIS parties only, employees file from the EUISIS employee portal (Flow 5).
        if (\Modules\Court\Application\Support\EuisisParties::only()) {
            return redirect()->route('court.applicant.cases.index')->with('info', __('court::cases.file_in_euisis'));
        }

        $types  = DB::table('court_case_types')->orderBy('name')->get(['id', 'name', 'prefix']);
        $activeTerms = TermsAndCondition::published()->orderByDesc('published_at')->first();
        $applicant = auth('applicant')->user();

        // The respondent organization picker reads the EUISIS city directory (docs/euisis-integration.md), as the Blade form did.
        $organizations = Schema::hasTable('court_euisis_organizations')
            ? EuisisOrganization::query()
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
                ->orderBy('name_en')
                ->get(['uuid', 'name_en', 'name_am', 'status'])
                ->map(fn (EuisisOrganization $org): array => ['uuid' => (string) $org->uuid, 'name' => $org->displayName()])
                ->values()
                ->all()
            : [];

        return Inertia::render('Court/Applicant/Cases/Create', [
            'types' => $this->caseTypeOptions($types),
            'terms' => $activeTerms ? [
                'id' => (int) $activeTerms->id,
                'body_html' => \Modules\Court\Application\Support\Purifier::clean(nl2br(e((string) $activeTerms->body)), 'cases'),
            ] : null,
            'applicant' => [
                'is_lawyer' => (bool) ($applicant?->is_lawyer),
                'name' => (string) ($applicant->full_name ?? $applicant->name ?? ''),
                'address' => (string) ($applicant->address ?? ''),
            ],
            'organizations' => $organizations,
            'today' => now()->toDateString(),
        ]);
    }

    private function generateCaseNumber($caseTypeId)
    {
        // Build a short prefix from the case type name (no separate column required)
        $caseType = \Modules\Court\Domain\Models\CaseType::findOrFail($caseTypeId);
        $prefixBase = (string) ($caseType->prifix ?? $caseType->prefix ?? $caseType->name ?? 'CASE');
        // Keep letters/numbers from any locale, strip spaces/punctuation, take first 4 chars
        $clean = preg_replace('/[\s\p{P}]+/u', '', $prefixBase);
        $prifix = mb_strtoupper(mb_substr($clean ?: 'CASE', 0, 4)) ?: 'CASE';

        $year = now()->format('y'); // YY format

        // Lock rows for this prefix/year while computing the next sequence to avoid duplicates on concurrent requests.
        $maxSeq = DB::table('court_cases')
            ->where('case_number', 'LIKE', "{$prifix}/%/{$year}")
            ->lockForUpdate()
            ->selectRaw("MAX(".\Modules\Court\Application\Support\Sql::middleSegmentNumber('case_number').") as max_seq")
            ->value('max_seq');

        $nextNumber = ((int) $maxSeq) + 1;

        $sequence = str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        return "{$prifix}/{$sequence}/{$year}";
    }



    private function generateCaseCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $exists = DB::table('court_cases')->where('code', $code)->exists();
        } while ($exists);

        return $code;
    }

    /**
     * Store new case (supports evidence PDFs & witness names/details)
     * NOTE: Sanitizes TinyMCE HTML fields on save.
     */
    public function store(Request $request)
    {
        $aid = auth('applicant')->id();
        abort_if(!$aid, 403);

        // With EUISIS parties only, employees file from the EUISIS employee portal (Flow 5).
        if (\Modules\Court\Application\Support\EuisisParties::only()) {
            return redirect()->route('court.applicant.cases.index')->with('info', __('court::cases.file_in_euisis'));
        }

        $activeTerms = TermsAndCondition::published()->orderByDesc('published_at')->first();

        $rules = [
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'relief_requested'   => ['required', 'string'],
            'certify_appeal'     => ['accepted'],
            'respondent_name'    => ['required', 'string', 'max:255'],
            'respondent_address' => ['required', 'string', 'max:500'],
            // Optional: the respondent organization from the EUISIS city directory.
            'respondent_organization_uuid' => ['nullable', 'uuid', 'exists:court_euisis_organizations,uuid'],
            'case_type_id'       => ['required', 'integer', 'exists:court_case_types,id'],

            'filing_date'        => ['required', 'date'],
            'first_hearing_date' => ['nullable', 'date'],

            // initial evidence & witnesses
            'evidence_titles'    => ['required', 'array', 'min:1'],
            'evidence_titles.*'  => ['required', 'string', 'max:255'],
            'evidence_files'     => ['required', 'array', 'min:1'],
            'evidence_files.*'   => ['required', 'file', 'mimes:pdf', 'max:5120'],

            'witnesses'              => ['required', 'array', 'min:1'],
            'witnesses.*.full_name'  => ['required', 'string', 'max:255'],
            'witnesses.*.phone'      => ['required', 'string', 'max:60'],
            'witnesses.*.address'    => ['required', 'string', 'max:255'],
            'certify_evidence'       => ['accepted'],
        ];

        if ($activeTerms) {
            $rules['accept_terms'] = ['accepted'];
        }

        $messages = [
            'certify_appeal.accepted' => 'You must certify the validity of your appeal.',
            'certify_evidence.accepted' => 'You must certify that your evidence is true.',
            'accept_terms.accepted' => __('court::terms.validation_accept_terms'),
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        $validator->after(function ($validator) use ($request) {
            $witnesses = collect($request->input('witnesses', []));

            $phones = $witnesses->pluck('phone')->filter(fn($phone) => filled($phone))
                ->map(fn($phone) => mb_strtolower(trim($phone)));
            if ($phones->count() !== $phones->unique()->count()) {
                $validator->errors()->add('witnesses_duplicate_phone', 'Witness phone numbers must be unique.');
            }

            // witness email removed from form; no email validation needed here
        });

        $data = $validator->validate();

        // Sanitize TinyMCE HTML (decode if entity-encoded, then Purifier 'cases' profile)
        $descHtml   = $this->cleanHtml($data['description'] ?? '');
        $reliefHtml = $this->cleanHtml($data['relief_requested'] ?? '');

        DB::beginTransaction();

        try {
            // 1) Generate locked case number inside the transaction to avoid duplicates
            $finalNumber = $this->generateCaseNumber($data['case_type_id']);

            $caseId = DB::table('court_cases')->insertGetId([
                'applicant_id'       => $aid,
                'respondent_name'    => isset($data['respondent_name']) ? strip_tags($data['respondent_name']) : null,
                'respondent_address' => isset($data['respondent_address']) ? strip_tags($data['respondent_address']) : null,
                'respondent_organization_uuid' => $data['respondent_organization_uuid'] ?? null,
                'case_number'        => $finalNumber,
                'code'               => $this->generateCaseCode(),
                'title'              => trim($data['title']),
                'description'        => $descHtml,      // sanitized HTML
                'case_type_id'       => $data['case_type_id'],

                'relief_requested'   => $reliefHtml ?: null, // sanitized HTML
                'filing_date'        => $data['filing_date'],
                'first_hearing_date' => $data['first_hearing_date'] ?? null,
                'status'             => 'pending',
                'review_status'      => 'awaiting_review',
                'assigned_user_id'   => null,
                'assigned_at'        => null,
                'notes'              => $data['notes'] ?? null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            // The applicant is the case's lead party (Modules\Court\Application\Services\CaseParties).
            app(CaseParties::class)->ensureLead((int) $caseId);

            // 2) Initial status log
            DB::table('court_case_status_logs')->insert([
                'case_id'            => $caseId,
                'from_status'        => null,
                'to_status'          => 'pending',
                'changed_by_user_id' => null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            if ($activeTerms) {
                ApplicantTermAcceptance::updateOrCreate(
                    [
                        'applicant_id' => $aid,
                        'terms_and_condition_id' => $activeTerms->id,
                    ],
                    [
                        'accepted_at' => now(),
                    ]
                );
            }

            // 4) Initial evidence PDFs (ensure mime/size + file_path/path)
            if ($request->hasFile('evidence_files')) {
                $titles = $request->input('evidence_titles', []);
                foreach ($request->file('evidence_files') as $i => $file) {
                    if (!$file) continue;

                    /** @var UploadedFile $file */
                    $stored = app(SecureUploadService::class)->store($file, 'evidences', 'court_private', [
                        'related_type' => 'court_case',
                        'related_id' => $caseId,
                        'applicant_id' => $aid,
                    ]);

                    $insert = [
                        'case_id'    => $caseId,
                        'type'       => 'document',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (Schema::hasColumn('court_case_evidences', 'file_path')) {
                        $insert['file_path'] = $stored;
                    } elseif (Schema::hasColumn('court_case_evidences', 'path')) {
                        $insert['path'] = $stored;
                    }

                    if (Schema::hasColumn('court_case_evidences', 'title')) {
                        $insert['title'] = $titles[$i] ?? ('Document ' . ($i + 1));
                    }
                    if (Schema::hasColumn('court_case_evidences', 'description')) {
                        $insert['description'] = null;
                    }

                    if (Schema::hasColumn('court_case_evidences', 'mime')) {
                        $insert['mime'] = $file->getClientMimeType() ?: 'application/pdf';
                    }
                    if (Schema::hasColumn('court_case_evidences', 'size')) {
                        $insert['size'] = $file->getSize();
                    }

                    if (Schema::hasColumn('court_case_evidences', 'uploaded_by_applicant_id')) {
                        $insert['uploaded_by_applicant_id'] = $aid;
                    } elseif (Schema::hasColumn('court_case_evidences', 'uploaded_by_user_id')) {
                        $insert['uploaded_by_user_id'] = $aid;
                    }

                    DB::table('court_case_evidences')->insert($insert);
                }
            }

            // 5) Initial witnesses (from case_witnesses)
            $witnesses = $request->input('witnesses', []);
            foreach ($witnesses as $w) {
                $fullName = trim((string) ($w['full_name'] ?? ''));
                if ($fullName === '') continue;

                DB::table('court_case_witnesses')->insert([
                    'case_id'            => $caseId,
                    'full_name'          => $fullName,
                    'phone'              => $w['phone'] ?? null,
                    'email'              => $w['email'] ?? null,
                    'address'            => $w['address'] ?? null,

                    'created_by_user_id' => null,
                    'updated_by_user_id' => null,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }

            DB::commit();

            ResponseNotificationService::notifyApplicantCaseCreated($caseId);

            return redirect()
                ->route('court.applicant.cases.show', $caseId)
                ->with('success', "Case created successfully. Number: {$finalNumber}");
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return back()->withInput()->with('error', 'Failed to create case. Please try again.');
        }
    }

    /**
     * Show a case the applicant owns
     */
    public function show(int $id)
    {
        $aid = auth('applicant')->id();

        $case = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')

            ->select('c.*', 'ct.name as case_type')
            ->where('c.id', $id)
            ->whereIn('c.id', CaseParties::caseIdsFor((int) $aid))
            ->first();

        abort_if(!$case, 404);

        $timeline = DB::table('court_case_status_logs')
            ->where('case_id', $id)
            ->orderBy('created_at')
            ->get();

        $letters = DB::table('court_letters as l')
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
            ->where('l.approval_status', 'approved')
            ->orderByDesc('l.created_at')
            ->get();

        $respondentResponses = DB::table('court_respondent_responses')
            ->where('case_number', $case->case_number)
            ->where('review_status', 'accepted')
            ->orderByDesc('created_at')
            ->get();

        $files = DB::table('court_case_files')
            ->where('case_id', $id)
            ->orderByDesc('created_at')
            ->get();

        $msgs = DB::table('court_case_messages as m')
            ->leftJoin('court_staff as u', 'u.id', '=', 'm.sender_user_id')
            ->leftJoin('court_applicants as sa', 'sa.id', '=', 'm.sender_applicant_id')
            ->select('m.*', 'u.name as admin_name', 'sa.first_name as sender_first_name', 'sa.middle_name as sender_middle_name', 'sa.last_name as sender_last_name')
            ->where('m.case_id', $id)
            ->orderBy('m.created_at')
            ->get();

        $hearings = DB::table('court_case_hearings')
            ->where('case_id', $id)
            ->orderBy('hearing_at')
            ->get();

        $docs = DB::table('court_case_evidences')
            ->where('case_id', $id)
            ->where('type', 'document')
            ->orderBy('id')
            ->get();

        // Witnesses
        $witnesses = DB::table('court_case_witnesses')
            ->where('case_id', $id)
            ->orderBy('id')
            ->get();

        // Auto-mark as seen
        $now  = now();
        $rows = [];

        $unseenMsgIds = DB::table('court_case_messages as m')
            ->where('m.case_id', $id)
            ->whereNotNull('m.sender_user_id')
            ->where('m.created_at', '>=', now()->subDays(14))
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'm.id')
                    ->where('nr.type', 'message')
                    ->where('nr.applicant_id', $aid);
            })
            ->pluck('m.id');

        foreach ($unseenMsgIds as $mid) {
            $rows[] = ['applicant_id' => $aid, 'type' => 'message', 'source_id' => $mid, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }

        $unseenStatusIds = DB::table('court_case_status_logs as l')
            ->where('l.case_id', $id)
            ->where('l.created_at', '>=', now()->subDays(14))
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'l.id')
                    ->where('nr.type', 'status')
                    ->where('nr.applicant_id', $aid);
            })
            ->pluck('l.id');

        foreach ($unseenStatusIds as $sid) {
            $rows[] = ['applicant_id' => $aid, 'type' => 'status', 'source_id' => $sid, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }

        $unseenHearingIds = DB::table('court_case_hearings as h')
            ->where('h.case_id', $id)
            ->whereBetween('h.hearing_at', [now()->subDay(), now()->addDays(60)])
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'h.id')
                    ->where('nr.type', 'hearing')
                    ->where('nr.applicant_id', $aid);
            })
            ->pluck('h.id');

        foreach ($unseenHearingIds as $hid) {
            $rows[] = ['applicant_id' => $aid, 'type' => 'hearing', 'source_id' => $hid, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }

        if (!empty($rows)) {
            DB::table('court_notification_reads')->upsert(
                $rows,
                ['applicant_id', 'type', 'source_id'],
                ['seen_at', 'updated_at']
            );
        }

        $audits = DB::table('court_case_audits')
            ->where('case_id', $id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

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

        // React page Court/Applicant/Cases/Show: the Blade page's rows as plain arrays, dates as ISO 8601.
        $applicantUser = auth('applicant')->user();
        $iso = fn ($value): ?string => $this->isoDate($value);

        return Inertia::render('Court/Applicant/Cases/Show', [
            'case' => [
                'id' => (int) $case->id,
                'case_number' => (string) $case->case_number,
                'code' => !empty($case->code) ? (string) $case->code : null,
                'title' => $case->title ?? null,
                'status' => (string) ($case->status ?? 'pending'),
                'review_status' => (string) ($case->review_status ?? 'accepted'),
                'review_note' => !empty($case->review_note) ? (string) $case->review_note : null,
                'case_type' => $case->case_type ?? null,
                'filed_at' => $iso($case->filing_date ?? $case->created_at ?? null),
                'respondent_name' => $case->respondent_name ?? null,
                'respondent_address' => $case->respondent_address ?? null,
                'description_html' => \Modules\Court\Application\Support\Purifier::clean((string) ($case->description ?? ''), 'cases'),
                'relief_html' => !empty($case->relief_requested) ? \Modules\Court\Application\Support\Purifier::clean((string) $case->relief_requested, 'cases') : null,
            ],
            'applicant' => [
                // The case title doubles as the applicant's name, as on the Blade page.
                'name' => ($case->title ?? '') !== '' ? (string) $case->title : (string) ($applicantUser->full_name ?? $applicantUser->name ?? '-'),
                'address' => (string) ($applicantUser->address ?? '-'),
            ],
            'docs' => $docs->map(function ($d) use ($iso): array {
                $docPath = $d->file_path ?? $d->path ?? null;

                return [
                    'id' => (int) $d->id,
                    'title' => ($d->title ?? null) ?: (($d->label ?? null) ?: ($docPath ? basename((string) $docPath) : null)),
                    'mime' => $d->mime ?? null,
                    'size' => isset($d->size) ? max(0, (int) $d->size) : null,
                    'created_at' => $iso($d->created_at ?? null),
                    'description_html' => !empty($d->description) ? \Modules\Court\Application\Support\Purifier::clean((string) $d->description, 'cases') : null,
                    'has_file' => !empty($docPath),
                ];
            })->values()->all(),
            'witnesses' => $this->witnessRows($witnesses),
            'hearings' => $hearings->map(fn ($h): array => [
                'id' => (int) $h->id,
                'hearing_at' => $iso($h->hearing_at ?? null),
                'type' => $h->type ?? null,
                'location' => $h->location ?? null,
                'notes' => $h->notes ?? null,
            ])->values()->all(),
            'letters' => $letters->map(function ($letter) use ($iso): array {
                try {
                    $previewUrl = URL::signedRoute('court.letters.case-preview', ['letter' => $letter->id]);
                } catch (\Throwable $e) {
                    $previewUrl = url('/court/case-letters/' . $letter->id);
                }

                return [
                    'id' => (int) $letter->id,
                    'subject' => $letter->subject ?? null,
                    'reference_number' => $letter->reference_number ?? null,
                    'approval_status' => (string) ($letter->approval_status ?? 'draft'),
                    'template_title' => $letter->template_title ?? null,
                    'author_name' => $letter->author_name ?? null,
                    'created_at' => $iso($letter->created_at ?? null),
                    'preview_url' => $previewUrl,
                ];
            })->values()->all(),
            'respondentResponses' => $respondentResponses->map(fn ($r): array => [
                'id' => (int) $r->id,
                'title' => $r->title ?? null,
                'case_number' => (string) ($r->case_number ?? $case->case_number),
                'response_number' => !empty($r->response_number) ? (string) $r->response_number : null,
                'created_at' => $iso($r->created_at ?? null),
                'excerpt' => !empty($r->description) ? Str::limit(strip_tags((string) $r->description), 180) : null,
                'has_pdf' => !empty($r->pdf_path),
            ])->values()->all(),
            'messages' => $msgs->map(fn ($m): array => [
                'id' => (int) $m->id,
                'body' => (string) ($m->body ?? ''),
                'mine' => (int) ($m->sender_applicant_id ?? 0) === (int) $aid,
                // Another party's name; null for a court message without a named sender: the page shows "Court staff".
                'sender_name' => !empty($m->sender_applicant_id)
                    ? ((int) $m->sender_applicant_id === (int) $aid ? null : trim(implode(' ', array_filter([$m->sender_first_name ?? null, $m->sender_middle_name ?? null, $m->sender_last_name ?? null]))))
                    : ($m->admin_name ?? null),
                'created_at' => $iso($m->created_at ?? null),
            ])->values()->all(),
            'files' => $files->map(fn ($f): array => [
                'id' => (int) $f->id,
                'name' => ($f->label ?? null) ?: basename((string) ($f->path ?? '')),
                'mime' => $f->mime ?? null,
                'size' => (int) ($f->size ?? 0),
                'created_at' => $iso($f->created_at ?? null),
                // ApplicantCaseController::deleteFile removes only the applicant's own uploads.
                'can_delete' => (int) ($f->uploaded_by_applicant_id ?? 0) === (int) $aid,
            ])->values()->all(),
            'audits' => $audits->map(function ($a) use ($iso): array {
                $meta = $a->meta ? json_decode((string) $a->meta, true) : null;

                return [
                    'id' => (int) $a->id,
                    'action' => (string) ($a->action ?? ''),
                    'actor_type' => $a->actor_type ?? null,
                    'actor_id' => isset($a->actor_id) ? (int) $a->actor_id : null,
                    'actor_name' => ($a->actor_name ?? null) ?: null,
                    'meta' => $meta ? json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null,
                    'created_at' => $iso($a->created_at ?? null),
                ];
            })->values()->all(),
        ]);
    }

    public function showRespondentResponse(int $caseId, int $responseId)
    {
        $aid = auth('applicant')->id();

        $case = $this->findApplicantCaseWithType($caseId, $aid);

        abort_if(!$case, 404);

        $response = DB::table('court_respondent_responses as rr')
            ->leftJoin('court_respondents as r', 'r.id', '=', 'rr.respondent_id')
            ->select(
                'rr.*',
                'r.first_name',
                'r.middle_name',
                'r.last_name',
                'r.email as respondent_email',
                'r.phone as respondent_phone',
                'r.organization_name as respondent_org'
            )
            ->where('rr.id', $responseId)
            ->where('rr.case_number', $case->case_number)
            ->where('rr.review_status', 'accepted')
            ->first();

        abort_if(!$response, 404);

        // Every party's replies on this side of the case; each is changed by its own author only.
        $replies = DB::table('court_applicant_response_replies')
            ->where('case_id', $case->id)
            ->where('respondent_response_id', $response->id)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Court/Applicant/Cases/RespondentResponse', [
            'case' => ['id' => (int) $case->id, 'case_number' => (string) $case->case_number],
            'response' => array_merge($this->respondentResponseSummary($response), [
                'title' => $response->title ?? null,
                'case_number' => (string) ($response->case_number ?? $case->case_number),
                'respondent_email' => !empty($response->respondent_email) ? (string) $response->respondent_email : null,
                'respondent_phone' => !empty($response->respondent_phone) ? (string) $response->respondent_phone : null,
                'respondent_org' => !empty($response->respondent_org) ? (string) $response->respondent_org : null,
                'created_at' => $this->isoDate($response->created_at ?? null),
                'summary' => !empty($response->summary) ? (string) $response->summary : null,
                'description_html' => \Modules\Court\Application\Support\Purifier::clean((string) ($response->description ?? ''), 'cases'),
                'pdf_name' => !empty($response->pdf_path) ? basename((string) $response->pdf_path) : null,
            ]),
            'replies' => $replies->map(fn ($reply): array => [
                'id' => (int) $reply->id,
                'review_status' => (string) ($reply->review_status ?? 'awaiting_review'),
                'review_note' => !empty($reply->review_note) ? (string) $reply->review_note : null,
                'excerpt' => Str::limit((string) ($reply->description ?? ''), 180),
                'created_at' => $this->isoDate($reply->created_at ?? null),
                // An accepted reply is locked (ApplicantResponseReplyController::ensureEditableReply).
                'editable' => (int) $reply->applicant_id === (int) $aid && ($reply->review_status ?? null) !== 'accepted',
                'mine' => (int) $reply->applicant_id === (int) $aid,
            ])->values()->all(),
        ]);
    }

    public function replyRespondentResponse(int $caseId, int $responseId)
    {
        $aid = auth('applicant')->id();

        $case = $this->findApplicantCaseWithType($caseId, $aid);

        abort_if(!$case, 404);

        $response = DB::table('court_respondent_responses as rr')
            ->leftJoin('court_respondents as r', 'r.id', '=', 'rr.respondent_id')
            ->select(
                'rr.*',
                'r.first_name',
                'r.middle_name',
                'r.last_name',
                'r.email as respondent_email',
                'r.phone as respondent_phone',
                'r.organization_name as respondent_org'
            )
            ->where('rr.id', $responseId)
            ->where('rr.case_number', $case->case_number)
            ->where('rr.review_status', 'accepted')
            ->first();

        abort_if(!$response, 404);

        // Same page as ApplicantResponseReplyController::create.
        $applicant = auth('applicant')->user();

        return Inertia::render('Court/Applicant/Replies/Create', [
            'case' => ['id' => (int) $case->id, 'case_number' => (string) $case->case_number],
            'response' => $this->respondentResponseSummary($response),
            'applicantName' => trim(implode(' ', array_filter([$applicant->first_name ?? '', $applicant->middle_name ?? '', $applicant->last_name ?? ''], fn ($part) => trim((string) $part) !== ''))),
        ]);
    }

    /**
     * Edit form (only while pending)
     */
    public function edit(int $id)
    {
        $me = auth('applicant')->id();

        $case = DB::table('court_cases')
            ->where('id', $id)
            ->whereIn('id', CaseParties::leadCaseIdsFor((int) $me))
            ->first();

        abort_if(!$case, 404);

        if ($case->status !== 'pending' || ($case->review_status ?? null) === 'accepted') {
            return redirect()->route('court.applicant.cases.show', $id)
                ->with('error', 'You can only edit pending cases.');
        }

        $types  = DB::table('court_case_types')->orderBy('name')->get(['id', 'name', 'prefix']);


        $docs = DB::table('court_case_evidences')
            ->where('case_id', $id)->where('type', 'document')
            ->orderBy('id')->get();

        // correct table for witnesses
        $witnesses = DB::table('court_case_witnesses')
            ->where('case_id', $id)
            ->orderBy('id')
            ->get();

        $editable = $case->status === 'pending' && ($case->review_status ?? null) !== 'accepted';

        return Inertia::render('Court/Applicant/Cases/Edit', [
            'case' => [
                'id' => (int) $case->id,
                'case_number' => (string) $case->case_number,
                'status' => (string) $case->status,
                'review_status' => (string) ($case->review_status ?? 'accepted'),
                'title' => (string) ($case->title ?? ''),
                'filing_date' => $this->isoDay($case->filing_date ?? null),
                'case_type_id' => $case->case_type_id !== null ? (int) $case->case_type_id : null,
                'respondent_name' => (string) ($case->respondent_name ?? ''),
                'respondent_address' => (string) ($case->respondent_address ?? ''),
                'description' => \Modules\Court\Application\Support\Purifier::clean((string) ($case->description ?? ''), 'cases'),
                'relief_requested' => \Modules\Court\Application\Support\Purifier::clean((string) ($case->relief_requested ?? ''), 'cases'),
            ],
            'editable' => $editable,
            'types' => $this->caseTypeOptions($types),
            'docs' => $docs->map(function ($d): array {
                $docPath = $d->file_path ?? $d->path ?? null;

                return [
                    'id' => (int) $d->id,
                    'title' => ($d->title ?? null) ?: ($docPath ? basename((string) $docPath) : null),
                    'has_file' => !empty($docPath),
                ];
            })->values()->all(),
            'witnesses' => $this->witnessRows($witnesses),
            // The case number format hint: type prefix / 5-digit sequence / last 2 digits of the year.
            'yearSuffix' => now()->format('y'),
        ]);
    }
    public function destroy($id)
    {
        $applicantId = auth('applicant')->id();

        // Ensure the case belongs to this applicant
        $case = CourtCase::query()->whereIn('id', CaseParties::leadCaseIdsFor((int) $applicantId))->findOrFail($id);

        // Only allow deletion if status is pending
        if ($case->status !== 'pending' || ($case->review_status ?? null) === 'accepted') {
            abort(403, 'Only pending cases can be deleted.');
        }

        $case->delete(); // if you want soft delete, enable SoftDeletes on the model instead

        return redirect()
            ->route('court.applicant.cases.index')
            ->with('success', __('court::cases.deleted_successfully') ?? 'Case deleted successfully.');
    }
    /**
     * Update case (add more docs/witness names; only while pending)
     * NOTE: Sanitizes TinyMCE HTML fields on save.
     */
    public function update(Request $request, int $id)
    {
        $me = auth('applicant')->id();

        $case = DB::table('court_cases')
            ->where('id', $id)
            ->whereIn('id', CaseParties::leadCaseIdsFor((int) $me))
            ->first();

        abort_if(!$case, 404);

        if ($case->status !== 'pending' || ($case->review_status ?? null) === 'accepted') {
            return redirect()->route('court.applicant.cases.show', $id)
                ->with('error', 'You can only edit pending cases.');
        }

        $rules = [
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'relief_requested'   => ['required', 'string'],
            'certify_appeal'     => ['accepted'],
            'respondent_name'    => ['nullable', 'string', 'max:255'],
            'respondent_address' => ['nullable', 'string', 'max:500'],
            'case_type_id'       => ['required', 'integer', 'exists:court_case_types,id'],

            'filing_date'        => ['required', 'date'],

            // uploads
            'evidence_titles.*'  => ['nullable', 'string', 'max:255'],
            'evidence_files.*'   => ['nullable', 'file', 'mimes:pdf', 'max:5120'],

            // witnesses (match edit form names)
            'witnesses'                 => ['array'],
            'witnesses.*.full_name'     => ['nullable', 'string', 'max:255'],
            'witnesses.*.phone'         => ['nullable', 'string', 'max:60'],
            'witnesses.*.email'         => ['nullable', 'email', 'max:150'],
            'witnesses.*.address'       => ['nullable', 'string', 'max:255'],
            'certify_evidence'          => ['accepted'],
        ];

        $messages = [
            'certify_appeal.accepted' => 'You must certify the validity of your appeal.',
            'certify_evidence.accepted' => 'You must certify that your evidence is true.',
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        $validator->after(function ($validator) use ($request, $id) {
            $newWitnesses = collect($request->input('witnesses', []));

            $newPhones = $newWitnesses->pluck('phone')
                ->filter(fn($phone) => filled($phone))
                ->map(fn($phone) => mb_strtolower(trim($phone)))
                ->values();
            if ($newPhones->count() !== $newPhones->unique()->count()) {
                $validator->errors()->add('witnesses_duplicate_phone', 'Witness phone numbers must be unique.');
            }

            $existingPhones = DB::table('court_case_witnesses')
                ->where('case_id', $id)
                ->pluck('phone')
                ->filter(fn($phone) => filled($phone))
                ->map(fn($phone) => mb_strtolower(trim($phone)));
            if ($existingPhones->intersect($newPhones)->isNotEmpty()) {
                $validator->errors()->add('witnesses_duplicate_phone', 'Witness phone numbers must be unique.');
            }

            $newEmails = $newWitnesses->pluck('email')
                ->filter(fn($email) => filled($email))
                ->map(fn($email) => mb_strtolower(trim($email)))
                ->values();
            if ($newEmails->count() !== $newEmails->unique()->count()) {
                $validator->errors()->add('witnesses_duplicate_email', 'Witness email addresses must be unique.');
            }

            $existingEmails = DB::table('court_case_witnesses')
                ->where('case_id', $id)
                ->pluck('email')
                ->filter(fn($email) => filled($email))
                ->map(fn($email) => mb_strtolower(trim($email)));
            if ($existingEmails->intersect($newEmails)->isNotEmpty()) {
                $validator->errors()->add('witnesses_duplicate_email', 'Witness email addresses must be unique.');
            }
        });

        $data = $validator->validate();

        // Sanitize TinyMCE HTML (decode if entity-encoded, then Purifier 'cases' profile)
        $descHtml   = $this->cleanHtml($data['description'] ?? '');
        $reliefHtml = $this->cleanHtml($data['relief_requested'] ?? '');

        // Optional word limit to mirror UI
        if ($this->wordCount($descHtml) > 1300 || $this->wordCount($reliefHtml) > 1300) {
            return back()
                ->withErrors(['description' => 'Please keep each editor under ~1,300 words.'])
                ->withInput();
        }

        DB::beginTransaction();

        try {
            // update core fields

            $newCaseNumber = $case->case_number;

            if ($case->case_type_id != $data['case_type_id']) {
                // Generate a NEW case number based on selected case type
                $newCaseNumber = $this->generateCaseNumber($data['case_type_id']);
            }
            DB::table('court_cases')->where('id', $id)->update([
                'title'              => trim($data['title']),
                'description'        => $descHtml,           // sanitized HTML
                'case_number'        => $newCaseNumber,
                'relief_requested'   => $reliefHtml ?: null, // sanitized HTML
                'respondent_name'    => isset($data['respondent_name']) ? strip_tags($data['respondent_name']) : null,
                'respondent_address' => isset($data['respondent_address']) ? strip_tags($data['respondent_address']) : null,
                'case_type_id'       => $data['case_type_id'],

                'filing_date'        => $data['filing_date'],
                'review_status'      => 'awaiting_review',
                'reviewed_by_user_id' => null,
                'reviewed_at'        => null,
                'updated_at'         => now(),
            ]);

            // add new PDFs (if any)
            if ($request->hasFile('evidence_files')) {
                $titles = $request->input('evidence_titles', []);
                foreach ($request->file('evidence_files') as $i => $file) {
                    if (!$file) continue;

                    /** @var \Illuminate\Http\UploadedFile $file */
                    $stored = app(SecureUploadService::class)->store($file, 'evidences', 'court_private', [
                        'related_type' => 'court_case',
                        'related_id' => (int) $id,
                        'applicant_id' => (int) $me,
                    ]);

                    $insert = [
                        'case_id'    => $id,
                        'type'       => 'document',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (Schema::hasColumn('court_case_evidences', 'file_path')) {
                        $insert['file_path'] = $stored;
                    } elseif (Schema::hasColumn('court_case_evidences', 'path')) {
                        $insert['path'] = $stored;
                    }
                    if (Schema::hasColumn('court_case_evidences', 'title')) {
                        $insert['title'] = $titles[$i] ?? ('Document ' . ($i + 1));
                    }
                    if (Schema::hasColumn('court_case_evidences', 'description')) {
                        $insert['description'] = null;
                    }
                    if (Schema::hasColumn('court_case_evidences', 'mime')) {
                        $insert['mime'] = $file->getClientMimeType() ?: 'application/pdf';
                    }
                    if (Schema::hasColumn('court_case_evidences', 'size')) {
                        $insert['size'] = $file->getSize();
                    }
                    if (Schema::hasColumn('court_case_evidences', 'uploaded_by_applicant_id')) {
                        $insert['uploaded_by_applicant_id'] = $me;
                    } elseif (Schema::hasColumn('court_case_evidences', 'uploaded_by_user_id')) {
                        $insert['uploaded_by_user_id'] = $me;
                    }

                    DB::table('court_case_evidences')->insert($insert);
                }
            }

            // add new witnesses (from edit form)
            $incoming = $request->input('witnesses', []);
            foreach ($incoming as $w) {
                $full = trim((string) ($w['full_name'] ?? ''));
                if ($full === '') continue; // skip empty rows

                $row = [
                    'case_id'    => $id,
                    'full_name'  => $full,
                    'phone'      => $w['phone']   ?? null,
                    'email'      => $w['email']   ?? null,
                    'address'    => $w['address'] ?? null,

                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('court_case_witnesses', 'created_by_user_id')) {
                    $row['created_by_user_id'] = null;
                }
                if (Schema::hasColumn('court_case_witnesses', 'updated_by_user_id')) {
                    $row['updated_by_user_id'] = null;
                }

                DB::table('court_case_witnesses')->insert($row);
            }

            DB::commit();
            $case = DB::table('court_cases')->where('id', $id)->first();
            if ($case) {
                $this->notifyAdminCaseUpdated($case, $me);
                $this->logCaseAudit($id, 'applicant_updated', ['sections' => 'case_core']);
            }
            return redirect()->route('court.applicant.cases.show', $id)->with('success', 'Case updated.');
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return back()->withInput()->with('error', 'Failed to update case. Please try again.');
        }
    }

    /** One rule for every change an applicant makes to a case: pending, and not yet accepted by the court. */
    private function applicantMayModify(object $case): bool
    {
        return $case->status === 'pending' && ($case->review_status ?? null) !== 'accepted';
    }

    public function deleteEvidence(int $id, int $evidenceId)
    {
        $applicantId = auth('applicant')->id();

        $case = DB::table('court_cases')
            ->where('id', $id)
            ->whereIn('id', CaseParties::leadCaseIdsFor((int) $applicantId))
            ->first();
        abort_if(!$case, 404);

        if (! $this->applicantMayModify($case)) {
            return back()->with('error', __('court::cases.hints.edit_only_pending'));
        }

        $ev = DB::table('court_case_evidences')
            ->where('id', $evidenceId)
            ->where('case_id', $id)
            ->first();
        abort_if(!$ev, 404);

        $filePath = $ev->file_path ?? $ev->path ?? null;
        if (!empty($filePath)) {
            app(LegalHoldService::class)->assertFileMayBeDeleted((string) $filePath);
            $this->deleteStoredFile($filePath);
        }

        DB::table('court_case_evidences')->where('id', $evidenceId)->delete();

        return back()->with('success', __('court::cases.messages_flash.evidence_removed'));
    }

    public function deleteWitness(int $id, int $witnessId)
    {
        $applicantId = auth('applicant')->id();

        // enforce ownership + pending
        $case = DB::table('court_cases')
            ->where('id', $id)
            ->whereIn('id', CaseParties::leadCaseIdsFor((int) $applicantId))
            ->first();

        abort_if(!$case, 404);

        if (! $this->applicantMayModify($case)) {
            return back()->with('error', __('court::cases.hints.edit_only_pending'));
        }

        $exists = DB::table('court_case_witnesses')
            ->where('id', $witnessId)
            ->where('case_id', $id)
            ->exists();

        abort_unless($exists, 404);

        DB::table('court_case_witnesses')
            ->where('id', $witnessId)
            ->where('case_id', $id)
            ->delete();

        return back()->with('success', __('court::cases.messages_flash.witness_removed'));
    }

    /**
     * Upload a generic file to the case (separate from "evidences" PDFs)
     */
    public function uploadFile(Request $request, $id, SecureUploadService $uploads)
    {
        $applicantId = auth('applicant')->id();

        $own = CaseParties::canFollow((int) $applicantId, (int) $id);
        abort_unless($own, 403);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'file'  => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:4096'],
        ]);

        /** @var UploadedFile $file */
        $file = $data['file'];
        $path = $uploads->store($file, 'case_files', 'court_private', [
            'related_type' => 'court_case',
            'related_id' => (int) $id,
            'applicant_id' => $applicantId,
        ]);

        DB::table('court_case_files')->insert([
            'case_id'                   => $id,
            'uploaded_by_applicant_id'  => $applicantId,
            'label'                     => isset($data['label']) ? strip_tags($data['label']) : null,
            'path'                      => $path,
            'mime'                      => $file->getClientMimeType(),
            'size'                      => $file->getSize(),
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);

        $this->logCaseAudit($id, 'file_uploaded', [
            'label' => $data['label'] ?? null,
            'path'  => $path,
        ]);

        return back()->with('success', 'File uploaded.');
    }

    /**
     * Delete a file uploaded by the applicant
     */
    public function deleteFile($id, $fileId, LegalHoldService $legalHolds)
    {
        $applicantId = auth('applicant')->id();

        $file = DB::table('court_case_files')->where('id', $fileId)->where('case_id', $id)->first();
        abort_unless($file, 404);

        abort_unless((int) $file->uploaded_by_applicant_id === (int) $applicantId, 403);

        $legalHolds->assertFileMayBeDeleted((string) $file->path);

        $this->deleteStoredFile($file->path);
        DB::table('court_case_files')->where('id', $fileId)->delete();

        $this->logCaseAudit($id, 'file_deleted', [
            'file_id' => $fileId,
            'label'   => $file->label ?? null,
        ]);

        return back()->with('success', 'File removed.');
    }

    /**
     * Applicant posts a message to staff
     */
    public function postMessage(Request $request, $id)
    {
        $applicantId = auth('applicant')->id();

        $own = CaseParties::canFollow((int) $applicantId, (int) $id);
        abort_unless($own, 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        DB::table('court_case_messages')->insert([
            'case_id'             => $id,
            'sender_applicant_id' => $applicantId,
            'body'                => $data['body'], // rendered with {{ }} so XSS-safe
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // notify assignee (or fallback inbox)
        $caseRow = DB::table('court_cases')->where('id', $id)->first();

        $assigneeEmail = null;
        if (!empty($caseRow->assigned_user_id)) {
            $assigneeEmail = DB::table('court_staff')->where('id', $caseRow->assigned_user_id)->value('email');
        }
        $to = $assigneeEmail ?: config('mail.from.address');

        // The message is posted: a mail server that refuses must not undo the audit and notices below.
        if ($to) {
            try {
                $preview = mb_strimwidth($data['body'], 0, 180, '...');
                Mail::to($to)->send(new CaseMessageMail($caseRow, __('court::cases.summary.applicant'), $preview));
            } catch (\Throwable $e) {
                Log::error('Failed sending case message notification', [
                    'case_id' => $id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->logCaseAudit($id, 'message_posted', [
            'by'   => 'applicant',
            'body' => mb_strimwidth($data['body'], 0, 200, '...'),
        ]);

        ResponseNotificationService::notifyCaseMessagePosted((int) $id, __('court::cases.summary.applicant'), $data['body']);

        return back()->with('success', 'Message sent.');
    }

    /**
     * Printable receipt page
     */
    public function receipt(int $id)
    {
        $me = auth('applicant')->id();

        $case = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')

            ->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
            ->select(
                'c.*',
                'ct.name as case_type',

                'a.first_name',
                'a.middle_name',
                'a.last_name',
                'a.email',
                'a.phone'
            )
            ->where('c.id', $id)
            ->whereIn('c.id', CaseParties::caseIdsFor((int) $me))
            ->first();

        abort_if(!$case, 404);

        $evidenceDocs = DB::table('court_case_evidences')
            ->where('case_id', $id)
            ->where('type', 'document')
            ->orderBy('id')
            ->get();

        // witnesses
        $witnesses = DB::table('court_case_witnesses')
            ->where('case_id', $id)
            ->orderBy('id')
            ->get();

        $files = DB::table('court_case_files')
            ->where('case_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        $hearings = DB::table('court_case_hearings')
            ->where('case_id', $id)
            ->orderBy('hearing_at')
            ->get();

        $at = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;

        // The printable receipt (React, Court/Applicant/Cases/Receipt); the PDF stays receiptPdf().
        return Inertia::render('Court/Applicant/Cases/Receipt', [
            'receipt' => [
                'id' => (int) $case->id,
                'case_number' => (string) $case->case_number,
                'code' => $case->code ?: null,
                'status' => (string) $case->status,
                'title' => (string) $case->title,
                'filed_at' => $at($case->filing_date ?? $case->created_at),
                'case_type' => $case->case_type ?: null,
                'court_name' => ($case->court_name ?? null) ?: null,
                'applicant' => [
                    'name' => trim(preg_replace('/\s+/', ' ', $case->first_name.' '.($case->middle_name ?? '').' '.$case->last_name)),
                    'email' => $case->email ?: null,
                    'phone' => $case->phone ?: null,
                ],
                'respondent' => ($case->respondent_name || $case->respondent_address)
                    ? ['name' => $case->respondent_name ?: null, 'address' => $case->respondent_address ?: null]
                    : null,
                // The details are the editor's HTML; the Blade page printed the tags as text.
                'description_html' => \Modules\Court\Application\Support\Purifier::clean((string) ($case->description ?? ''), 'cases'),
                'relief_html' => ! empty($case->relief_requested) ? \Modules\Court\Application\Support\Purifier::clean((string) $case->relief_requested, 'cases') : null,
            ],
            'witnesses' => $witnesses->map(fn (object $w): array => [
                'id' => (int) $w->id,
                'name' => (string) $w->full_name,
                'phone' => $w->phone ?: null,
                'email' => $w->email ?: null,
            ])->values(),
            'documents' => $evidenceDocs->map(fn (object $d): array => [
                'id' => (int) $d->id,
                'title' => (string) ($d->title ?? basename((string) $d->file_path)),
                'url' => $d->file_path ? route('court.applicant.cases.evidences.download', ['id' => $case->id, 'evidenceId' => $d->id]) : null,
            ])->values(),
            'files' => $files->map(fn (object $f): array => [
                'id' => (int) $f->id,
                'label' => (string) ($f->label ?? basename((string) $f->path)),
                'extension' => strtoupper(pathinfo((string) $f->path, PATHINFO_EXTENSION)),
                'uploaded_at' => $at($f->created_at),
            ])->values(),
            'hearings' => $hearings->map(fn (object $h): array => [
                'id' => (int) $h->id,
                'at' => $at($h->hearing_at),
                'type' => $h->type ?: null,
                'location' => $h->location ?: null,
                'ics_url' => route('court.applicant.cases.hearings.ics', [$case->id, $h->id]),
            ])->values(),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * Download .ics for a hearing and mark it seen
     */
    public function downloadHearingIcs(int $id, int $hearingId)
    {
        $aid = auth('applicant')->id();

        $case = DB::table('court_cases')->where('id', $id)->whereIn('id', CaseParties::caseIdsFor((int) $aid))->first();
        abort_if(!$case, 404);

        $hearing = DB::table('court_case_hearings')->where('id', $hearingId)->where('case_id', $id)->first();
        abort_if(!$hearing, 404);

        DB::table('court_notification_reads')->updateOrInsert(
            ['applicant_id' => $aid, 'type' => 'hearing', 'source_id' => $hearing->id],
            ['seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );

        $start = Carbon::parse($hearing->hearing_at)->utc();
        $end   = (clone $start)->addHour();
        $now   = Carbon::now('UTC');

        $host = parse_url(config('app.url', 'https://court-ms.local'), PHP_URL_HOST) ?: 'court-ms.local';
        $uid  = 'hearing-' . $hearing->id . '@' . $host;

        $escape = fn($t) => str_replace(["\\", ";", ","], ["\\\\", "\;", "\,"], $t ?? '');

        $summary     = $escape(__('court::notifications.mail.hearing_calendar_summary', [
            'case' => $case->case_number ?? '',
        ]));
        $description = $escape(__('court::notifications.mail.hearing_calendar_description', [
            'title' => $case->title ?? '',
        ]));
        $location    = $escape($hearing->location ?? '');

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Court-MS//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now->format('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:'   . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . $summary,
            'DESCRIPTION:' . $description,
            'LOCATION:' . $location,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $safeCaseNumber = $this->sanitizeFilenamePart($case->case_number, 'case');
        $filename = 'hearing-' . $safeCaseNumber . '-' . $hearing->id . '.ics';

        return response($ics, 200, [
            'Content-Type'              => 'text/calendar; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $filename . '"',
            'Content-Transfer-Encoding' => 'binary',
        ]);
    }

    /**
     * Download receipt as PDF
     */
    public function receiptPdf(int $id)
    {
        $me = auth('applicant')->id();

        $case = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')

            ->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
            ->select(
                'c.*',
                'ct.name as case_type',

                'a.first_name',
                'a.middle_name',
                'a.last_name',
                'a.email',
                'a.phone'
            )
            ->where('c.id', $id)
            ->whereIn('c.id', CaseParties::caseIdsFor((int) $me))
            ->first();

        abort_if(!$case, 404);

        $evidenceDocs = DB::table('court_case_evidences')->where('case_id', $id)->where('type', 'document')->orderBy('id')->get();

        // witnesses
        $witnesses    = DB::table('court_case_witnesses')->where('case_id', $id)->orderBy('id')->get();

        $files        = DB::table('court_case_files')->where('case_id', $id)->orderByDesc('created_at')->get();
        $hearings     = DB::table('court_case_hearings')->where('case_id', $id)->orderBy('hearing_at')->get();

        $pdf = PDF::loadView('court::applicant.cases.receipt-pdf', [
            'case'         => $case,
            'evidenceDocs' => $evidenceDocs,
            'witnesses'    => $witnesses,
            'files'        => $files,
            'hearings'     => $hearings,
        ])->setPaper('a4');

        $safeCaseNumber = $this->sanitizeFilenamePart($case->case_number, 'case');
        $filename = 'receipt-' . $safeCaseNumber . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Email receipt PDF to the applicant
     */
    public function emailReceipt(int $id)
    {
        $me    = auth('applicant')->id();
        $email = auth('applicant')->user()->email ?? null;

        $case = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')

            ->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
            ->select(
                'c.*',
                'ct.name as case_type',

                'a.first_name',
                'a.middle_name',
                'a.last_name',
                'a.email',
                'a.phone'
            )
            ->where('c.id', $id)
            ->whereIn('c.id', CaseParties::caseIdsFor((int) $me))
            ->first();

        abort_if(!$case, 404);

        $evidenceDocs = DB::table('court_case_evidences')->where('case_id', $id)->where('type', 'document')->orderBy('id')->get();

        // witnesses
        $witnesses    = DB::table('court_case_witnesses')->where('case_id', $id)->orderBy('id')->get();

        $files        = DB::table('court_case_files')->where('case_id', $id)->orderByDesc('created_at')->get();
        $hearings     = DB::table('court_case_hearings')->where('case_id', $id)->orderBy('hearing_at')->get();

        $pdf = PDF::loadView('court::applicant.cases.receipt-pdf', [
            'case'         => $case,
            'evidenceDocs' => $evidenceDocs,
            'witnesses'    => $witnesses,
            'files'        => $files,
            'hearings'     => $hearings,
        ])->setPaper('a4');

        if (!$email) {
            return back()->with('error', __('court::cases.notifications.receipt_email_missing'));
        }

        try {
            $safeCaseNumber = $this->sanitizeFilenamePart($case->case_number, 'case');
            $filename = 'receipt-' . $safeCaseNumber . '.pdf';
            Mail::to($email)->send(new ApplicantReceiptMail($case, $pdf->output(), $filename));
            Log::info('Applicant receipt PDF sent', ['case_id' => $id, 'to' => $email]);
        } catch (\Throwable $e) {
            Log::error('Failed sending applicant receipt PDF', [
                'case_id' => $id,
                'error'   => $e->getMessage(),
            ]);
            return back()->with('error', __('court::cases.notifications.receipt_email_failed'));
        }

        return back()->with('success', __('court::cases.notifications.receipt_email_sent', ['email' => $email]));
    }

    // --------------------
    // Helpers
    // --------------------

    /**
     * Decode entity-encoded HTML (if needed) and sanitize using Purifier 'cases' profile.
     */
    private function cleanHtml(?string $html): string
    {
        $s = (string) ($html ?? '');
        if ($s === '') return '';
        // If TinyMCE content got entity-encoded (&lt;p&gt;), decode to real tags first
        if (str_contains($s, '&lt;') || str_contains($s, '&gt;')) {
            $s = htmlspecialchars_decode($s, ENT_QUOTES);
        }
        return \Modules\Court\Application\Support\Purifier::clean($s, 'cases');
    }

    /**
     * Ensure dynamic filename parts avoid directory separators rejected by Symfony.
     */
    private function sanitizeFilenamePart(?string $value, string $fallback = 'file'): string
    {
        $clean = trim((string) ($value ?? ''));
        $clean = str_replace(['/', '\\'], '-', $clean);
        $clean = trim($clean, "- \t\n\r\0\x0B");

        return $clean !== '' ? $clean : $fallback;
    }

    /** A database date/time as ISO 8601, or null when it is empty or unparseable. */
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

    /** A database date as an ISO 8601 calendar date (YYYY-MM-DD), or null. */
    private function isoDay(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The case type options of the filing and edit forms. @return list<array{id: int, name: string, prefix: ?string}> */
    private function caseTypeOptions($types): array
    {
        return collect($types)->map(fn ($t): array => [
            'id' => (int) $t->id,
            'name' => (string) $t->name,
            'prefix' => isset($t->prefix) && $t->prefix !== '' ? (string) $t->prefix : null,
        ])->values()->all();
    }

    /** @return list<array{id: int, full_name: string, phone: ?string, email: ?string, address: ?string}> */
    private function witnessRows($witnesses): array
    {
        return collect($witnesses)->map(fn ($w): array => [
            'id' => (int) $w->id,
            'full_name' => (string) ($w->full_name ?? ''),
            'phone' => !empty($w->phone) ? (string) $w->phone : null,
            'email' => !empty($w->email) ? (string) $w->email : null,
            'address' => !empty($w->address) ? (string) $w->address : null,
        ])->values()->all();
    }

    /** The respondent response as the reply pages show it: number and respondent's name. @return array<string, mixed> */
    private function respondentResponseSummary(object $response): array
    {
        $name = collect([$response->first_name ?? null, $response->middle_name ?? null, $response->last_name ?? null])
            ->map(fn ($part) => trim((string) $part))->filter()->implode(' ');

        return [
            'id' => (int) $response->id,
            'response_number' => !empty($response->response_number) ? (string) $response->response_number : null,
            'respondent_name' => $name !== '' ? $name : null,
            'has_pdf' => !empty($response->pdf_path),
        ];
    }

    /**
     * Count words in sanitized HTML (approx; strips tags first).
     */
    private function wordCount(string $html): int
    {
        return str_word_count(strip_tags($html));
    }

    /**
     * Notify admins/reviewers that the applicant changed the case (triggers admin notifications + email).
     */
    private function notifyAdminCaseUpdated(object $case, int $applicantId): void
    {
        try {
            $body = __('court::cases.notifications.applicant_updated_submission');
            DB::table('court_case_messages')->insert([
                'case_id'             => $case->id,
                'sender_applicant_id' => $applicantId,
                'sender_user_id'      => null,
                'body'                => $body,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            $to = null;
            if (!empty($case->assigned_user_id)) {
                $to = DB::table('court_staff')->where('id', $case->assigned_user_id)->value('email');
            }
            $to = $to ?: config('mail.from.address');

            ResponseNotificationService::notifyApplicantCaseUpdated((int) $case->id);

            if ($to) {
                $preview = mb_strimwidth($body, 0, 180, '...');
                Mail::to($to)->send(new CaseMessageMail($case, __('court::cases.summary.applicant'), $preview));
            }
        } catch (\Throwable $e) {
            Log::error('Failed sending admin update notification', [
                'case_id' => $case->id ?? null,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function findApplicantCaseWithType(int $caseId, int $applicantId)
    {
        return DB::table('court_cases as cc')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'cc.case_type_id')
            ->select('cc.*', 'ct.name as case_type_name')
            ->where('cc.id', $caseId)
            ->whereIn('cc.id', CaseParties::caseIdsFor((int) $applicantId))
            ->first();
    }

    /**
     * Record a case audit entry as applicant.
     */
    private function logCaseAudit(int $caseId, string $action, array $meta = []): void
    {
        try {
            DB::table('court_case_audits')->insert([
                'case_id'    => $caseId,
                'action'     => $action,
                'actor_type' => 'applicant',
                'actor_id'   => auth('applicant')->id(),
                'meta'       => empty($meta) ? null : json_encode($meta),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log applicant case audit', ['case_id' => $caseId, 'action' => $action, 'error' => $e->getMessage()]);
        }
    }

    private function deleteStoredFile(string $path): void
    {
        $private = Storage::disk('court_private');
        if ($private->exists($path)) {
            $private->delete($path);
            return;
        }

        $public = Storage::disk('court_public');
        if ($public->exists($path)) {
            $public->delete($path);
        }
    }
}
