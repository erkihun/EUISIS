<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CourtCaseOfficer;
use App\Models\InstitutionCourtCase;
use App\Models\Organization;
use App\Models\User;
use App\Services\CourtMs\CourtMsClient;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\InstitutionCourtCaseInbox;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tribunal cases > Cases against the institution (court-ms Flow 7, ADR 0013 #14).
 * Every case filed at the administrative tribunal against an institution in the
 * reader's scope, given to the officer the institution named to follow its
 * court cases. A case opens with its course read live from court-ms; once it is
 * closed, the officer may ask for the record to take to the appellate court, and
 * download it when the court's registry releases it.
 */
class InstitutionCourtCaseController extends Controller
{
    public function __construct(private readonly InstitutionCourtCaseInbox $inbox) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(InstitutionCourtCaseInbox::canRead($user), 403);
        $filter = in_array($request->query('show'), ['mine', 'unassigned'], true) ? (string) $request->query('show') : '';

        $cases = $this->inbox->visibleTo($user)
            ->with(['organization:id,name_en,name_am', 'officer:id,name'])
            ->when($filter === 'mine', fn ($q) => $q->where('officer_user_id', $user->getKey()))
            ->when($filter === 'unassigned', fn ($q) => $q->whereNull('officer_user_id'))
            ->latest('received_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('AdministrativeTribunal/InstitutionCases', [
            'cases' => $cases->through(fn (InstitutionCourtCase $case): array => $this->row($case)),
            'filters' => ['show' => $filter],
            'canManage' => $user->can(InstitutionCourtCaseInbox::MANAGE),
        ]);
    }

    public function show(Request $request, InstitutionCourtCase $institutionCourtCase, CourtMsClient $client): Response
    {
        $user = $request->user();
        $case = $this->visible($request, $institutionCourtCase);
        $live = null;
        try {
            $live = $client->institutionCase((int) $case->court_case_id, (string) $case->organization_id);
        } catch (CourtMsException $e) {
            report($e);
        }
        $canManage = $this->inbox->canManage($user, (string) $case->organization_id);

        return Inertia::render('AdministrativeTribunal/InstitutionCaseShow', [
            'case' => $this->row($case),
            'court' => $live,
            'officers' => $canManage ? $this->inbox->candidates((string) $case->organization_id)->map(fn (User $officer): array => ['id' => (int) $officer->getKey(), 'name' => (string) $officer->name])->all() : [],
            'can' => ['manage' => $canManage],
            'error' => $request->session()->get('court_error'),
        ]);
    }

    /** Gives one case to another officer. */
    public function assign(Request $request, InstitutionCourtCase $institutionCourtCase): RedirectResponse
    {
        $case = $this->managed($request, $institutionCourtCase);
        $data = $request->validate(['officer_user_id' => ['required', 'integer']]);
        $officer = $this->inbox->candidates((string) $case->organization_id)->firstWhere('id', (int) $data['officer_user_id']);
        if ($officer === null) {
            throw ValidationException::withMessages(['officer_user_id' => __('validation.exists', ['attribute' => 'officer'])]);
        }
        $this->inbox->assign($case, $officer);

        return back()->with('success', __('court_cases.institution.assigned', ['name' => $officer->name]));
    }

    /** The institution asks for the record of a closed case to take to the appellate court. */
    public function appeal(Request $request, InstitutionCourtCase $institutionCourtCase, CourtMsClient $client): RedirectResponse
    {
        $case = $this->managed($request, $institutionCourtCase);
        $data = $request->validate(['grounds' => ['required', 'string', 'max:10000']]);
        $organization = $case->organization;

        try {
            $client->institutionAppeal((int) $case->court_case_id, (string) $case->organization_id, (string) ($organization?->name_am ?: $organization?->name_en ?: ''), $data['grounds']);
        } catch (CourtMsException $e) {
            if ($e->status === 422) {
                throw ValidationException::withMessages(['grounds' => (string) collect($e->errors)->flatten()->first() ?: $e->getMessage()]);
            }

            return back()->with('court_error', 'unavailable');
        }

        return back()->with('success', __('court_cases.institution.appeal_requested'));
    }

    /** The appeal record, once the court's registry released it to the institution. */
    public function appealRecord(Request $request, InstitutionCourtCase $institutionCourtCase, CourtMsClient $client): HttpResponse|RedirectResponse
    {
        $case = $this->visible($request, $institutionCourtCase);
        try {
            $pdf = $client->institutionAppealRecord((int) $case->court_case_id, (string) $case->organization_id);
        } catch (CourtMsException $e) {
            return back()->with('court_error', $e->status === 403 ? 'not_released' : 'unavailable');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="appeal-record-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $case->case_number).'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The officer each institution in scope names to follow its court cases. */
    public function officers(Request $request, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless(InstitutionCourtCaseInbox::canRead($user), 403);
        $organizations = $scope->applyOrganizationScope(Organization::query(), $user, 'id')
            ->where('status', 'active')->orderBy('name_en')->limit(300)->get(['id', 'name_en', 'name_am']);
        $assigned = CourtCaseOfficer::query()->whereIn('organization_id', $organizations->pluck('id'))->with('user:id,name')->get()->keyBy('organization_id');

        return Inertia::render('AdministrativeTribunal/CourtCaseOfficers', [
            'organizations' => $organizations->map(function (Organization $organization) use ($assigned, $user): array {
                $canManage = $this->inbox->canManage($user, (string) $organization->getKey());

                return [
                    'id' => (string) $organization->getKey(),
                    'name_en' => $organization->name_en,
                    'name_am' => $organization->name_am,
                    'officer' => ($officer = $assigned->get($organization->getKey())?->user) ? ['id' => (int) $officer->getKey(), 'name' => (string) $officer->name] : null,
                    'candidates' => $canManage ? $this->inbox->candidates((string) $organization->getKey())->map(fn (User $candidate): array => ['id' => (int) $candidate->getKey(), 'name' => (string) $candidate->name])->all() : [],
                    'can_manage' => $canManage,
                ];
            })->values()->all(),
        ]);
    }

    /** Names (or clears) the officer who follows an institution's court cases; the new cases go to them. */
    public function saveOfficer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'user_id' => ['nullable', 'integer'],
        ]);
        abort_unless($this->inbox->canManage($request->user(), $data['organization_id']), 403);

        if (empty($data['user_id'])) {
            CourtCaseOfficer::query()->where('organization_id', $data['organization_id'])->delete();

            return back()->with('success', __('court_cases.institution.officer_cleared'));
        }
        $officer = $this->inbox->candidates($data['organization_id'])->firstWhere('id', (int) $data['user_id']);
        if ($officer === null) {
            throw ValidationException::withMessages(['user_id' => __('validation.exists', ['attribute' => 'officer'])]);
        }
        CourtCaseOfficer::query()->updateOrCreate(['organization_id' => $data['organization_id']], ['user_id' => $officer->getKey(), 'assigned_by_user_id' => $request->user()->getKey()]);
        // Cases already waiting without an officer go to them as well.
        InstitutionCourtCase::query()->where('organization_id', $data['organization_id'])->whereNull('officer_user_id')->get()
            ->each(fn (InstitutionCourtCase $case) => $this->inbox->assign($case, $officer));

        return back()->with('success', __('court_cases.institution.officer_saved', ['name' => $officer->name]));
    }

    /** @return array<string, mixed> */
    private function row(InstitutionCourtCase $case): array
    {
        return [
            'id' => (string) $case->getKey(),
            'court_case_id' => (int) $case->court_case_id,
            'case_number' => (string) $case->case_number,
            'applicants' => array_values((array) $case->applicants),
            'case_type' => $case->case_type,
            'filed_at' => $case->filed_at?->toDateString(),
            'status' => $case->status,
            'review_status' => $case->review_status,
            'received_at' => $case->received_at?->toIso8601String(),
            'organization' => $case->organization ? ['name_en' => $case->organization->name_en, 'name_am' => $case->organization->name_am] : null,
            'officer' => $case->officer ? ['id' => (int) $case->officer->getKey(), 'name' => (string) $case->officer->name] : null,
        ];
    }

    private function visible(Request $request, InstitutionCourtCase $case): InstitutionCourtCase
    {
        abort_unless(InstitutionCourtCaseInbox::canRead($request->user()), 403);
        abort_unless($this->inbox->visibleTo($request->user())->whereKey($case->getKey())->exists(), 404);

        return $case->loadMissing(['organization:id,name_en,name_am', 'officer:id,name']);
    }

    private function managed(Request $request, InstitutionCourtCase $case): InstitutionCourtCase
    {
        $case = $this->visible($request, $case);
        abort_unless($this->inbox->canManage($request->user(), (string) $case->organization_id), 403);

        return $case;
    }
}
