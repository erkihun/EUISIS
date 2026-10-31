<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CaseApplicant;
use Modules\Court\Domain\Models\CaseRepresentative;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Services\SecureUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The parties on the applicant's side of a case, on its staff page
 * (EUISIS ADR 0013 #13): the judge admits or refuses an intervener, the
 * registry accepts or refuses a lawyer's power of attorney and records a
 * lawyer for a party from a power of attorney brought to it, and a judge or
 * registrar (`cases.merge`) merges open cases against the same respondent.
 */
class CasePartiesController extends Controller
{
    public function __construct(private readonly CaseParties $parties) {}

    /**
     * The "Parties and representation" section's props for the case page.
     *
     * @return array<string, mixed>
     */
    public static function props(int $caseId, ?User $user): array
    {
        $service = app(CaseParties::class);
        $service->ensureLead($caseId);
        $iso = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;
        $columns = ['id', 'case_number', 'status', 'respondent_name', 'merged_into_case_id', 'merged_at', 'merge_note', 'created_at'];
        if (Schema::hasColumn('court_cases', 'respondent_organization_uuid')) {
            $columns[] = 'respondent_organization_uuid';
        }
        $case = DB::table('court_cases')->where('id', $caseId)->first($columns);

        $rows = CaseApplicant::query()->where('case_id', $caseId)
            ->orderByRaw("CASE role WHEN 'lead' THEN 1 WHEN 'co_applicant' THEN 2 WHEN 'intervener' THEN 3 ELSE 4 END")->orderBy('id')
            ->with(['representatives.lawyer:id,first_name,middle_name,last_name,lawyer_license_number,phone,email'])
            ->get();
        $deciders = User::query()->whereIn('id', $rows->pluck('decided_by_user_id')->filter()->all())->pluck('name', 'id');
        $origins = DB::table('court_cases')->whereIn('id', $rows->pluck('merged_from_case_id')->filter()->all())->pluck('case_number', 'id');

        $open = $case !== null && $case->merged_into_case_id === null && ! in_array($case->status, ['closed', 'dismissed'], true);
        $candidates = [];
        if ($open && $user?->hasPermission('cases.merge')) {
            $candidates = DB::table('court_cases')->where('id', '!=', $caseId)->whereNull('merged_into_case_id')
                ->whereNotIn('status', ['closed', 'dismissed'])
                ->when(! empty($case->respondent_organization_uuid),
                    fn ($q) => $q->where('respondent_organization_uuid', $case->respondent_organization_uuid),
                    fn ($q) => $q->whereRaw('LOWER(TRIM(respondent_name)) = ?', [mb_strtolower(trim((string) $case->respondent_name))]))
                ->orderByDesc('filing_date')->limit(50)
                ->get(['id', 'case_number', 'title', 'filing_date', 'status'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'case_number' => (string) $row->case_number, 'title' => (string) $row->title, 'filed_at' => $iso($row->filing_date), 'status' => (string) $row->status])
                ->all();
        }

        return [
            'rows' => $rows->map(fn (CaseApplicant $party): array => [
                'id' => (int) $party->id,
                'name' => (string) $party->name,
                'role' => (string) $party->role,
                'status' => (string) $party->status,
                'source' => (string) $party->source,
                'linked' => $party->applicant_id !== null,
                'statement' => $party->statement,
                'attachment_url' => $party->attachment_path ? route('court.cases.parties.attachment', [$caseId, $party->id]) : null,
                'decision_note' => $party->decision_note,
                'decided_at' => $iso($party->decided_at),
                'decided_by' => $party->decided_by_user_id ? ($deciders[$party->decided_by_user_id] ?? null) : null,
                'merged_from' => $party->merged_from_case_id ? ($origins[$party->merged_from_case_id] ?? null) : null,
                // A lead added by the backfill has been a party since the case was filed, not since the backfill ran.
                'created_at' => $iso($party->role === CaseApplicant::ROLE_LEAD && $party->source === 'backfill' ? ($case->created_at ?? $party->created_at) : $party->created_at),
                'representatives' => $party->representatives->map(fn (CaseRepresentative $representative): array => [
                    'id' => (int) $representative->id,
                    'name' => $representative->lawyer ? CaseParties::fullName($representative->lawyer) : '—',
                    'license_number' => $representative->lawyer?->lawyer_license_number,
                    'phone' => $representative->lawyer?->phone,
                    'email' => $representative->lawyer?->email,
                    'status' => (string) $representative->status,
                    'confirmed_by_party' => $representative->confirmed_by_party_at !== null,
                    'power_of_attorney_url' => $representative->power_of_attorney_path ? route('court.cases.representatives.power-of-attorney', [$caseId, $representative->id]) : null,
                    'note' => $representative->note,
                    'verified_at' => $iso($representative->verified_at),
                ])->values()->all(),
            ])->values()->all(),
            'can' => [
                'decide' => $user !== null && $service->canDecideIntervention($user, $caseId),
                'verify' => (bool) $user?->hasPermission('cases.edit'),
                'merge' => $open && (bool) $user?->hasPermission('cases.merge'),
            ],
            // The court's register of verified lawyers, for "Record a lawyer" (registry staff only).
            'lawyers' => $user?->hasPermission('cases.edit')
                ? Applicant::query()->where('is_lawyer', true)->where('is_active', true)->whereNotNull('lawyer_verified_at')
                    ->orderBy('first_name')->orderBy('last_name')->limit(500)
                    ->get(['id', 'first_name', 'middle_name', 'last_name', 'lawyer_license_number'])
                    ->map(fn (Applicant $lawyer): array => ['id' => (int) $lawyer->id, 'name' => CaseParties::fullName($lawyer), 'license_number' => (string) $lawyer->lawyer_license_number])
                    ->all()
                : [],
            'mergedInto' => $case?->merged_into_case_id ? [
                'id' => (int) $case->merged_into_case_id,
                'case_number' => (string) DB::table('court_cases')->where('id', $case->merged_into_case_id)->value('case_number'),
                'note' => $case->merge_note,
                'at' => $iso($case->merged_at),
            ] : null,
            'mergedFrom' => DB::table('court_cases')->where('merged_into_case_id', $caseId)->orderBy('merged_at')->get(['id', 'case_number', 'merged_at'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'case_number' => (string) $row->case_number, 'at' => $iso($row->merged_at)])->all(),
            'candidates' => $candidates,
        ];
    }

    /** The judge admits or refuses an intervener (a refusal gives the reason). */
    public function decide(Request $request, int $id, CaseApplicant $party): RedirectResponse
    {
        abort_unless((int) $party->case_id === $id, 404);
        $data = $request->validate(['decision' => ['required', 'in:admit,refuse'], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->parties->decideIntervention($party, $request->user(), $data['decision'] === 'admit', $data['note'] ?? null);

        return back()->with('success', __($data['decision'] === 'admit' ? 'court::case_parties.flash.admitted' : 'court::case_parties.flash.refused'));
    }

    /** The registry accepts or refuses a lawyer's power of attorney (a refusal gives the reason). */
    public function verify(Request $request, int $id, CaseRepresentative $representative): RedirectResponse
    {
        abort_unless((int) $representative->case_id === $id, 404);
        $data = $request->validate(['decision' => ['required', 'in:accept,refuse,revoke'], 'note' => ['nullable', 'string', 'max:2000']]);
        if ($data['decision'] === 'revoke') {
            $representative->forceFill(['status' => 'revoked', 'note' => $data['note'] ?? null, 'verified_by_user_id' => $request->user()->id, 'verified_at' => now()])->save();
        } else {
            $this->parties->verifyRepresentative($representative, $request->user(), $data['decision'] === 'accept', $data['note'] ?? null);
        }

        return back()->with('success', __("court::case_parties.flash.representation_{$data['decision']}"));
    }

    /** The registry records a lawyer for a party from the power of attorney the party signed. */
    public function addRepresentative(Request $request, int $id, CaseApplicant $party): RedirectResponse
    {
        abort_unless((int) $party->case_id === $id, 404);
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer'],
            'power_of_attorney' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['power_of_attorney.required' => __('court::case_parties.errors.power_of_attorney_required')]);
        $lawyer = Applicant::query()->find($data['lawyer_id']);
        if ($lawyer === null || ! CaseParties::isVerifiedLawyer($lawyer)) {
            return back()->withErrors(['lawyer_id' => __('court::case_parties.errors.lawyer_not_verified')]);
        }
        $path = app(SecureUploadService::class)->store($request->file('power_of_attorney'), 'powers-of-attorney', 'court_private', ['related_type' => 'court_case', 'related_id' => $id]);
        $this->parties->addRepresentativeByRegistry($party, $lawyer, $path, $request->user(), $data['note'] ?? null);

        return back()->with('success', __('court::case_parties.flash.representation_added'));
    }

    /** Merges the chosen cases into this one. */
    public function merge(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'cases' => ['required', 'array', 'min:1', 'max:20'],
            'court.cases.*' => ['integer', 'distinct'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $merged = $this->parties->merge($id, array_map('intval', $data['cases']), $request->user(), trim((string) $data['note']));

        return back()->with('success', __('court::case_parties.flash.merged', ['numbers' => implode(', ', $merged)]));
    }

    public function powerOfAttorney(int $id, CaseRepresentative $representative): StreamedResponse
    {
        abort_unless((int) $representative->case_id === $id && $representative->power_of_attorney_path, 404);

        return $this->download((string) $representative->power_of_attorney_path, 'power-of-attorney-'.$representative->id.'.pdf');
    }

    public function attachment(int $id, CaseApplicant $party): StreamedResponse
    {
        abort_unless((int) $party->case_id === $id && $party->attachment_path, 404);

        return $this->download((string) $party->attachment_path, 'intervention-'.$party->id.'.pdf');
    }

    private function download(string $path, string $name): StreamedResponse
    {
        $disk = Storage::disk('court_private');
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $name, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
