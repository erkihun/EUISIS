<?php

namespace Modules\Court\Http\Controllers\Respondent;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Application\Mail\RespondentViewedCaseMail;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Application\Services\ResponseNotificationService;
use Modules\Court\Application\Support\DecisionPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class CaseSearchController extends Controller
{
    /**
     * The case finder (React, Court/Respondent/CaseSearch). The search form posts
     * to submit(), which redirects here with the number only, so the access code
     * never appears in a URL or in browser history (#3, 58e8368). An old
     * ?case_code= link still works once; the stored code never leaves the server.
     */
    public function index(Request $request)
    {
        Session::put('acting_as_respondent', true);
        $caseNumber = trim((string) $request->get('case_number', ''));
        $case = null;
        $codeError = (bool) session('respondent_search_failed', false);

        if ($caseNumber !== '' && $request->filled('case_code')) {
            [$case, $codeError] = $this->attempt($caseNumber, trim((string) $request->get('case_code')));
        } elseif ($caseNumber !== '') {
            // After submit(): the case is shown when this session was granted access to it.
            $found = $this->findCaseForSearch($caseNumber);
            $case = $found && $this->hasCaseAccess((int) $found->id) ? $found : null;
        }

        return Inertia::render('Court/Respondent/CaseSearch', [
            'caseNumber' => $caseNumber,
            'case' => $case ? [
                'case_number' => (string) $case->case_number,
                'title' => $case->title,
                'status' => $case->status,
                'created_at' => $this->iso($case->created_at),
                // The access granted in attempt() opens the case page without the code.
                'show_url' => route('court.respondent.cases.show', ['caseNumber' => $case->case_number]),
            ] : null,
            'codeError' => $codeError,
        ]);
    }

    /** The search form: checks the number and access code, then redirects without the code. */
    public function submit(Request $request): RedirectResponse
    {
        Session::put('acting_as_respondent', true);
        $data = $request->validate([
            'case_number' => ['required', 'string', 'max:64'],
            'case_code' => ['required', 'string', 'max:32'],
        ]);
        $caseNumber = trim($data['case_number']);

        [$case, $codeError] = $this->attempt($caseNumber, trim($data['case_code']));

        return redirect()
            ->route('court.respondent.case.search', ['case_number' => $caseNumber])
            ->with('respondent_search_failed', $case === null && $codeError);
    }

    /**
     * Looks a case up by number and access code; on success grants this session
     * access and records the view (which is what "My cases" and later visits use).
     *
     * @return array{0: object|null, 1: bool} [case, wrong code]
     */
    private function attempt(string $caseNumber, string $caseCode): array
    {
        $case = $this->findCaseForSearch($caseNumber);

        // Applicants cannot look up their own cases while in respondent mode.
        $applicantId = Auth::guard('applicant')->id();
        if ($case && $applicantId && (int) $case->applicant_id === (int) $applicantId) {
            $case = null;
        }

        if ($case && ! $this->caseCodeMatches($case, $caseCode)) {
            return [null, true];
        }

        if ($case) {
            $this->grantCaseAccess($case->id);
            $history = collect(session('respondent_viewed_cases', []))->prepend($caseNumber)->unique()->take(12);
            session(['respondent_viewed_cases' => $history->values()->all()]);

            $respondent = $this->resolveActingRespondent();
            if ($respondent) {
                $this->recordRespondentCaseView($case->id, $respondent->id, $case->case_number ?? null);
            }
        }

        return [$case, false];
    }

    private function iso(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    /** The signed public preview of an approved letter (the Blade page built it the same way). */
    private function letterPreviewUrl(int $letterId): string
    {
        try {
            return URL::signedRoute('court.letters.case-preview', ['letter' => $letterId]);
        } catch (\Throwable) {
            return url('/court/case-letters/'.$letterId);
        }
    }

    /** Stored rich text, cleaned the way the Blade page printed it. */
    private function cleanHtml(?string $html): ?string
    {
        $html = (string) ($html ?? '');

        return $html === '' ? null : (string) \Modules\Court\Application\Support\Purifier::clean($html, 'cases');
    }

    public function show(string $caseNumber)
    {
        Session::put('acting_as_respondent', true);
        $caseCode = trim((string) request()->query('case_code', ''));
        $case = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')
            ->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
            ->select(
                'c.*',
                'ct.name as case_type',
                'c.applicant_id',
                'a.first_name as applicant_first_name',
                'a.middle_name as applicant_middle_name',
                'a.last_name as applicant_last_name',
                'a.email as applicant_record_email',
                'a.phone as applicant_record_phone'
            )
            ->where('c.case_number', trim($caseNumber))
            ->first();

        abort_if(!$case, 404);

        if (!$this->hasCaseAccess($case->id)) {
            $hasDbAccess = false;
            if (auth('applicant')->check()) {
                $applicant = auth('applicant')->user();
                $respondent = Respondent::where('email', $applicant->email)->first();
                if ($respondent) {
                    $hasDbAccess = DB::table('court_respondent_case_views')
                        ->where('respondent_id', $respondent->id)
                        ->where('case_id', $case->id)
                        ->exists();
                }
            }
            if (!$hasDbAccess) {
                abort_if(!$this->caseCodeMatches($case, $caseCode), 404);
            }
            $this->grantCaseAccess($case->id);
        }

        // Prevent applicants from viewing/searching their own cases while in respondent mode.
        $applicantId = Auth::guard('applicant')->id();
        if ($applicantId && (int) $case->applicant_id === (int) $applicantId) {
            abort(404);
        }

        if (auth('applicant')->check()) {
            $this->handleRespondentCaseView($case);
        }

        $caseId = $case->id;

        // The status timeline, case files and staff messages were loaded here for the Blade page but never
        // shown to the respondent; the React page receives only what it shows.
        $hearings = DB::table('court_case_hearings')
            ->where('case_id', $caseId)
            ->orderBy('hearing_at')
            ->get();

        $docs = DB::table('court_case_evidences')
            ->where('case_id', $caseId)
            ->where('type', 'document')
            ->orderBy('id')
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

        // Only published + approved decisions are downloadable by the respondent.
        $decisions = DB::table('court_decisions')
            ->select('id', 'name', 'case_number', 'decision_date', 'status', 'approved_at', 'created_at')
            ->where('case_number', $case->case_number)
            ->where('status', 'published')
            ->whereNotNull('approved_at')
            ->orderByDesc('decision_date')
            ->orderByDesc('created_at')
            ->get();

        $status = (string) ($case->status ?? 'pending');

        // The case as the respondent sees it (React, Court/Respondent/CaseShow). Named fields only: the
        // access code and the staff-side columns of court_cases stay on the server.
        return Inertia::render('Court/Respondent/CaseShow', [
            'case' => [
                'id' => (int) $case->id,
                'case_number' => (string) $case->case_number,
                'status' => $status === '' ? 'pending' : $status,
                'review_status' => (string) ($case->review_status ?? 'accepted') ?: 'accepted',
                'review_note' => $case->review_note ?? null,
                'case_type' => $case->case_type ?? null,
                'filed_at' => $this->iso($case->filing_date ?? $case->created_at ?? null),
                'applicant_name' => $case->applicant_name ?? $case->title ?? null,
                'applicant_email' => $case->applicant_email ?? null,
                'respondent_name' => $case->respondent_name ?? null,
                'respondent_address' => $case->respondent_address ?? null,
                'description_html' => $this->cleanHtml($case->description ?? null),
                'relief_requested_html' => $this->cleanHtml($case->relief_requested ?? null),
            ],
            'docs' => $docs->map(function (object $doc) use ($case): array {
                $path = $doc->file_path ?? $doc->path ?? null;

                return [
                    'id' => (int) $doc->id,
                    'title' => $doc->title ?? $doc->label ?? ($path ? basename((string) $path) : null),
                    'mime' => $doc->mime ?? null,
                    'size' => isset($doc->size) ? max(0, (int) $doc->size) : null,
                    'created_at' => $this->iso($doc->created_at ?? null),
                    'description_html' => $this->cleanHtml($doc->description ?? null),
                    'download_url' => $path ? route('court.applicant.cases.evidences.download', ['id' => $case->id, 'evidenceId' => $doc->id]) : null,
                ];
            })->values()->all(),
            'hearings' => $hearings->map(fn (object $hearing): array => [
                'id' => (int) $hearing->id,
                'hearing_at' => $this->iso($hearing->hearing_at ?? null),
                'type' => $hearing->type ?? null,
                'location' => $hearing->location ?? null,
                'notes' => $hearing->notes ?? null,
            ])->values()->all(),
            'letters' => $letters->map(fn (object $letter): array => [
                'id' => (int) $letter->id,
                'subject' => $letter->subject,
                'reference_number' => $letter->reference_number,
                'approval_status' => (string) ($letter->approval_status ?? 'draft'),
                'created_at' => $this->iso($letter->created_at),
                'template_title' => $letter->template_title,
                'author_name' => $letter->author_name,
                'preview_url' => $this->letterPreviewUrl((int) $letter->id),
            ])->values()->all(),
            'decisions' => $decisions->map(fn (object $decision): array => [
                'id' => (int) $decision->id,
                'name' => $decision->name,
                'date' => $this->iso($decision->decision_date ?: $decision->created_at),
                'download_url' => route('court.respondent.decisions.download', $decision->id),
            ])->values()->all(),
            // The Blade page always showed these two sections empty for a respondent.
            'witnesses' => [],
            'audits' => [],
        ]);
    }

    /**
     * Download the sealed decision PDF for a case the respondent has access to.
     * Only available once the decision is approved AND published.
     */
    public function downloadDecision(Decision $decision)
    {
        $caseId = DB::table('court_cases')->where('case_number', $decision->case_number)->value('id');

        abort_if(!$caseId, 404);
        abort_unless($this->hasCaseAccess((int) $caseId), 403);
        abort_unless($decision->isDownloadableByParties(), 403);

        return DecisionPdf::render($decision)->download(DecisionPdf::filename($decision));
    }

    private function handleRespondentCaseView(object $case): void
    {
        $respondent = $this->resolveActingRespondent();
        if (!$respondent) {
            return;
        }

        $respondentName = $this->formatRespondentName($respondent);
        $caseUrl = route('court.respondent.cases.show', $case->case_number);

        $this->recordRespondentCaseView($case->id, $respondent->id, $case->case_number ?? null);
        // Every view is recorded and audited, but the applicant and the court are told
        // at most once a day per respondent and case, not on every page load (#3, 4dd634b).
        if (Cache::add("respondent-viewed-notice:{$respondent->id}:{$case->id}", true, now()->addDay())) {
            $this->notifyRespondentViewParticipants($case, $respondentName, $caseUrl);
            ResponseNotificationService::notifyRespondentCaseViewed((int) $case->id, $respondentName);
        }
        $this->logRespondentCaseAudit($case->id, $respondent->id, $respondentName);
    }

    private function recordRespondentCaseView(int $caseId, ?int $respondentId, ?string $caseNumber): void
    {
        if (!$respondentId) {
            return;
        }

        try {
            $now = now();
            DB::table('court_respondent_case_views')->updateOrInsert(
                [
                    'respondent_id' => $respondentId,
                    'case_id' => $caseId,
                ],
                [
                    'case_number' => $caseNumber,
                    'viewed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to record respondent case view', [
                'case_id' => $caseId,
                'respondent_id' => $respondentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyRespondentViewParticipants(object $case, string $respondentName, string $caseUrl): void
    {
        $recipients = collect();

        $applicantEmail = data_get($case, 'applicant_record_email') ?? data_get($case, 'applicant_email');
        if ($applicantEmail) {
            $recipients->push($applicantEmail);
        }

        $adminEmail = null;
        if (!empty($case->assigned_user_id)) {
            $adminEmail = DB::table('court_staff')->where('id', $case->assigned_user_id)->value('email');
        }
        $adminEmail = $adminEmail ?: config('mail.from.address');

        if ($adminEmail) {
            $recipients->push($adminEmail);
        }

        foreach ($recipients->filter()->unique() as $email) {
            try {
                Mail::to($email)->send(new RespondentViewedCaseMail($case, $respondentName, $caseUrl));
            } catch (\Throwable $e) {
                Log::error('Failed to send respondent viewed case notification', [
                    'case_id' => $case->id,
                    'case_number' => $case->case_number ?? null,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function logRespondentCaseAudit(int $caseId, ?int $respondentId, string $respondentName): void
    {
        try {
            DB::table('court_case_audits')->insert([
                'case_id' => $caseId,
                'action' => 'respondent_viewed',
                'actor_type' => 'respondent',
                'actor_id' => $respondentId,
                'meta' => json_encode([
                    'respondent' => $respondentName,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log respondent view audit', [
                'case_id' => $caseId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatRespondentName(object $respondent): string
    {
        $name = trim($respondent->full_name ?? '');
        if ($name === '') {
            $name = trim(($respondent->first_name ?? '') . ' ' . ($respondent->last_name ?? ''));
        }
        return $name !== '' ? $name : ($respondent->email ?? 'Respondent');
    }

    public function myCases()
    {
        Session::put('acting_as_respondent', true);
        $respondent = $this->resolveActingRespondent();
        $cases = collect();

        if ($respondent) {
            $cases = DB::table('court_respondent_case_views as r')
                ->join('court_cases as c', 'c.id', '=', 'r.case_id')
                ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')
                ->select('c.*', 'ct.name as case_type', 'r.viewed_at')
                ->where('r.respondent_id', $respondent->id)
                ->orderByDesc('r.viewed_at')
                ->get();
        }

        // The cases this respondent has opened (React, Court/Respondent/MyCases).
        return Inertia::render('Court/Respondent/MyCases', [
            'cases' => $cases->map(fn (object $case): array => [
                'id' => (int) $case->id,
                'case_number' => (string) $case->case_number,
                'status' => (string) ($case->status ?? 'pending') ?: 'pending',
                'case_type' => $case->case_type ?? null,
                'viewed_at' => $this->iso($case->viewed_at ?? null),
                // The recorded view opens the case page; the access code stays out of the link.
                'show_url' => route('court.respondent.cases.show', ['caseNumber' => $case->case_number]),
            ])->values()->all(),
        ]);
    }

    private function resolveActingRespondent(): ?Respondent
    {
        $applicant = Auth::guard('applicant')->user();
        if (!$applicant) {
            return null;
        }

        $respondent = Respondent::where('email', $applicant->email)->first();
        if (!$respondent) {
            $phone = $applicant->phone ?? 'resp_' . substr(md5((string) microtime(true)), 0, 12);
            if (Respondent::where('phone', $phone)->where('email', '!=', $applicant->email)->exists()) {
                $phone = 'resp_' . substr(md5(uniqid('', true)), 0, 12);
            }

            $respondent = Respondent::create([
                'first_name'        => $applicant->first_name ?? '',
                'middle_name'       => $applicant->middle_name ?? '',
                'last_name'         => $applicant->last_name ?? '',
                'gender'            => $applicant->gender ?? null,
                'position'          => $applicant->position ?? '',
                'organization_name' => $applicant->organization_name ?? '',
                'address'           => $applicant->address ?? '',
                'national_id'       => $this->applicantNationalId($applicant),
                'phone'             => $phone,
                'email'             => $applicant->email,
                'password'          => \Illuminate\Support\Str::random(32),
            ]);
        } else {
            $dirty = false;
            $maybeNationalId = $this->applicantNationalId($applicant);
            if (!$respondent->national_id && $maybeNationalId) {
                $respondent->national_id = $maybeNationalId;
                $dirty = true;
            }
            if ($dirty) {
                $respondent->save();
            }
        }

        return $respondent;
    }

    private function applicantNationalId($applicant): ?string
    {
        // Normalize to digits-only and trim to 16 characters to respect DB column length.
        $digits = preg_replace('/\D/', '', (string) ($applicant->getRawOriginal('national_id_number') ?? $applicant->national_id_number ?? ''));
        if ($digits === '') {
            return null;
        }
        return substr($digits, 0, 16);
    }

    private function caseCodeMatches(object $case, string $provided): bool
    {
        $stored = $this->normalizeCaseCode((string) ($case->code ?? ''));
        $input = $this->normalizeCaseCode($provided);

        if ($stored === '' || $input === '') {
            return false;
        }

        return hash_equals($stored, $input);
    }

    private function findCaseForSearch(string $caseNumber): ?object
    {
        $select = ['id', 'case_number', 'code', 'title', 'status', 'created_at', 'applicant_id'];

        $exact = DB::table('court_cases')
            ->select($select)
            ->where('case_number', $caseNumber)
            ->first();
        if ($exact) {
            return $exact;
        }

        $normalizedInput = $this->normalizeCaseNumber($caseNumber);
        if ($normalizedInput === '') {
            return null;
        }

        // Fallback for copied values that include hidden/variant separators or extra spaces.
        return DB::table('court_cases')
            ->select($select)
            ->get()
            ->first(fn ($row) => $this->normalizeCaseNumber((string) ($row->case_number ?? '')) === $normalizedInput);
    }

    private function normalizeCaseNumber(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Remove invisible format characters and all spaces.
        $value = preg_replace('/\p{Cf}+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', '', $value) ?? $value;

        // Normalize common slash variants users may paste.
        $value = str_replace(['／', '⁄', '∕', '\\'], '/', $value);

        return mb_strtoupper($value, 'UTF-8');
    }

    private function normalizeCaseCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $map = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/\s+/u', '', $value) ?? $value;

        return mb_strtoupper($value, 'UTF-8');
    }

    private function grantCaseAccess(int $caseId): void
    {
        $access = session('respondent_case_access', []);
        $access[$caseId] = now()->timestamp;
        session(['respondent_case_access' => $access]);
    }

    private function hasCaseAccess(int $caseId): bool
    {
        $access = session('respondent_case_access', []);
        if (!is_array($access)) {
            return false;
        }

        $lifetime = (int) config('session.lifetime', 120);
        $threshold = now()->subMinutes($lifetime)->timestamp;
        $access = array_filter($access, fn ($ts) => is_int($ts) && $ts >= $threshold);

        session(['respondent_case_access' => $access]);

        return array_key_exists($caseId, $access);
    }
}
