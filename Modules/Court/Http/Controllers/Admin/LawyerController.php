<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Application\Notifications\LawyerApplicationApproved;
use Modules\Court\Application\Notifications\LawyerApplicationRefused;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Services\SecureUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The court's register of lawyers (EUISIS ADR 0013 #13). The registry opens a
 * lawyer's account and verifies their licence; only a verified, active lawyer
 * signs in (/lawyer/login), is offered to employees filing in the EUISIS
 * employee portal, and may file for a client. Opening an account emails the
 * lawyer a link to choose their password. A lawyer may also apply at
 * /lawyer/register: the application waits here until the registry verifies
 * the licence (the approval email carries the same link) or refuses it.
 */
class LawyerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $lawyers = Applicant::query()->where('is_lawyer', true)
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('lawyer_license_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            // Applications waiting for the registry first, then the other unverified, then the register.
            ->orderByRaw('(lawyer_verified_at IS NULL AND lawyer_refused_at IS NULL AND lawyer_applied_at IS NOT NULL) DESC')
            ->orderByRaw('lawyer_verified_at IS NULL DESC')->orderByDesc('lawyer_applied_at')->orderBy('first_name')
            ->paginate(25)->withQueryString();
        $caseCounts = DB::table('court_case_representatives')->whereIn('lawyer_applicant_id', collect($lawyers->items())->pluck('id')->all())
            ->where('status', 'accepted')->selectRaw('lawyer_applicant_id, COUNT(DISTINCT case_id) as total')->groupBy('lawyer_applicant_id')->pluck('total', 'lawyer_applicant_id');

        return Inertia::render('Court/Lawyers/Index', [
            'lawyers' => $lawyers->through(fn (Applicant $lawyer): array => [
                'id' => (int) $lawyer->id,
                'name' => CaseParties::fullName($lawyer),
                'email' => (string) $lawyer->email,
                'phone' => (string) $lawyer->phone,
                'license_number' => $lawyer->lawyer_license_number,
                'verified' => $lawyer->lawyer_verified_at !== null,
                'verified_at' => $lawyer->lawyer_verified_at?->toIso8601String(),
                'applied_at' => $lawyer->lawyer_applied_at?->toIso8601String(),
                'refused' => $lawyer->lawyer_refused_at !== null,
                'refusal_reason' => $lawyer->lawyer_refused_at !== null ? $lawyer->lawyer_refusal_reason : null,
                'active' => (bool) $lawyer->is_active,
                'has_document' => ! empty($lawyer->lawyer_document_path),
                'cases' => (int) ($caseCounts[$lawyer->id] ?? 0),
            ]),
            'search' => $search,
            'canManage' => (bool) $request->user()?->hasPermission('applicants.manage'),
        ]);
    }

    /**
     * One lawyer in full, for the registry to check before verifying or refusing:
     * who they are, their licence (the PDF beside it), the registry's decisions
     * and the cases they represent.
     */
    public function show(Request $request, Applicant $applicant): Response
    {
        abort_unless((bool) $applicant->is_lawyer, 404);
        $licenseStored = ! empty($applicant->lawyer_document_path) && Storage::disk('court_private')->exists($applicant->lawyer_document_path);
        $verifiedBy = $applicant->lawyer_verified_by_user_id ? DB::table('court_staff')->where('id', $applicant->lawyer_verified_by_user_id)->value('name') : null;
        $cases = DB::table('court_case_representatives as r')
            ->join('court_cases as c', 'c.id', '=', 'r.case_id')
            ->leftJoin('court_case_applicants as p', 'p.id', '=', 'r.case_applicant_id')
            ->where('r.lawyer_applicant_id', $applicant->id)
            ->select('c.id', 'c.case_number', 'c.status as case_status', 'r.status', 'p.name as client', 'r.created_at')
            ->orderByDesc('r.created_at')->limit(50)->get();

        return Inertia::render('Court/Lawyers/Show', [
            'lawyer' => [
                'id' => (int) $applicant->id,
                'name' => CaseParties::fullName($applicant),
                'first_name' => (string) $applicant->first_name,
                'middle_name' => (string) $applicant->middle_name,
                'last_name' => (string) $applicant->last_name,
                'gender' => $applicant->gender,
                'national_id_number' => (string) $applicant->national_id_number,
                'email' => (string) $applicant->email,
                'phone' => (string) $applicant->phone,
                'address' => (string) $applicant->address,
                'license_number' => $applicant->lawyer_license_number,
                'license_url' => $licenseStored ? route('court.lawyers.license', $applicant->id) : null,
                // A licence on record whose file is gone (not the same as none sent).
                'license_missing' => ! empty($applicant->lawyer_document_path) && ! $licenseStored,
                'applied_at' => $applicant->lawyer_applied_at?->toIso8601String(),
                'created_at' => $applicant->created_at?->toIso8601String(),
                'verified' => $applicant->lawyer_verified_at !== null,
                'verified_at' => $applicant->lawyer_verified_at?->toIso8601String(),
                'verified_by' => $verifiedBy,
                'refused' => $applicant->lawyer_refused_at !== null,
                'refused_at' => $applicant->lawyer_refused_at?->toIso8601String(),
                'refusal_reason' => $applicant->lawyer_refusal_reason,
                'active' => (bool) $applicant->is_active,
                'email_confirmed' => $applicant->email_verified_at !== null,
            ],
            'cases' => $cases->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_number' => (string) $row->case_number,
                'case_status' => (string) $row->case_status,
                'status' => (string) $row->status,
                'client' => (string) ($row->client ?? ''),
            ])->all(),
            'canManage' => (bool) $request->user()?->hasPermission('applicants.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'in:male,female'],
            'email' => ['required', 'email', 'max:255', Rule::unique('court_applicants', 'email')],
            'phone' => ['required', 'string', 'max:30', Rule::unique('court_applicants', 'phone')],
            'national_id_number' => ['required', 'string', 'max:32', Rule::unique('court_applicants', 'national_id_number')],
            'address' => ['required', 'string', 'max:255'],
            'license_number' => ['required', 'string', 'max:80', Rule::unique('court_applicants', 'lawyer_license_number')],
            'license_document' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $lawyer = new Applicant([
            'first_name' => trim($data['first_name']), 'middle_name' => trim($data['middle_name']), 'last_name' => trim($data['last_name']),
            'gender' => $data['gender'], 'email' => mb_strtolower(trim($data['email'])), 'phone' => trim($data['phone']),
            'national_id_number' => trim($data['national_id_number']), 'address' => trim($data['address']),
            // A password nobody knows: the lawyer chooses theirs from the emailed link.
            'password' => Hash::make(Str::random(48)), 'is_active' => true, 'organization_name' => null, 'position' => null,
        ]);
        $lawyer->forceFill([
            'is_lawyer' => true, 'lawyer_license_number' => trim($data['license_number']),
            'lawyer_verified_at' => now(), 'lawyer_verified_by_user_id' => $request->user()->id, 'email_verified_at' => now(),
        ])->save();
        if ($request->hasFile('license_document')) {
            $lawyer->forceFill(['lawyer_document_path' => app(SecureUploadService::class)->store($request->file('license_document'), 'lawyer-documents', 'court_private', [
                'related_type' => 'applicant', 'related_id' => $lawyer->id,
            ])])->save();
        }
        Password::broker('applicants')->sendResetLink(['email' => $lawyer->email]);

        return back()->with('success', __('court::lawyer.register.created', ['name' => CaseParties::fullName($lawyer)]));
    }

    /** Verifies a lawyer's licence (an account a lawyer opened in the old portal), or withdraws it. */
    public function verify(Request $request, Applicant $applicant): RedirectResponse
    {
        abort_unless((bool) $applicant->is_lawyer, 404);
        $data = $request->validate([
            'verified' => ['required', 'boolean'],
            'license_number' => ['nullable', 'required_if:verified,true', 'string', 'max:80', Rule::unique('court_applicants', 'lawyer_license_number')->ignore($applicant->id)],
        ]);
        $verified = (bool) $data['verified'];
        // An application approved for the first time: its account opens and the lawyer is told by email.
        $approvesApplication = $verified && $applicant->lawyer_applied_at !== null && $applicant->email_verified_at === null;
        $applicant->forceFill([
            'lawyer_license_number' => $verified ? trim((string) $data['license_number']) : $applicant->lawyer_license_number,
            'lawyer_verified_at' => $verified ? now() : null,
            'lawyer_verified_by_user_id' => $verified ? $request->user()->id : null,
            ...($verified ? ['lawyer_refused_at' => null, 'lawyer_refusal_reason' => null, 'is_active' => true] : []),
            ...($approvesApplication ? ['email_verified_at' => now()] : []),
        ])->save();
        if ($approvesApplication) {
            $applicant->notify(new LawyerApplicationApproved(Password::broker('applicants')->createToken($applicant)));
        }

        return back()->with('success', __($verified ? 'court::lawyer.register.verified_flash' : 'court::lawyer.register.unverified_flash'));
    }

    /** Refuses a lawyer's application with a reason the lawyer is emailed; the account stays closed. */
    public function refuse(Request $request, Applicant $applicant): RedirectResponse
    {
        abort_unless((bool) $applicant->is_lawyer && $applicant->lawyer_applied_at !== null && $applicant->lawyer_verified_at === null, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $applicant->forceFill(['lawyer_refused_at' => now(), 'lawyer_refusal_reason' => trim($data['reason']), 'is_active' => false])->save();
        $applicant->notify(new LawyerApplicationRefused(trim($data['reason'])));

        return back()->with('success', __('court::lawyer.register.refused_flash'));
    }

    /** The licence a lawyer sent, for the registry to check. */
    public function license(Applicant $applicant): StreamedResponse
    {
        abort_unless((bool) $applicant->is_lawyer && ! empty($applicant->lawyer_document_path), 404);
        $disk = Storage::disk('court_private');
        abort_unless($disk->exists($applicant->lawyer_document_path), 404);

        return $disk->response($applicant->lawyer_document_path, 'licence-'.$applicant->id.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
