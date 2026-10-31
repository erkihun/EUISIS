<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Letter;
use Modules\Court\Application\Mail\LetterApprovedMail;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\LetterTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Modules\Court\Application\Support\Purifier;

class LetterController extends Controller
{
    public function index(Request $request)
    {
        $statusFilter = (string) $request->query('status', '');
        if (!in_array($statusFilter, ['', 'pending', 'approved', 'rejected'], true)) {
            $statusFilter = '';
        }
        $search = trim((string) $request->query('q', ''));

        $baseQuery = Letter::query();

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'approved' => (clone $baseQuery)->where('approval_status', 'approved')->count(),
            'rejected' => (clone $baseQuery)->where('approval_status', 'rejected')->count(),
            'category_counts' => (clone $baseQuery)
                ->leftJoin('court_letter_templates as lt', 'lt.id', '=', 'court_letters.letter_template_id')
                ->selectRaw('COALESCE(NULLIF(TRIM(lt.category), \'\'), ?) as category_label, COUNT(*) as aggregate_count', [__('court::letters.form.category_fallback')])
                ->groupBy('category_label')
                ->pluck('aggregate_count', 'category_label'),
        ];

        $letters = $baseQuery
            ->leftJoin('court_cases as c', 'c.case_number', '=', 'court_letters.case_number')
            ->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
            ->select('court.letters.*')
            ->selectRaw("NULLIF(TRIM(c.title), '') as applicant_name")
            ->selectRaw("NULLIF(TRIM(CONCAT_WS(' ', NULLIF(a.first_name, ''), NULLIF(a.middle_name, ''), NULLIF(a.last_name, ''))), '') as applicant_lawyer_name")
            ->selectRaw('COALESCE(a.is_lawyer, 0) as applicant_is_lawyer')
            ->selectRaw("NULLIF(TRIM(c.respondent_name), '') as respondent_name")
            ->when($statusFilter === 'approved', fn($query) => $query->where('court_letters.approval_status', 'approved'))
            ->when($statusFilter === 'rejected', fn($query) => $query->where('court_letters.approval_status', 'rejected'))
            ->when($statusFilter === 'pending', fn($query) => $query->where(function ($pendingQuery) {
                $pendingQuery
                    ->whereNull('court_letters.approval_status')
                    ->orWhere('court_letters.approval_status', '')
                    ->orWhere('court_letters.approval_status', 'pending');
            }))
            // Reference, subject, case number and the parties (recipient, applicant, respondent).
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('court_letters.reference_number', 'like', $like)
                        ->orWhere('court_letters.subject', 'like', $like)
                        ->orWhere('court_letters.case_number', 'like', $like)
                        ->orWhere('court_letters.recipient_name', 'like', $like)
                        ->orWhere('c.title', 'like', $like)
                        ->orWhere('c.respondent_name', 'like', $like);
                });
            })
            ->with(['template', 'author'])
            ->latest('court_letters.created_at')
            ->paginate(10)
            ->withQueryString();

        // The register (React, Court/Letters/Index); the row actions follow the Blade page's permissions.
        $user = $request->user();
        $can = fn (string $permission): bool => (bool) ($user?->hasPermission($permission) ?? false);
        $categories = collect($stats['category_counts']);

        return Inertia::render('Court/Letters/Index', [
            'letters' => $letters->through(fn (Letter $letter): array => [
                'id' => (int) $letter->id,
                'reference_number' => $letter->reference_number,
                'subject' => $letter->subject,
                'case_number' => $letter->case_number,
                'send_to_applicant' => (bool) $letter->send_to_applicant,
                'send_to_respondent' => (bool) $letter->send_to_respondent,
                'applicant_name' => $letter->applicant_name,
                'applicant_lawyer_name' => $letter->applicant_is_lawyer ? $letter->applicant_lawyer_name : null,
                'respondent_name' => $letter->respondent_name,
                'author_name' => $letter->author?->name,
                'author_position' => $letter->author?->position,
                'category' => $letter->template?->category,
                'approval_status' => $letter->approval_status ?: null,
                'approved_by_name' => $letter->approved_by_name,
                'created_at' => $letter->created_at?->toIso8601String(),
            ]),
            'stats' => [
                'total' => $stats['total'],
                'approved' => $stats['approved'],
                'rejected' => $stats['rejected'],
                'categories' => $categories->count(),
                'top_category' => $categories->sortDesc()->keys()->first(),
                'top_category_count' => (int) ($categories->sortDesc()->first() ?? 0),
            ],
            'filters' => ['status' => $statusFilter, 'q' => $search],
            'can' => [
                'create' => $can('letters.create'),
                'update' => $can('letters.update'),
                'delete' => $can('letters.delete'),
                'approve' => $can('letters.approve'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'template_id'       => ['required', 'exists:court_letter_templates,id'],
            'recipient_name'    => ['nullable', 'string', 'max:255'],
            'recipient_title'   => ['nullable', 'string', 'max:255'],
            'recipient_company' => ['nullable', 'string', 'max:255'],
            'subject'           => ['nullable', 'string', 'max:255'],
            'case_number'       => ['nullable', 'string', 'max:60'],
            'body'              => ['required', 'string'],
            'cc'                => ['nullable', 'string', 'max:255'],
            'send_to_applicant' => ['nullable', 'boolean'],
            'send_to_respondent'=> ['nullable', 'boolean'],
            'approved_by_name'  => ['nullable', 'string', 'max:255'],
            'approved_by_title' => ['nullable', 'string', 'max:255'],
        ]);

        $sendToApplicant = array_key_exists('send_to_applicant', $data) ? (bool) $data['send_to_applicant'] : true;
        $sendToRespondent = array_key_exists('send_to_respondent', $data) ? (bool) $data['send_to_respondent'] : true;

        if (!$sendToApplicant && !$sendToRespondent) {
            return back()
                ->withErrors(['send_to_applicant' => __('court::letters.form.delivery_required')])
                ->withInput();
        }

        // Block letters for closed cases
        if (!empty($data['case_number'])) {
            $case = CourtCase::where('case_number', $data['case_number'])->first();
            if ($case && ($case->status === 'closed')) {
                return back()
                    ->withErrors(['case_number' => __('court::messages.error.closed_case_letter_locked')])
                    ->withInput();
            }
        }

        $data['send_to_applicant'] = $sendToApplicant;
        $data['send_to_respondent'] = $sendToRespondent;
        $data['body'] = \Modules\Court\Application\Support\Purifier::clean(
            $this->applyTemplatePlaceholders(
                (string) ($data['body'] ?? ''),
                $data['case_number'] ?? null
            ),
            'default'
        );
        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        if ($recipientName === '') {
            $targets = [];
            $caseParty = null;
            if (!empty($data['case_number'])) {
                $caseParty = DB::table('court_cases as c')
                    ->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
                    ->select(
                        'c.respondent_name',
                        'c.title as applicant_name'
                    )
                    ->where('c.case_number', $data['case_number'])
                    ->first();
            }
            if ($sendToApplicant) {
                $targets[] = trim((string) ($caseParty->applicant_name ?? '')) ?: __('court::letters.form.deliver_applicant');
            }
            if ($sendToRespondent) {
                $targets[] = trim((string) ($caseParty->respondent_name ?? '')) ?: __('court::letters.form.deliver_respondent');
            }
            $recipientName = implode(', ', $targets);
        }
        $data['recipient_name'] = $recipientName;

        $letter          = null;
        $template        = LetterTemplate::findOrFail($data['template_id']);
        DB::transaction(function () use ($request, $data, $template, &$letter) {
            $lockedTemplate = LetterTemplate::where('id', $template->id)->lockForUpdate()->first();
            $lockedTemplate->reference_sequence = ($lockedTemplate->reference_sequence ?? 0) + 1;
            $lockedTemplate->save();

            $baseSubject = trim($data['subject'] ?? $lockedTemplate->title);
            $subjectValue = $baseSubject === '' ? null : $baseSubject;

            $baseSubject = trim($data['subject'] ?? $lockedTemplate->title);
            $subjectValue = $baseSubject === '' ? null : $baseSubject;

            // CASE NUMBER–BASED REFERENCE
            $caseNumber = $data['case_number'] ?? null;

            if ($caseNumber) {
                // Lock rows for this case during reference generation.
                $last = DB::table('court_letters')
                    ->where('case_number', $caseNumber)
                    ->lockForUpdate()
                    ->orderBy('id', 'desc')
                    ->first();

                if ($last && preg_match('/\/(\d{2})$/', $last->reference_number, $m)) {
                    $nextSeq = intval($m[1]) + 1;
                } else {
                    $nextSeq = 1;
                }

                do {
                    $seq = str_pad($nextSeq, 2, '0', STR_PAD_LEFT);
                    $referenceNumber = "{$caseNumber}/{$seq}";
                    $exists = DB::table('court_letters')
                        ->where('reference_number', $referenceNumber)
                        ->lockForUpdate()
                        ->exists();
                    $nextSeq++;
                } while ($exists);
            } else {
                $referenceNumber = null;
            }

            $letter = Letter::create([
                'letter_template_id' => $lockedTemplate->id,
                'user_id'            => $request->user()->id,
                'recipient_name'     => $data['recipient_name'],
                'recipient_title'    => $data['recipient_title'] ?? null,
                'recipient_company'  => $data['recipient_company'] ?? null,
                'subject'            => $subjectValue,
                'reference_number'   => $referenceNumber,
                'case_number'        => $data['case_number'] ?? null,
                'body'               => $data['body'],
                'cc'                 => $data['cc'] ?? null,
                'send_to_applicant'  => $data['send_to_applicant'],
                'send_to_respondent' => $data['send_to_respondent'],
                'approved_by_name'   => $data['approved_by_name'] ?? null,
                'approved_by_title'  => $data['approved_by_title'] ?? null,
            ]);
        });

        return redirect()->route('court.letters.show', $letter)->with('success', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.letter')],
        ]);
    }

    /**
     * The edit page (React, Court/Letters/Edit): the letter's fields as last
     * submitted when a save was refused, else as stored, and its template.
     */
    public function edit(Letter $letter)
    {
        // An approved letter has been sent to the parties; it is no longer editable.
        if ($letter->approval_status === 'approved') {
            return redirect()->route('court.letters.index')->with('error', __('court::messages.error.approved_letter_update_locked'));
        }

        $letter->load('template');
        $template = $letter->template;

        $nextSeq = ($template?->reference_sequence ?? 0) + 1;
        $nextReference = implode('/', array_filter([
            $template?->subject_prefix,
            str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT),
        ]));

        return Inertia::render('Court/Letters/Edit', [
            'letter' => [
                'id' => (int) $letter->id,
                'reference_number' => $letter->reference_number,
                'approval_status' => $letter->approval_status ?: null,
                'recipient_name' => (string) old('recipient_name', $letter->recipient_name ?? ''),
                'recipient_title' => (string) old('recipient_title', $letter->recipient_title ?? ''),
                'recipient_company' => (string) old('recipient_company', $letter->recipient_company ?? ''),
                'case_number' => (string) old('case_number', $letter->case_number ?? ''),
                'cc' => (string) old('cc', $letter->cc ?? ''),
                'send_to_applicant' => filter_var(old('send_to_applicant', $letter->send_to_applicant ?? true), FILTER_VALIDATE_BOOLEAN),
                'send_to_respondent' => filter_var(old('send_to_respondent', $letter->send_to_respondent ?? true), FILTER_VALIDATE_BOOLEAN),
                'subject' => (string) old('subject', $letter->subject ?? ''),
                'body' => (string) old('body', $letter->body ?? ''),
            ],
            'template' => [
                'id' => (int) ($template?->id ?? 0),
                'title' => (string) ($template?->title ?? ''),
                'subject_prefix' => $template?->subject_prefix ?: null,
                'next_reference' => $nextReference,
            ],
        ]);
    }

    public function update(Request $request, Letter $letter)
    {
        if ($letter->approval_status === 'approved') {
            return back()->with('error', __('court::messages.error.approved_letter_update_locked'));
        }

        $data = $request->validate([
            'recipient_name'    => ['nullable', 'string', 'max:255'],
            'recipient_title'   => ['nullable', 'string', 'max:255'],
            'recipient_company' => ['nullable', 'string', 'max:255'],
            'subject'           => ['nullable', 'string', 'max:255'],
            'case_number'       => ['nullable', 'string', 'max:60'],
            'body'              => ['required', 'string'],
            'cc'                => ['nullable', 'string', 'max:255'],
            'send_to_applicant' => ['nullable', 'boolean'],
            'send_to_respondent'=> ['nullable', 'boolean'],
            'approved_by_name'  => ['nullable', 'string', 'max:255'],
            'approved_by_title' => ['nullable', 'string', 'max:255'],
        ]);

        $sendToApplicant = array_key_exists('send_to_applicant', $data)
            ? (bool) $data['send_to_applicant']
            : ($letter->send_to_applicant ?? true);
        $sendToRespondent = array_key_exists('send_to_respondent', $data)
            ? (bool) $data['send_to_respondent']
            : ($letter->send_to_respondent ?? true);

        if (!$sendToApplicant && !$sendToRespondent) {
            return back()
                ->withErrors(['send_to_applicant' => __('court::letters.form.delivery_required')])
                ->withInput();
        }

        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        if ($recipientName === '') {
            $targets = [];
            $caseParty = null;
            $caseNumber = trim((string) ($data['case_number'] ?? $letter->case_number ?? ''));
            if ($caseNumber !== '') {
                $caseParty = DB::table('court_cases as c')
                    ->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
                    ->select(
                        'c.respondent_name',
                        'c.title as applicant_name'
                    )
                    ->where('c.case_number', $caseNumber)
                    ->first();
            }
            if ($sendToApplicant) {
                $targets[] = trim((string) ($caseParty->applicant_name ?? '')) ?: __('court::letters.form.deliver_applicant');
            }
            if ($sendToRespondent) {
                $targets[] = trim((string) ($caseParty->respondent_name ?? '')) ?: __('court::letters.form.deliver_respondent');
            }
            $recipientName = implode(', ', $targets);
        }
        if ($recipientName === '') {
            $recipientName = (string) ($letter->recipient_name ?? '');
        }
        $data['recipient_name'] = $recipientName;
        $resolvedCaseNumber = array_key_exists('case_number', $data)
            ? (trim((string) ($data['case_number'] ?? '')) ?: null)
            : ($letter->case_number ?: null);
        $data['body'] = \Modules\Court\Application\Support\Purifier::clean(
            $this->applyTemplatePlaceholders(
                (string) ($data['body'] ?? ''),
                $resolvedCaseNumber
            ),
            'default'
        );

        $letter->load('template');

        $baseSubject = trim($data['subject'] ?? $letter->template->title);
        $subjectValue = $baseSubject === '' ? null : $baseSubject;

        $letter->update([
            'recipient_name'    => $data['recipient_name'],
            'recipient_title'   => $data['recipient_title'] ?? null,
            'recipient_company' => $data['recipient_company'] ?? null,
            'subject'           => $subjectValue,
            'case_number'       => $resolvedCaseNumber,
            'body'              => $data['body'],
            'cc'                => $data['cc'] ?? null,
            'send_to_applicant'  => $sendToApplicant,
            'send_to_respondent' => $sendToRespondent,
            'approved_by_name'   => $data['approved_by_name'] ?? null,
            'approved_by_title'  => $data['approved_by_title'] ?? null,
        ]);

        return redirect()->route('court.letters.show', $letter)->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.letter')],
        ]);
    }

    public function destroy(Letter $letter)
    {
        if ($letter->approval_status === 'approved') {
            return back()->with('error', 'court::messages.error.approved_letter_delete_locked');
        }

        $letter->delete();

        return redirect()->route('court.letters.index')->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.letter')],
        ]);
    }

    public function show(Letter $letter)
    {
        $letter->load(['template', 'author']);

        return view('court::admin.letters.preview', [
            'letter' => $letter,
            'template' => $letter->template,
        ]);
    }

    /**
     * Allow applicants/respondents (by case) to view a letter preview without /admin prefix.
     */
    public function publicPreview(Request $request, Letter $letter)
    {
        $letter->load(['template', 'author']);

        $sendToApplicant = $letter->send_to_applicant ?? true;
        $sendToRespondent = $letter->send_to_respondent ?? true;
        $case = null;
        if ($letter->case_number) {
            $case = CourtCase::where('case_number', $letter->case_number)->first();
        }

        $applicantId = auth('applicant')->id();
        $respondentId = auth('respondent')->id();
        $isAdmin = auth()->check();

        $authorized = URL::hasValidSignature($request);

        // Admin/staff (already signed in via default guard)
        if ($isAdmin) {
            $authorized = true;
        }

        // Applicant owns the case
        if (
            !$authorized
            && $sendToApplicant
            && $case
            && $applicantId
            && (int) $case->applicant_id === (int) $applicantId
        ) {
            $authorized = true;
        }

        // Respondent has viewed this case (association via respondent_case_views)
        if (!$authorized && $sendToRespondent && $case && $respondentId) {
            $hasAccess = DB::table('court_respondent_case_views')
                ->where('respondent_id', $respondentId)
                ->where('case_id', $case->id)
                ->exists();
            if ($hasAccess) {
                $authorized = true;
            }
        }

        abort_unless($authorized, 403);

        return view('court::admin.letters.preview', [
            'letter' => $letter,
            'template' => $letter->template,
        ]);
    }

    public function approve(Request $request, Letter $letter)
    {
        $user = $request->user();

        $data = $request->validate([
            'status' => 'required|in:approved,returned,rejected',
        ]);

        // An approved letter has been sent to the parties; it can no longer change state
        // (deciding it again re-sent the approval emails).
        if ($letter->approval_status === 'approved') {
            return back()->with('error', __('court::messages.error.approved_letter_update_locked'));
        }

        $letter->update([
            'approved_by_name'  => $user?->name,
            'approved_by_title' => $user?->position,
            'approval_status'   => $data['status'],
        ]);

        if ($data['status'] === 'approved') {
            $this->notifyPartiesOfApprovedLetter($letter);
            $this->createSystemCaseMessage($letter, $user?->id);
            // A letter to an EUISIS institution also goes to EUISIS, which delivers it (Flow 5).
            app(\Modules\Court\Application\Services\Euisis\LetterDeliveries::class)->record($letter);
        }

        return redirect()->route('court.letters.index')->with('success', [
            'key' => 'court::messages.success.letter_status',
            'replace' => ['status' => __('court::messages.letter_status_values.' . $data['status'])],
        ]);
    }

    /**
     * Email the approved letter preview to the applicant and any respondents tied to the case number.
     */
    protected function notifyPartiesOfApprovedLetter(Letter $letter): void
    {
        if (!$letter->case_number) {
            return;
        }

        $sendToApplicant = $letter->send_to_applicant ?? true;
        $sendToRespondent = $letter->send_to_respondent ?? true;
        $case = CourtCase::with('applicant')->where('case_number', $letter->case_number)->first();

        $recipients = collect();

        if ($sendToApplicant && $case?->applicant?->email) {
            $recipients->push([
                'email' => $case->applicant->email,
                'name'  => $case->applicant->full_name ?? $case->applicant->email,
            ]);
        }

        // Respondents who have viewed the case
        if ($sendToRespondent && $case) {
            $respondents = DB::table('court_respondent_case_views as v')
                ->join('court_respondents as r', 'r.id', '=', 'v.respondent_id')
                ->where('v.case_id', $case->id)
                ->select('r.email', 'r.first_name', 'r.middle_name', 'r.last_name')
                ->distinct()
                ->get();

            foreach ($respondents as $resp) {
                if (!$resp->email) {
                    continue;
                }
                $recipients->push([
                    'email' => $resp->email,
                    'name'  => trim("{$resp->first_name} {$resp->middle_name} {$resp->last_name}") ?: $resp->email,
                ]);
            }
        }

        // Fallback: respondents who submitted responses with this case number
        if ($sendToRespondent) {
            $responses = DB::table('court_respondent_responses as rr')
                ->join('court_respondents as r', 'r.id', '=', 'rr.respondent_id')
                ->where('rr.case_number', $letter->case_number)
                ->select('r.email', 'r.first_name', 'r.middle_name', 'r.last_name')
                ->distinct()
                ->get();

            foreach ($responses as $resp) {
                if (!$resp->email) {
                    continue;
                }
                $recipients->push([
                    'email' => $resp->email,
                    'name'  => trim("{$resp->first_name} {$resp->middle_name} {$resp->last_name}") ?: $resp->email,
                ]);
            }
        }

        $uniqueRecipients = $recipients->unique('email');

        foreach ($uniqueRecipients as $recipient) {
            Mail::to($recipient['email'])->send(
                new LetterApprovedMail($letter, $case, $recipient['name'])
            );
        }
    }

    /**
     * Create an in-app case message so applicants/respondents see an approval notification.
     */
    protected function createSystemCaseMessage(Letter $letter, ?int $senderUserId = null): void
    {
        if (!$letter->case_number) {
            return;
        }

        $caseId = DB::table('court_cases')
            ->where('case_number', $letter->case_number)
            ->value('id');

        if (!$caseId) {
            return;
        }

        $previewUrl = Route::has('court.letters.case-preview')
            ? URL::signedRoute('court.letters.case-preview', ['letter' => $letter->getKey()])
            : URL::to('/court/case-letters/' . $letter->getKey());

        $subject = $letter->subject ?: optional($letter->template)->title ?: __('court::cases.notifications.letter');
        $body = __('court::cases.notifications.approved_letter_subject', ['subject' => $subject])
            . "\n"
            . __('court::cases.notifications.preview_link', ['url' => $previewUrl]);

        DB::table('court_case_messages')->insert([
            'case_id'            => $caseId,
            'sender_applicant_id'=> null,
            'sender_user_id'     => $senderUserId,
            'body'               => $body,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function applyTemplatePlaceholders(string $content, ?string $caseNumber): string
    {
        if ($content === '') {
            return $content;
        }

        $caseNumber = trim((string) $caseNumber);
        if ($caseNumber === '') {
            return $content;
        }

        $case = CourtCase::with('applicant')
            ->where('case_number', $caseNumber)
            ->first();

        if (!$case) {
            return $content;
        }

        $applicant = $case->applicant;
        $map = [
            '{case_number}' => (string) ($case->case_number ?? ''),
            '{case_code}' => (string) ($case->code ?? ''),
            '{case_title}' => (string) ($case->title ?? ''),
            '{case_filing_date}' => (string) ($case->filing_date ? \Modules\Court\Application\Support\EthiopianDate::format($case->filing_date) : ''),
            '{case_status}' => (string) ($case->status ?? ''),
            '{respondent_name}' => (string) ($case->respondent_name ?? ''),
            '{applicant_name}' => (string) ($case->title ?? ''),
            '{applicant_first_name}' => (string) ($applicant?->first_name ?? ''),
            '{applicant_middle_name}' => (string) ($applicant?->middle_name ?? ''),
            '{applicant_last_name}' => (string) ($applicant?->last_name ?? ''),
            '{applicant_email}' => (string) ($applicant?->email ?? ''),
            '{applicant_phone}' => (string) ($applicant?->phone ?? ''),
            '{applicant_address}' => (string) ($applicant?->address ?? ''),
        ];

        return strtr($content, $map);
    }
}
