<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApplicantController extends Controller
{
    /**
     * List applicants with optional search.
     */
    public function index(Request $request)
    {
        $q = trim($request->string('q')->toString());

        $status = $request->string('status')->toString();

        $applicants = Applicant::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($builder) use ($q) {
                    $builder->where('first_name', 'like', "%{$q}%")
                        ->orWhere('middle_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('organization_name', 'like', "%{$q}%")
                        ->orWhere('position', 'like', "%{$q}%");
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('created_at')
            ->paginate(12)
            ->withQueryString();

        // The register (React, Court/Applicants/Index).
        return Inertia::render('Court/Applicants/Index', [
            'applicants' => $applicants->through(fn (Applicant $applicant): array => [
                'id' => (int) $applicant->id,
                'full_name' => $applicant->full_name,
                'national_id_number' => $applicant->national_id_number,
                'email' => $applicant->email,
                'phone' => $applicant->phone,
                'position' => $applicant->position,
                'organization_name' => $applicant->organization_name,
                'is_active' => (bool) $applicant->is_active,
                'verified' => $applicant->hasVerifiedEmail(),
                'created_at' => $applicant->created_at?->toIso8601String(),
            ]),
            'filters' => ['q' => $q, 'status' => in_array($status, ['active', 'inactive'], true) ? $status : ''],
            'canManage' => (bool) ($request->user()?->hasPermission('applicants.manage') ?? false),
        ]);
    }

    public function updateStatus(Request $request, Applicant $applicant)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $applicant->update(['is_active' => $data['is_active']]);

        $message = [
            'key' => $applicant->is_active ? 'court::messages.success.activated' : 'court::messages.success.deactivated',
            'replace' => ['resource' => __('court::messages.resources.applicant')],
        ];

        return back()->with('success', $message);
    }
}
