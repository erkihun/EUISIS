<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeServiceFeedback;
use App\Services\IdCards\IdCardQrCodeRenderer;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generation, printing and retirement of employee feedback QR codes.
 *
 * The printed QR encodes only the public feedback URL. Nothing about the
 * employee travels in the image, so a photographed code reveals no more than
 * the page it opens — which itself names no person.
 *
 * All actions require `service_feedback.settings.manage`, and the employee
 * must be inside the actor's organization scope.
 */
class EmployeeFeedbackQrController extends Controller
{
    public function __construct(
        private readonly EmployeeFeedbackTokenService $tokens,
        private readonly IdCardQrCodeRenderer $qrRenderer,
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    /**
     * The QR management screen for one employee.
     *
     * Opening this page provisions a token on the spot for an active employee
     * who has none, so an administrator never lands on an empty card and has to
     * press "Generate" before the QR exists. Auto-provisioning is skipped for
     * inactive employees and for anyone whose token was deliberately revoked or
     * suspended — see EmployeeFeedbackTokenService::ensureActiveTokenFor().
     */
    public function show(Request $request, Employee $employee): Response
    {
        $this->authorizeManage($employee);

        $token = $this->tokens->ensureActiveTokenFor($employee, Auth::user(), $request);

        return Inertia::render('ServiceFeedback/EmployeeQr', [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'status' => $employee->status->value,
            ],
            'token' => $token === null ? null : $this->tokenPayload($token),
            'stats' => $this->stats($employee),
            /*
             * Tells the page WHY there is no QR, so it can explain the state
             * instead of implying something is broken.
             */
            'unavailableReason' => $token !== null
                ? null
                : ($this->tokens->hasDeliberatelyDisabledToken($employee) ? 'disabled' : 'inactive_employee'),
        ]);
    }

    public function generate(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeManage($employee);

        $this->tokens->ensureActiveToken($employee, Auth::user(), $request);

        return back()->with('flash', ['message' => __('Feedback QR generated.'), 'type' => 'success']);
    }

    public function regenerate(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeManage($employee);

        $this->tokens->regenerate($employee, Auth::user(), $request);

        return back()->with('flash', [
            'message' => __('Feedback QR regenerated. Previously printed codes no longer work.'),
            'type' => 'success',
        ]);
    }

    public function revoke(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeManage($employee);

        $token = $this->tokens->activeToken($employee);

        if ($token !== null) {
            $this->tokens->revoke($token, Auth::user(), $request);
        }

        return back()->with('flash', ['message' => __('Feedback QR revoked.'), 'type' => 'success']);
    }

    /** Download the QR as a PNG image. */
    public function exportPng(Request $request, Employee $employee): HttpResponse
    {
        $this->authorizeManage($employee);

        $token = $this->tokens->activeToken($employee);

        abort_if($token === null, 404);

        $png = $this->qrRenderer->asPngBytes($token->publicUrl(), 12);

        abort_if($png === '', 500, 'QR image could not be generated.');

        $this->auditExport($employee, $request, 'PNG');

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="feedback-qr-'.$employee->employee_number.'.png"',
        ]);
    }

    /** Download a printable A4 sheet with the QR and instructions. */
    public function exportPdf(Request $request, Employee $employee): HttpResponse
    {
        $this->authorizeManage($employee);

        $token = $this->tokens->activeToken($employee);

        abort_if($token === null, 404);

        $employee->loadMissing([
            'currentAssignment.organization',
            'currentAssignment.organizationUnit',
            'currentAssignment.position',
        ]);

        $assignment = $employee->currentAssignment;

        $pdf = Pdf::loadView('pdf.feedback-qr', [
            'qr' => $this->qrRenderer->asSvgDataUri($token->publicUrl(), 260),
            'url' => $token->publicUrl(),
            'organization' => $assignment?->organization?->name_en,
            'unit' => $assignment?->organizationUnit?->name_en,
            'position' => $assignment?->position?->title_en,
        ])->setPaper('a4');

        $this->auditExport($employee, $request, 'PDF');

        return $pdf->download('feedback-qr-'.$employee->employee_number.'.pdf');
    }

    /**
     * Feedback summary shown alongside the QR.
     *
     * Hidden entries are excluded from the average so a suppressed abusive
     * comment cannot drag an employee's visible score down.
     *
     * @return array{total: int, average: float, recent: array<int, array<string, mixed>>}
     */
    private function stats(Employee $employee): array
    {
        $base = EmployeeServiceFeedback::query()
            ->where('employee_id', $employee->getKey())
            ->visible();

        $row = (clone $base)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('AVG(rating) AS average_rating')
            ->first();

        $recent = (clone $base)
            ->with(['positionService:id,service_no,name_en,name_am'])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (EmployeeServiceFeedback $item): array => [
                'id' => $item->id,
                'rating' => $item->rating,
                'comment' => $item->comment,
                'status' => $item->status->value,
                'created_at' => $item->created_at?->toIso8601String(),
                'service_type' => $item->service_name_snapshot ?? $item->positionService?->name_en,
            ])
            ->all();

        return [
            'total' => (int) ($row->total_count ?? 0),
            'average' => round((float) ($row->average_rating ?? 0), 2),
            'recent' => $recent,
        ];
    }

    /** @return array<string, mixed> */
    private function tokenPayload(mixed $token): array
    {
        return [
            'id' => $token->id,
            'status' => $token->status->value,
            'url' => $token->publicUrl(),
            // Rendered for display only; the raw token is never echoed as text.
            'qr_svg' => $this->qrRenderer->asSvgDataUri($token->publicUrl(), 220),
            'scan_count' => $token->scan_count,
            'last_scanned_at' => $token->last_scanned_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }

    /**
     * Gate every action on the manage permission AND the employee's scope.
     *
     * The permission alone is not enough: a scoped Organizational Admin must
     * not be able to mint or revoke a QR for an employee in another
     * organization by guessing a route parameter.
     */
    private function authorizeManage(Employee $employee): void
    {
        $this->authorize('manageSettings', EmployeeServiceFeedback::class);
        $this->authorize('view', $employee);
    }

    private function auditExport(Employee $employee, Request $request, string $format): void
    {
        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackQrExported,
            actor: Auth::user(),
            auditable: $employee,
            reason: 'Employee feedback QR exported as '.$format,
            request: $request,
        );
    }
}
