<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discipline;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Discipline\DisciplinaryAppealOutcome;
use App\Enums\Discipline\DisciplinaryCaseStatus;
use App\Enums\Discipline\DisciplinaryEvidenceSide;
use App\Enums\Discipline\DisciplinaryFinding;
use App\Enums\Discipline\DisciplinaryHeadAction;
use App\Enums\Discipline\DisciplinaryObjectionGround;
use App\Enums\Discipline\DisciplinaryPenalty;
use App\Enums\Discipline\DisciplinarySeverity;
use App\Enums\Discipline\DisciplinaryTrack;
use App\Http\Controllers\Controller;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseMember;
use App\Models\DisciplinaryEvidence;
use App\Models\Employee;
use App\Services\Discipline\DisciplinaryCaseAccess;
use App\Services\Discipline\DisciplinaryCasePresenter;
use App\Services\Discipline\DisciplinaryCaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff side of Employee Discipline. Controllers validate the shape of input
 * and present data; every rule and permission is enforced again in
 * DisciplinaryCaseService / DisciplinaryCaseAccess.
 */
class DisciplinaryCaseController extends Controller
{
    public function __construct(
        private readonly DisciplinaryCaseService $cases,
        private readonly DisciplinaryCaseAccess $access,
        private readonly DisciplinaryCasePresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->access->authorize($user->can('discipline.view') || $user->can('discipline.investigate') || $user->can('discipline.decide'));
        $locale = app()->getLocale();

        $query = $this->access->constrainList(DisciplinaryCase::query(), $user)->with(['accused', 'organization'])->latest();
        if (in_array($request->string('status')->toString(), DisciplinaryCaseStatus::values(), true)) {
            $query->where('status', $request->string('status')->toString());
        }
        if (in_array($request->string('track')->toString(), ['expedited', 'formal'], true)) {
            $query->where('track', $request->string('track')->toString());
        }
        if (($search = trim($request->string('search')->toString())) !== '') {
            $query->where(fn ($q) => $q->where('case_number', 'like', "%{$search}%")
                ->orWhereHas('accused', fn ($a) => $a->where('employee_number', 'like', "%{$search}%")));
        }

        return Inertia::render('Discipline/Index', [
            'cases' => $query->paginate(20)->withQueryString()->through(fn (DisciplinaryCase $c): array => $this->presenter->row($c, $locale)),
            'filters' => $request->only(['status', 'track', 'search']),
            'statuses' => DisciplinaryCaseStatus::values(),
            'canCreate' => $user->can('discipline.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->access->authorize($request->user()->can('discipline.create'));

        return Inertia::render('Discipline/Create', [
            'tracks' => array_column(DisciplinaryTrack::cases(), 'value'),
            'severities' => array_column(DisciplinarySeverity::cases(), 'value'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:60'],
            'track' => ['required', Rule::enum(DisciplinaryTrack::class)],
            'severity' => ['required', Rule::enum(DisciplinarySeverity::class)],
            'summary' => ['nullable', 'string', 'max:2000'],
        ]);
        // Unknown and out-of-scope employees get the same answer, so the form
        // cannot be used to discover employee numbers in other institutions.
        $employee = Employee::query()->where('employee_number', $data['employee_number'])->first();
        $organizationId = $employee?->currentAssignment?->organization_id;
        if ($employee === null || ! $this->access->canCreateIn($request->user(), $organizationId)) {
            return back()->withErrors(['employee_number' => __('validation.exists', ['attribute' => 'employee number'])]);
        }

        $case = $this->cases->open($request->user(), $employee, $data);

        return to_route('discipline.cases.show', $case)->with('success', __('discipline.flash.opened'));
    }

    public function show(Request $request, DisciplinaryCase $case, WriteAuditLogAction $audit): Response|RedirectResponse
    {
        $user = $request->user();
        if ($this->access->isAccused($user, $case)) {
            return to_route('employee.discipline.show', $case);
        }
        $this->access->authorize($this->access->canView($user, $case));
        $audit->execute(AuditEventType::DisciplinaryCaseViewed, $user, $case, $case->organization_id, null, ['case_number' => $case->case_number], null, $request);

        $locale = app()->getLocale();

        return Inertia::render('Discipline/Show', [
            'disciplinaryCase' => $this->presenter->detail($case, $user, $locale),
            'abilities' => $this->presenter->abilities($case, $user),
            'committees' => $case->status === DisciplinaryCaseStatus::Draft ? $this->presenter->committeeOptions($case, $locale) : [],
            'votingMembers' => $case->status === DisciplinaryCaseStatus::UnderInvestigation ? $this->presenter->votingMembers($case) : [],
            'options' => $this->options(),
        ]);
    }

    // ── Charge stage ─────────────────────────────────────────────────────────

    public function addCharge(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->addCharge($case, $request->user(), $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'particulars' => ['required', 'string', 'max:5000'],
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'occurred_place' => ['required', 'string', 'max:255'],
            'violated_provision' => ['required', 'string', 'max:255'],
            'evidence_list' => ['required', 'string', 'max:5000'],
        ]));

        return back()->with('success', __('discipline.flash.charge_added'));
    }

    public function assignPanel(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate([
            'committee_id' => ['nullable', 'uuid'],
            'investigator_numbers' => ['nullable', 'array', 'max:5'],
            'investigator_numbers.*' => ['string', 'max:60'],
        ]);
        $investigators = Employee::query()->whereIn('employee_number', $data['investigator_numbers'] ?? [])->pluck('id')->all();
        $this->cases->assignPanel($case, $request->user(), $data['committee_id'] ?? null, $investigators);

        return back()->with('success', __('discipline.flash.panel_assigned'));
    }

    public function fileCharge(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->fileCharge($case, $request->user());

        return back()->with('success', __('discipline.flash.charged'));
    }

    public function serveSummons(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->serveSummons($case, $request->user(), $request->validate([
            'served_at' => ['required', 'date'],
            'method' => ['required', Rule::in(['personal', 'family_member', 'posted'])],
            'hearing_at' => ['required', 'date', 'after:served_at'],
            'hearing_location' => ['required', 'string', 'max:255'],
        ]));

        return back()->with('success', __('discipline.flash.summons_served'));
    }

    // ── Investigation ────────────────────────────────────────────────────────

    public function recordResponse(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->recordResponse($case, $request->user(), $this->validatedResponse($request));

        return back()->with('success', __('discipline.flash.response_recorded'));
    }

    public function decideObjection(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate(['accepted' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $this->cases->decideObjection($case, $request->user(), (bool) $data['accepted'], $data['notes'] ?? null);

        return back()->with('success', __('discipline.flash.objection_decided'));
    }

    public function proceedInAbsentia(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->cases->proceedInAbsentia($case, $request->user(), $data['reason']);

        return back()->with('success', __('discipline.flash.absentia'));
    }

    public function addEvidence(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $this->validatedEvidence($request);
        $this->cases->addEvidence($case, $request->user(), $data, $request->file('file'));

        return back()->with('success', __('discipline.flash.evidence_added'));
    }

    public function downloadEvidence(Request $request, DisciplinaryCase $case, DisciplinaryEvidence $evidence): StreamedResponse
    {
        [$disk, $path, $name, $mime] = $this->cases->evidenceDownload($case, $evidence, $request->user());

        return Storage::disk($disk)->download($path, $name, ['Content-Type' => $mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function recordWitness(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->recordWitness($case, $request->user(), $request->validate([
            'called_by' => ['required', Rule::enum(DisciplinaryEvidenceSide::class)],
            'name' => ['required', 'string', 'max:255'],
            'age' => ['nullable', 'integer', 'min:14', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'occupation' => ['required', 'string', 'max:255'],
            'relationship_disclosure' => ['required', 'string', 'max:1000'],
            'statement' => ['required', 'string', 'max:10000'],
            'cross_examination' => ['nullable', 'string', 'max:10000'],
            'committee_questions' => ['nullable', 'string', 'max:10000'],
            'written_testimony' => ['boolean'],
            'heard_in_absence_of_other_party' => ['boolean'],
            'heard_at' => ['required', 'date', 'before_or_equal:now'],
        ]));

        return back()->with('success', __('discipline.flash.witness_recorded'));
    }

    public function recuse(Request $request, DisciplinaryCase $case, DisciplinaryCaseMember $member): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'replacement_employee_number' => ['nullable', 'string', 'max:60'],
        ]);
        $replacement = isset($data['replacement_employee_number'])
            ? Employee::query()->where('employee_number', $data['replacement_employee_number'])->value('id')
            : null;
        if (isset($data['replacement_employee_number']) && $replacement === null) {
            return back()->withErrors(['replacement_employee_number' => __('validation.exists', ['attribute' => 'employee number'])]);
        }
        $this->cases->recuse($case, $request->user(), $member, $data['reason'], $replacement);

        return back()->with('success', __('discipline.flash.recused'));
    }

    public function finalStatement(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate(['statement' => ['nullable', 'string', 'max:10000'], 'declined' => ['boolean']]);
        $this->cases->recordFinalStatement($case, $request->user(), $data['statement'] ?? null, (bool) ($data['declined'] ?? false));

        return back()->with('success', __('discipline.flash.final_statement'));
    }

    public function extend(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->requireAdditionalEvidence($case, $request->user());

        return back()->with('success', __('discipline.flash.extended'));
    }

    public function recommend(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->adoptRecommendation($case, $request->user(), $request->validate([
            'finding' => ['required', Rule::enum(DisciplinaryFinding::class)],
            'penalty' => ['nullable', Rule::enum(DisciplinaryPenalty::class)],
            'penalty_amount' => ['nullable', 'integer', 'min:1', 'max:24'],
            'gravity_assessment' => ['nullable', 'string', 'max:5000'],
            'conduct_assessment' => ['nullable', 'string', 'max:5000'],
            'prior_record_assessment' => ['nullable', 'string', 'max:5000'],
            'reasoning' => ['required', 'string', 'max:10000'],
            'votes' => ['nullable', 'array', 'max:10'],
            'votes.*.vote' => ['required', Rule::in(['for', 'against'])],
            'votes.*.dissent_reason' => ['nullable', 'string', 'max:5000'],
        ]));

        return back()->with('success', __('discipline.flash.recommendation'));
    }

    // ── Decision ─────────────────────────────────────────────────────────────

    public function decide(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->decide($case, $request->user(), $request->validate([
            'action' => ['required', Rule::enum(DisciplinaryHeadAction::class)],
            'finding' => ['nullable', Rule::enum(DisciplinaryFinding::class)],
            'penalty' => ['nullable', Rule::enum(DisciplinaryPenalty::class)],
            'penalty_amount' => ['nullable', 'integer', 'min:1', 'max:24'],
            'reason' => ['required', 'string', 'max:10000'],
        ]));

        return back()->with('success', __($request->input('action') === DisciplinaryHeadAction::ReferToCommittee->value ? 'discipline.flash.referred' : 'discipline.flash.decided'));
    }

    public function deliver(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->recordDelivery($case, $request->user(), $request->validate([
            'delivered_at' => ['required', 'date'],
            'method' => ['required', Rule::in(['personal', 'family_member', 'posted'])],
            'acknowledged_at' => ['nullable', 'date', 'after_or_equal:delivered_at'],
        ]));

        return back()->with('success', __('discipline.flash.delivered'));
    }

    /** Art. 12(1): the head's suspension order, with its written reason and how it was served. */
    public function suspend(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->suspend($case, $request->user(), $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'without_pay' => ['required', 'boolean'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'required_if_accepted:without_pay'],
            'reason' => ['required', 'string', 'max:5000'],
            'served_at' => ['required', 'date'],
            'service_method' => ['required', Rule::in(['personal', 'family_member', 'posted'])],
        ]));

        return back()->with('success', __('discipline.flash.suspended'));
    }

    public function liftSuspension(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate(['lifted_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->cases->liftSuspension($case, $request->user(), $data['lifted_on'], $data['reason']);

        return back()->with('success', __('discipline.flash.suspension_lifted'));
    }

    public function suspensionBackPay(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate([
            'suspension_id' => ['required', 'uuid'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->cases->recordSuspensionBackPay($case, $request->user(), $data['suspension_id'], $data['paid_on'], $data['note'] ?? null);

        return back()->with('success', __('discipline.flash.suspension_back_paid'));
    }

    /** Art. 37(1): the employee's appeal notice, optionally asking for the committee record. */
    public function appealNotice(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->recordAppealNotice($case, $request->user(), $request->validate([
            'notice_received_at' => ['required', 'date'],
            'record_copy_requested' => ['required', 'boolean'],
        ]));

        return back()->with('success', __('discipline.flash.appeal_noticed'));
    }

    /** Art. 37(2): the sealed, certified record copy handed over. */
    public function appealRecordCopy(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate(['delivered_at' => ['required', 'date']]);
        $this->cases->recordAppealRecordCopy($case, $request->user(), $data['delivered_at']);

        return back()->with('success', __('discipline.flash.appeal_record_copy'));
    }

    public function appealFiling(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate([
            'filed_on' => ['required', 'date_format:Y-m-d'],
            'court_case_number' => ['nullable', 'string', 'max:100'],
        ]);
        $this->cases->recordAppealFiling($case, $request->user(), $data['filed_on'], $data['court_case_number'] ?? null);

        return back()->with('success', __('discipline.flash.appeal_filed'));
    }

    public function appealOutcome(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->recordAppealOutcome($case, $request->user(), $request->validate([
            'outcome' => ['required', Rule::in(DisciplinaryAppealOutcome::values())],
            'decided_on' => ['required', 'date_format:Y-m-d'],
            'received_at' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:5000'],
        ]));

        return back()->with('success', __('discipline.flash.appeal_outcome'));
    }

    /** Art. 54(1): a ruling for the employee carried out. */
    public function appealImplemented(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate([
            'implemented_at' => ['required', 'date'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $this->cases->recordAppealImplemented($case, $request->user(), $data['implemented_at'], $data['note']);

        return back()->with('success', __('discipline.flash.appeal_implemented'));
    }

    public function discontinue(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->cases->discontinue($case, $request->user(), $data['reason']);

        return back()->with('success', __('discipline.flash.discontinued'));
    }

    public function terminate(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->cases->terminateForEndOfService($case, $request->user(), $data['reason']);

        return back()->with('success', __('discipline.flash.terminated'));
    }

    public function close(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->cases->close($case, $request->user());

        return back()->with('success', __('discipline.flash.closed'));
    }

    // ── Shared with the portal controller ────────────────────────────────────

    /** @return array<string, mixed> */
    public static function validatedResponse(Request $request): array
    {
        return $request->validate([
            'pleas' => ['required', 'array', 'min:1', 'max:50'],
            'pleas.*.plea' => ['required', Rule::in(['admitted', 'denied'])],
            'pleas.*.statement' => ['nullable', 'string', 'max:5000'],
            'objection_ground' => ['nullable', Rule::enum(DisciplinaryObjectionGround::class)],
            'objection_text' => ['nullable', 'required_with:objection_ground', 'string', 'max:5000'],
            'defense_evidence_list' => ['nullable', 'string', 'max:5000'],
            'acknowledged_name' => ['required', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, mixed> */
    public static function validatedEvidence(Request $request): array
    {
        return $request->validate([
            'side' => ['required', Rule::enum(DisciplinaryEvidenceSide::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'held_by' => ['nullable', 'string', 'max:255'],
            'charge_id' => ['nullable', 'uuid'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ]);
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'penalties' => array_map(fn (DisciplinaryPenalty $p): array => [
                'value' => $p->value, 'severity' => $p->severity()->value,
                'unit' => $p->amountUnit(), 'max' => $p->maxAmount(),
            ], DisciplinaryPenalty::cases()),
            'objectionGrounds' => array_column(DisciplinaryObjectionGround::cases(), 'value'),
            'headActions' => array_column(DisciplinaryHeadAction::cases(), 'value'),
            'sides' => array_column(DisciplinaryEvidenceSide::cases(), 'value'),
        ];
    }
}
