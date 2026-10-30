<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\Grievance\GrievanceDispatchChannel;
use App\Enums\Grievance\GrievanceLetterLanguage;
use App\Enums\Grievance\GrievanceLetterStatus;
use App\Enums\Grievance\GrievanceLetterType;
use App\Enums\Grievance\GrievanceRecipientKind;
use App\Enums\Grievance\GrievanceRecipientType;
use App\Enums\Grievance\GrievanceSignatureMethod;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceLetter;
use App\Models\GrievanceLetterDispatch;
use App\Models\OrganizationSeal;
use App\Services\Grievances\GrievanceCaseAccessService;
use App\Services\Grievances\GrievanceCorrespondenceService;
use App\Services\Grievances\GrievancePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Official grievance correspondence: register, drafting, signing, sealing, issue, dispatch. */
class GrievanceCorrespondenceController extends Controller
{
    public function __construct(
        private readonly GrievanceCorrespondenceService $letters,
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievancePresenter $presenter,
    ) {}

    /** Correspondence register: letters of cases the user may open (plus registry scope). */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievance_correspondence.view'), 403);

        $query = GrievanceLetter::query()->with(['grievance:id,reference_number,organization_id,confidentiality_level', 'signatoryEmployee'])
            ->where(function ($q) use ($user): void {
                $q->whereIn('grievance_id', $this->access->constrainAuthorized(Grievance::query(), $user)->select('grievances.id'));
                if ($user->can('grievance_correspondence.apply_seal') || $user->can('grievance_correspondence.issue')) {
                    $q->orWhere(function ($r) use ($user): void {
                        $r->where('status', '!=', GrievanceLetterStatus::Draft->value);
                        $this->access->applyPermissionScope($r, $user, 'grievance_correspondence.view');
                    });
                }
            });

        foreach (['status', 'letter_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->string($filter));
            }
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('reference_number', 'like', "%{$term}%")->orWhereHas('grievance', fn ($g) => $g->where('reference_number', 'like', "%{$term}%")));
        }

        $page = $query->latest()->paginate(25)->withQueryString();
        $page->through(fn (GrievanceLetter $l) => [
            ...$this->presenter->letter($l, false),
            'grievance_id' => $l->grievance_id,
            'case_number' => $l->grievance?->reference_number,
        ]);

        return Inertia::render('Grievances/Correspondence/Index', [
            'letters' => $page,
            'filters' => $request->only(['status', 'letter_type', 'search']),
            'options' => ['statuses' => GrievanceLetterStatus::values(), 'types' => GrievanceLetterType::values()],
            'seals' => $user->can('grievance_correspondence.apply_seal') ? OrganizationSeal::query()->where('status', 'active')->whereNotNull('approved_at')->get(['id', 'name', 'organization_id']) : [],
            'can' => [
                'seal' => $user->can('grievance_correspondence.apply_seal'),
                'issue' => $user->can('grievance_correspondence.issue'),
            ],
        ]);
    }

    public function store(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'letter_type' => ['required', Rule::in(GrievanceLetterType::values())],
            'language' => ['required', Rule::in(GrievanceLetterLanguage::values())],
            'decision_id' => ['nullable', 'uuid'],
            'hearing_id' => ['nullable', 'uuid'],
            'information_request_id' => ['nullable', 'uuid'],
            'appeal_id' => ['nullable', 'uuid'],
        ]);
        $this->letters->generate($grievance, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.letter_generated'), 'type' => 'success']);
    }

    public function update(Request $request, Grievance $grievance, GrievanceLetter $letter): RedirectResponse
    {
        $this->owned($grievance, $letter);
        $data = $request->validate([
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string', 'max:30000'],
            'visible_to_complainant' => ['sometimes', 'boolean'],
            'recipients' => ['sometimes', 'array', 'max:40'],
            'recipients.*.kind' => ['required', Rule::in(GrievanceRecipientKind::values())],
            'recipients.*.recipient_type' => ['required', Rule::in(GrievanceRecipientType::values())],
            'recipients.*.recipient_id' => ['nullable', 'string', 'max:64'],
            'recipients.*.name' => ['required', 'string', 'max:255'],
            'recipients.*.position_title' => ['nullable', 'string', 'max:255'],
            'recipients.*.organization_name' => ['nullable', 'string', 'max:255'],
            'recipients.*.address' => ['nullable', 'string', 'max:500'],
            'recipients.*.email' => ['nullable', 'email', 'max:255'],
            'attachments' => ['sometimes', 'array', 'max:30'],
            'attachments.*.attachment_type' => ['required', 'in:decision,investigation_report,minutes,supporting_report,evidence,other'],
            'attachments.*.title' => ['required', 'string', 'max:255'],
            'attachments.*.evidence_id' => ['nullable', 'uuid'],
            'attachments.*.reference_id' => ['nullable', 'string', 'max:64'],
        ]);
        $this->letters->updateDraft($letter, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function transition(Request $request, Grievance $grievance, GrievanceLetter $letter): RedirectResponse
    {
        $this->owned($grievance, $letter);
        $data = $request->validate([
            'action' => ['required', 'in:finalize,sign,seal,issue,void,revise'],
            'signature_method' => ['required_if:action,sign', 'nullable', Rule::in(GrievanceSignatureMethod::values())],
            'seal_id' => ['required_if:action,seal', 'nullable', 'uuid', 'exists:organization_seals,id'],
            'reason' => ['required_if:action,void', 'nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();
        match ($data['action']) {
            'finalize' => $this->letters->finalize($letter, $user),
            'sign' => $this->letters->sign($letter, $user, GrievanceSignatureMethod::from((string) $data['signature_method'])),
            'seal' => $this->letters->applySeal($letter, $user, OrganizationSeal::query()->findOrFail($data['seal_id'])),
            'issue' => $this->letters->issue($letter, $user),
            'void' => $this->letters->void($letter, $user, (string) $data['reason']),
            'revise' => $this->letters->revise($letter, $user),
        };

        return back()->with('flash', ['message' => __('grievances.flash.letter_'.$data['action']), 'type' => 'success']);
    }

    public function dispatchLetter(Request $request, Grievance $grievance, GrievanceLetter $letter): RedirectResponse
    {
        $this->owned($grievance, $letter);
        $data = $request->validate([
            'channel' => ['required', Rule::in(GrievanceDispatchChannel::values())],
            'recipient_id' => ['nullable', 'uuid', Rule::exists('grievance_letter_recipients', 'id')->where('letter_id', $letter->getKey())],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $this->letters->dispatch($letter, $request->user(), GrievanceDispatchChannel::from($data['channel']), $data['recipient_id'] ?? null, $data['notes'] ?? null);

        return back()->with('flash', ['message' => __('grievances.flash.letter_dispatched'), 'type' => 'success']);
    }

    public function acknowledge(Request $request, Grievance $grievance, GrievanceLetter $letter, GrievanceLetterDispatch $dispatch): RedirectResponse
    {
        abort_unless($letter->grievance_id === $grievance->getKey() && $dispatch->letter_id === $letter->getKey(), 404);
        $this->letters->acknowledge($dispatch, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function preview(Request $request, Grievance $grievance, GrievanceLetter $letter): HttpResponse
    {
        $this->owned($grievance, $letter);

        return response($this->letters->preview($letter, $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Request $request, Grievance $grievance, GrievanceLetter $letter): StreamedResponse
    {
        $this->owned($grievance, $letter);

        return $this->letters->download($letter, $request->user());
    }

    private function owned(Grievance $grievance, GrievanceLetter $letter): void
    {
        abort_unless($letter->grievance_id === $grievance->getKey(), 404);
    }
}
