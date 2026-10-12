<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\ServiceFeedbackType;
use App\Http\Controllers\Controller;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\OrganizationFeedbackQrCode;
use App\Models\User;
use App\Services\IdCards\IdCardQrCodeRenderer;
use App\Services\ServiceFeedback\OrganizationFeedbackQrAccess;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Management of an organization's public feedback QR (brief §69–72).
 *
 * Opening the page never mints a code; "Enable" does, once. Disable and
 * enable pause and resume the same token. Rotate and revoke need the stronger
 * permission and a written reason, and every action is audited.
 */
class OrganizationFeedbackQrController extends Controller
{
    public function __construct(
        private readonly OrganizationFeedbackQrService $codes,
        private readonly OrganizationFeedbackQrAccess $access,
        private readonly IdCardQrCodeRenderer $qrRenderer,
    ) {}

    public function show(Request $request, Organization $organization): Response
    {
        $this->access->authorize($this->user(), 'view', $organization);

        $qr = $this->currentCode($organization);

        return Inertia::render('ServiceFeedback/OrganizationQr', [
            'organization' => [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'status' => $organization->status->value,
                'logo_url' => $organization->logo_url,
            ],
            'qr' => $qr === null ? null : $this->qrPayload($qr),
            'history' => OrganizationFeedbackQrCode::query()
                ->where('organization_id', $organization->id)
                ->where('status', '!=', 'active')
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(fn (OrganizationFeedbackQrCode $code): array => [
                    'id' => $code->id,
                    'status' => $code->status->value,
                    'enabled_at' => $code->enabled_at?->toIso8601String(),
                    'revoked_at' => $code->revoked_at?->toIso8601String(),
                    'revocation_reason' => $code->revocation_reason,
                ])
                ->all(),
            'stats' => $this->stats($organization),
            'abilities' => $this->access->abilities($this->user(), $organization),
        ]);
    }

    public function generate(Request $request, Organization $organization): RedirectResponse
    {
        $this->access->authorize($this->user(), 'create', $organization);

        $this->codes->ensureActive($organization, $this->user(), $request);

        return $this->back($organization, 'qrGenerated');
    }

    public function disable(Request $request, Organization $organization): RedirectResponse
    {
        $this->access->authorize($this->user(), 'disable', $organization);

        $qr = $this->codes->activeFor($organization);
        abort_if($qr === null, 404);

        $this->codes->disable($qr, $this->user(), $request);

        return $this->back($organization, 'qrDisabled');
    }

    public function enable(Request $request, Organization $organization): RedirectResponse
    {
        $this->access->authorize($this->user(), 'disable', $organization);

        $qr = $this->currentCode($organization);
        abort_if($qr === null || $qr->status->value !== 'disabled', 404);

        $this->codes->enable($qr, $this->user(), $request);

        return $this->back($organization, 'qrEnabled');
    }

    public function rotate(Request $request, Organization $organization): RedirectResponse
    {
        $this->access->authorize($this->user(), 'rotate', $organization);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $qr = $this->currentCode($organization);
        abort_if($qr === null, 404);

        $this->codes->rotate($qr, $validated['reason'], $this->user(), $request);

        return $this->back($organization, 'qrRotated');
    }

    public function revoke(Request $request, Organization $organization): RedirectResponse
    {
        $this->access->authorize($this->user(), 'rotate', $organization);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $qr = $this->currentCode($organization);
        abort_if($qr === null, 404);

        $this->codes->revoke($qr, $validated['reason'], $this->user(), $request);

        return $this->back($organization, 'qrRevoked');
    }

    public function exportPng(Request $request, Organization $organization): HttpResponse
    {
        $this->access->authorize($this->user(), 'export', $organization);

        $qr = $this->codes->activeFor($organization);
        abort_if($qr === null, 404);

        $png = $this->qrRenderer->asPngBytes($qr->publicUrl(), 12);
        abort_if($png === '', 500, 'QR image could not be generated.');

        $this->codes->recordExport($qr, 'png', $this->user(), $request);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="feedback-qr-'.$organization->code.'.png"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function exportPdf(Request $request, Organization $organization): HttpResponse
    {
        $this->access->authorize($this->user(), 'export', $organization);

        $qr = $this->codes->activeFor($organization);
        abort_if($qr === null, 404);

        $logo = $organization->logo_path !== null && is_file(public_path('storage/'.$organization->logo_path))
            ? public_path('storage/'.$organization->logo_path)
            : null;

        $pdf = Pdf::loadView('pdf.organization-feedback-qr', [
            'qr' => $this->qrRenderer->asSvgDataUri($qr->publicUrl(), 260),
            'url' => $qr->publicUrl(),
            'organization_en' => $organization->name_en,
            'organization_am' => $organization->name_am,
            'logo' => $logo,
        ]);

        $this->codes->recordExport($qr, 'pdf', $this->user(), $request);

        return $pdf->download('feedback-qr-'.$organization->code.'.pdf');
    }

    /** The active code, or the most recent disabled one that can still be enabled. */
    private function currentCode(Organization $organization): ?OrganizationFeedbackQrCode
    {
        return $this->codes->activeFor($organization)
            ?? OrganizationFeedbackQrCode::query()
                ->where('organization_id', $organization->id)
                ->where('status', 'disabled')
                ->latest('created_at')
                ->first();
    }

    /** @return array<string, mixed> */
    private function qrPayload(OrganizationFeedbackQrCode $qr): array
    {
        return [
            'id' => $qr->id,
            'status' => $qr->status->value,
            'url' => $qr->publicUrl(),
            // Rendered for display only; the raw token is never echoed as text.
            'qr_svg' => $qr->acceptsFeedback() ? $this->qrRenderer->asSvgDataUri($qr->publicUrl(), 220) : null,
            'scan_count' => $qr->scan_count,
            'last_scanned_at' => $qr->last_scanned_at?->toIso8601String(),
            'enabled_at' => $qr->enabled_at?->toIso8601String(),
            'disabled_at' => $qr->disabled_at?->toIso8601String(),
        ];
    }

    /** @return array<string, int> */
    private function stats(Organization $organization): array
    {
        $counts = EmployeeServiceFeedback::query()
            ->where('organization_target_id', $organization->id)
            ->selectRaw('type, COUNT(*) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'feedback' => (int) ($counts[ServiceFeedbackType::Feedback->value] ?? 0),
            'suggestion' => (int) ($counts[ServiceFeedbackType::Suggestion->value] ?? 0),
            'complaint' => (int) ($counts[ServiceFeedbackType::Complaint->value] ?? 0),
        ];
    }

    private function back(Organization $organization, string $flashKey): RedirectResponse
    {
        return redirect()
            ->route('organizations.feedback-qr.show', $organization)
            ->with('success', __('service-feedback.'.$flashKey));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
