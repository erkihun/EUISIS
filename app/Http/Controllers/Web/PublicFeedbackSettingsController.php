<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\ServiceFeedbackType;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePublicFeedbackSettingsRequest;
use App\Models\User;
use App\Services\ServiceFeedback\PublicFeedbackSettings;
use App\Services\ServiceFeedback\PublicSubmissionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** The city-wide public feedback policy page (brief §93). */
class PublicFeedbackSettingsController extends Controller
{
    public function __construct(private readonly PublicFeedbackSettings $settings) {}

    public function index(): Response
    {
        abort_unless($this->settings->canManage($this->user()), 403);

        return Inertia::render('ServiceFeedback/Settings', [
            'feedbackSettings' => $this->settings->toArray(),
            // The fixed identity matrix, shown read-only beside the switches.
            'identityPolicy' => array_map(
                fn (ServiceFeedbackType $type): array => app(PublicSubmissionPolicy::class)->for($type)->toPublicArray(),
                ServiceFeedbackType::cases(),
            ),
        ]);
    }

    public function update(UpdatePublicFeedbackSettingsRequest $request): RedirectResponse
    {
        $this->settings->update($this->user(), $request->validated(), $request);

        return redirect()->route('service-feedback.settings.index')->with('success', __('service-feedback.settings_saved'));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
