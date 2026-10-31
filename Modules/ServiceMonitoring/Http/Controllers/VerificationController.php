<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\RejectDigitalService;
use Modules\ServiceMonitoring\Application\Actions\VerifyDigitalService;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Enums\PeriodStatus;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Enums\VerificationStatus;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Http\Requests\ReasonRequest;

/** The Innovation & Technology Bureau's verification queue (D20). */
final class VerificationController extends Controller
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->canVerifyTechnology($request->user()), 403);
        $status = VerificationStatus::tryFrom($request->string('status')->toString()) ?? VerificationStatus::Pending;

        $services = DigitalService::query()
            ->where('verification_status', $status->value)
            ->whereHas('submission', fn ($q) => $q->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::Accepted->value])
                ->whereHas('period', fn ($p) => $p->where('status', '!=', PeriodStatus::Locked->value)))
            ->with(['submission.organization:id,name_en,name_am', 'submission.period.fiscalYear', 'parent:id,service_name_am,service_name_en'])
            ->orderBy('created_at')->paginate(50)->withQueryString();

        return Inertia::render('ServiceMonitoring/Verification/Index', [
            'services' => $services->through(fn (DigitalService $d): array => [
                'id' => $d->id, 'level' => $d->level->value, 'service_name_am' => $d->service_name_am, 'service_name_en' => $d->service_name_en,
                'parent' => $d->parent ? ['service_name_am' => $d->parent->service_name_am, 'service_name_en' => $d->parent->service_name_en] : null,
                'is_catalog_gap' => $d->is_catalog_gap, 'technology_type' => $d->technology_type->value, 'technology_other' => $d->technology_other,
                'system_name' => $d->system_name, 'system_count' => $d->system_count, 'go_live_on' => $d->go_live_on->toDateString(),
                'remark' => $d->remark, 'verification_status' => $d->verification_status->value, 'rejection_reason' => $d->rejection_reason,
                'submission_id' => $d->submission_id, 'period_label' => $d->submission->period->label(),
                'organization' => ['name_en' => $d->submission->organization?->name_en, 'name_am' => $d->submission->organization?->name_am],
            ]),
            'filters' => ['status' => $status->value],
        ]);
    }

    public function verify(Request $request, DigitalService $digitalService, VerifyDigitalService $verify): RedirectResponse
    {
        $this->authorize('verify', $digitalService);
        $verify->execute($digitalService, $request->user());

        return back()->with('success', __('service-monitoring.flash.verified'));
    }

    public function reject(ReasonRequest $request, DigitalService $digitalService, RejectDigitalService $reject): RedirectResponse
    {
        $this->authorize('verify', $digitalService);
        $reject->execute($digitalService, $request->user(), (string) $request->validated('reason'));

        return back()->with('success', __('service-monitoring.flash.rejected'));
    }
}
