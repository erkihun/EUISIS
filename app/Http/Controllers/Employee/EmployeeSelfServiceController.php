<?php

namespace App\Http\Controllers\Employee;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\UpdateSelfServiceProfileRequest;
use App\Http\Resources\EmployeeSelfServiceResource;
use App\Models\EmployeeCorrectionRequest;
use App\Models\EmployeeDocument;
use App\Models\IdCard;
use App\Services\Employees\EmployeeContactVerificationService;
use App\Services\Employees\EmployeeProfileUpdateService;
use App\Services\Employees\EmployeeSelfServicePolicy;
use App\Services\IdCards\IdCardFieldImpactService;
use App\Support\NotificationPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeSelfServiceController extends Controller
{
    public function page(Request $request, EmployeeProfileUpdateService $profiles, IdCardFieldImpactService $impact)
    {
        $section = $request->route()->defaults['section'] ?? 'profile';

        // Account security lives on My Profile, in its "Account & sign-in" block.
        if ($section === 'security') {
            return redirect()->to(route('employee.profile').'#account');
        }

        $employee = $profiles->employee($request->user());
        $employee->load('currentAssignment.organization', 'currentAssignment.organizationUnit', 'currentAssignment.position');
        $card = IdCard::query()->where('employee_id', $employee->id)->where('is_current', true)->first();
        $user = $request->user();

        return Inertia::render('Employee/SelfService', [
            'section' => $section,
            'profile' => (new EmployeeSelfServiceResource($employee))->resolve($request),
            'field_policy' => app(EmployeeSelfServicePolicy::class)->fields($employee, $card ? $impact->printedFields($card) : []),
            'card' => $card ? [
                'card_number' => $card->card_number, 'status' => $card->status->value,
                'issued_at' => $card->issued_at?->toDateString(), 'expires_at' => $card->expires_at?->toDateString(),
                'reprint_required' => $card->reprint_required,
                'changed_fields' => array_map(fn ($field) => __('employee-portal.fields.'.$field), $card->reprint_reasons ?? []),
                // Keys only, so the browser can name them in its own language.
                'changed_field_keys' => array_values($card->reprint_reasons ?? []),
                'snapshot_available' => $impact->latest($card) !== null,
            ] : null,
            'documents' => $section === 'documents' ? $employee->documents()->latest()->get()->map(fn ($doc) => ['id' => $doc->id, 'type' => $doc->document_type, 'created_at' => $doc->created_at?->toDateString()]) : [],
            'corrections' => $section === 'requests' ? EmployeeCorrectionRequest::query()->where('employee_id', $employee->id)->latest()->limit(100)->get()->map(fn ($correction) => ['id' => $correction->id, 'field' => __('employee-portal.fields.'.$correction->field), 'field_key' => $correction->field, 'status' => $correction->status, 'created_at' => $correction->created_at?->toDateString()]) : [],
            'notifications' => $section === 'notifications' ? $request->user()->notifications()->latest()->limit(100)->get()->map(fn ($notification) => NotificationPresenter::present($notification, NotificationPresenter::locale($request))) : [],
            // Both languages: the portal language is switched in the browser
            // without a round trip, so the page picks the set it needs.
            'labels' => ['en' => trans('employee-portal', [], 'en'), 'am' => trans('employee-portal', [], 'am')],
            'correction_fields' => EmployeeSelfServicePolicy::CORRECTIONS,
            // The sign-in account, shown on My Profile. No secrets: whether
            // two-factor is on, never its secret or recovery codes.
            'account' => $section === 'profile' ? [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values()->all(),
                'status' => $user->status,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'mfa_enabled' => $user->hasMfaEnabled(),
                'mfa_required' => $user->requiresMfa(),
            ] : null,
        ]);
    }

    public function update(UpdateSelfServiceProfileRequest $request, EmployeeProfileUpdateService $profiles)
    {
        $profiles->update($request->user(), $request->safe()->except('photo'), $request->file('photo'));

        return back()->with('success', __('employee-portal.saved'));
    }

    public function requestContact(Request $request, EmployeeContactVerificationService $verification)
    {
        $data = $request->validate(['field' => ['required', 'in:phone,email'], 'value' => ['required', 'string', 'max:255']]);
        $verification->request($request->user(), $data['field'], $data['value']);

        return back()->with('success', __('employee-portal.code_sent'));
    }

    public function confirmContact(Request $request, EmployeeContactVerificationService $verification)
    {
        // Posted as `otp`, which error logging always redacts (SBH-005).
        $data = $request->validate(['field' => ['required', 'in:phone,email'], 'otp' => ['required', 'digits:6']]);
        $verification->confirm($request->user(), $data['field'], $data['otp']);

        return back()->with('success', __('employee-portal.saved'));
    }

    public function correction(Request $request, EmployeeProfileUpdateService $profiles)
    {
        $employee = $profiles->employee($request->user());
        $data = $request->validate(['field' => ['required', Rule::in(EmployeeSelfServicePolicy::CORRECTIONS)], 'requested_value' => ['required', 'string', 'max:500']]);
        $correction = EmployeeCorrectionRequest::create([...$data, 'employee_id' => $employee->id, 'requested_by' => $request->user()->id]);
        app(WriteAuditLogAction::class)->execute(AuditEventType::EmployeeCorrectionRequested, $request->user(), $correction, $employee->currentAssignment?->organization_id, newValues: ['field' => $data['field']]);

        return back()->with('success', __('employee-portal.request_sent'));
    }

    public function photo(Request $request, EmployeeProfileUpdateService $profiles)
    {
        $employee = $profiles->employee($request->user());
        $path = $employee->photo_path;
        abort_unless($path && ! str_contains($path, '..'), 404);
        $disk = Storage::disk(str_starts_with($path, 'employee-photos/') ? 'local' : 'public');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function document(Request $request, string $document, EmployeeProfileUpdateService $profiles)
    {
        $employee = $profiles->employee($request->user());
        $document = EmployeeDocument::query()->where('employee_id', $employee->id)->findOrFail($document);
        abort_unless(! str_contains($document->file_path, '..'), 404);
        $disk = Storage::disk($document->storage_disk ?: 'local');
        abort_unless($disk->exists($document->file_path), 404);

        return $disk->download($document->file_path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function readNotification(Request $request, string $notification)
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return back();
    }
}
