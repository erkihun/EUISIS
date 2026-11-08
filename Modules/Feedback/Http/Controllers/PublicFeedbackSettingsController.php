<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Feedback\Application\Services\PublicFeedbackSettings;
use Modules\Feedback\Application\Services\PublicSubmissionPolicy;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Http\Requests\UpdatePublicFeedbackSettingsRequest;

/**
 * The city-wide policy, one page per module (owner rule 2026-11-08: service
 * feedback, Tip-offs and complaints are separate modules with nothing shared):
 * /feedback-admin/settings, /tip-off-admin/settings, /complaint-admin/settings.
 * Each page shows and saves only its own module's settings. The public
 * intake's own switches and limits (the QR forms belong to service feedback)
 * sit on the feedback page.
 */
class PublicFeedbackSettingsController extends Controller
{
    /** The settings each module's page owns. */
    public const MODULE_KEYS = [
        'feedback' => ['enabled', 'feedback_enabled', 'allow_attachments', 'acknowledgement_enabled', 'rate_limit_per_source', 'rate_limit_per_ip', 'sla_days_feedback', 'spike_threshold_percent', 'spike_minimum_count', 'repeat_threshold_count'],
        'suggestion' => ['suggestion_enabled', 'sla_days_suggestion'],
        'complaint' => ['complaint_enabled', 'sla_days_complaint', 'complaint_appeal_days', 'complaint_appeal_response_days'],
    ];

    public const ROUTES = ['feedback' => 'service-feedback.settings', 'suggestion' => 'tip-offs.settings', 'complaint' => 'public-complaints.settings'];

    public function __construct(private readonly PublicFeedbackSettings $settings) {}

    public function index(Request $request): Response
    {
        abort_unless($this->settings->canManage($this->user()), 403);
        $module = $this->module($request);

        return Inertia::render('ServiceFeedback/Settings', [
            'module' => $module,
            'routeBase' => self::ROUTES[$module],
            'feedbackSettings' => array_intersect_key($this->settings->toArray(), array_flip(self::MODULE_KEYS[$module])),
            // The fixed identity rule of this module's form, shown read-only.
            'identityPolicy' => [app(PublicSubmissionPolicy::class)->for(ServiceFeedbackType::from($module))->toPublicArray()],
        ]);
    }

    public function update(UpdatePublicFeedbackSettingsRequest $request): RedirectResponse
    {
        $module = $this->module($request);
        $this->settings->update($this->user(), array_intersect_key($request->validated(), array_flip(self::MODULE_KEYS[$module])), $request);

        return redirect()->route(self::ROUTES[$module].'.index')->with('success', __('service-feedback.settings_saved'));
    }

    private function module(Request $request): string
    {
        $module = (string) ($request->route('submission_type') ?? 'feedback');

        return array_key_exists($module, self::MODULE_KEYS) ? $module : 'feedback';
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
