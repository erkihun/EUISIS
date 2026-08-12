<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\IdCards\ActivateCardAction;
use App\Actions\IdCards\IssueCardAction;
use App\Actions\IdCards\ReplaceCardAction;
use App\Actions\IdCards\ReportLostOrDamagedCardAction;
use App\Actions\IdCards\RevokeCardAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IdCards\ActivateCardRequest;
use App\Http\Requests\IdCards\IssueCardRequest;
use App\Http\Requests\IdCards\ReplaceCardRequest;
use App\Http\Requests\IdCards\ReportDamagedCardRequest;
use App\Http\Requests\IdCards\ReportLostCardRequest;
use App\Http\Requests\IdCards\RevokeCardRequest;
use App\Http\Resources\IdCardResource;
use App\Models\IdCard;
use App\Models\Organization;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IdCardController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scopeService): Response
    {
        $this->authorize('viewAny', IdCard::class);

        $allowedOrgIds = $scopeService->accessibleOrganizationIds($request->user());

        $baseQuery = IdCard::query()
            ->with([
                'employee.currentAssignment.organization',
                'employee.currentAssignment.organizationUnit',
                'employee.currentAssignment.position',
            ])
            ->when(
                $allowedOrgIds->isNotEmpty(),
                fn ($q) => $q->whereHas(
                    'employee.currentAssignment',
                    fn ($aq) => $aq->whereIn('organization_id', $allowedOrgIds)
                )
            );

        $summary = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', CardStatus::Active)->count(),
            'pending' => (clone $baseQuery)->where('status', CardStatus::PendingPrint)->count(),
            'expired' => (clone $baseQuery)->where('status', CardStatus::Expired)->count(),
            'revoked' => (clone $baseQuery)->where('status', CardStatus::Revoked)->count(),
            'lost' => (clone $baseQuery)->where('status', CardStatus::Lost)->count(),
        ];

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'organization_id' => ['nullable', 'uuid'],
            'issued_from' => ['nullable', 'date_format:Y-m-d'],
            'expires_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $cards = $baseQuery
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->whereLike('card_number', "%{$search}%", caseSensitive: false)
                        ->orWhereHas('employee', fn ($employee) => $employee
                            ->whereLike('employee_number', "%{$search}%", caseSensitive: false)
                            ->orWhereLike('first_name', "%{$search}%", caseSensitive: false)
                            ->orWhereLike('middle_name', "%{$search}%", caseSensitive: false)
                            ->orWhereLike('last_name', "%{$search}%", caseSensitive: false));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['organization_id'] ?? null, fn ($query, string $organizationId) => $query->whereHas(
                'employee.currentAssignment',
                fn ($assignment) => $assignment->where('organization_id', $organizationId),
            ))
            ->when($filters['issued_from'] ?? null, fn ($query, string $date) => $query->whereDate('issued_at', '>=', $date))
            ->when($filters['expires_to'] ?? null, fn ($query, string $date) => $query->whereDate('expires_at', '<=', $date))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $organizations = Organization::query()
            ->select(['id', 'name_en', 'name_am'])
            ->when($allowedOrgIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $allowedOrgIds))
            ->orderBy('name_en')
            ->get();

        return Inertia::render('IdCards/Index', [
            'cards' => IdCardResource::collection($cards),
            'summary' => $summary,
            'filters' => $filters,
            'organizations' => $organizations,
            'can' => [
                'create' => request()->user()?->can('create', IdCard::class),
                'submitRequest' => request()->user()?->can('id-cards.submitRequest') || request()->user()?->can('cards.manage'),
                'createPrintBatch' => request()->user()?->can('id-cards.createPrintBatch') || request()->user()?->can('cards.manage'),
            ],
        ]);
    }

    public function show(IdCard $card, CardQrPayloadService $qrPayloadService): Response
    {
        $this->authorize('view', $card);
        $qrPayloadService->ensurePublicReference($card);

        $card->load([
            'employee.currentAssignment.organization',
            'employee.currentAssignment.position',
            'employee.currentAssignment.organizationUnit',
            'cardRequest.requester',
            'cardRequest.reviewer',
            'cardRequest.approver',
            'previousCard',
            'replacementCard',
            'verifications',
            'issuance.issuer',
            'replacements.newCard',
        ]);

        $user = request()->user();

        $card->makeVisible('qr_payload');

        return Inertia::render('IdCards/Show', [
            'card' => $card,
            'can' => [
                'view' => $user?->can('view', $card),
                'update' => $user?->can('update', $card),
                'print' => $card->status === CardStatus::PendingPrint && $user?->can('print', $card),
                'issue' => $card->status === CardStatus::Printed && $user?->can('issue', $card),
                'activate' => $card->status === CardStatus::Issued && $user?->can('activate', $card),
                'reportLost' => in_array($card->status, [CardStatus::Active, CardStatus::Issued], true) && $user?->can('reportLost', $card),
                'reportDamaged' => in_array($card->status, [CardStatus::Active, CardStatus::Issued], true) && $user?->can('reportDamaged', $card),
                'replace' => in_array($card->status, [CardStatus::Lost, CardStatus::Damaged, CardStatus::Expired, CardStatus::Active], true) && $user?->can('replace', $card),
                'revoke' => ! in_array($card->status, [CardStatus::Revoked, CardStatus::Replaced, CardStatus::Expired], true) && $user?->can('revoke', $card),
                'printAnytime' => $user?->can('printAnytime', $card),
                'exportPng' => $user?->can('exportPng', $card),
                'previewSvg' => $user?->can('previewSvg', $card),
            ],
        ]);
    }

    public function preview(IdCard $card, CardQrPayloadService $qrPayloadService): Response
    {
        $this->authorize('view', $card);
        $qrPayloadService->ensurePublicReference($card);

        $card->load([
            'employee.currentAssignment.organization',
            'employee.currentAssignment.organizationUnit',
            'employee.currentAssignment.position',
            'cardRequest',
        ]);

        $user = request()->user();

        $card->makeVisible('qr_payload');

        return Inertia::render('IdCards/Preview', [
            'card' => $card,
            'can' => [
                'print' => $user?->can('print', $card),
            ],
        ]);
    }

    public function issue(IssueCardRequest $request, IdCard $card, IssueCardAction $issueCardAction): RedirectResponse
    {
        $issueCardAction->execute($card, $request->user(), $request->input('issued_to'), $request->input('received_by'));

        return back()->with('success', __('id-cards.issued_successfully'));
    }

    public function activate(ActivateCardRequest $request, IdCard $card, ActivateCardAction $activateCardAction): RedirectResponse
    {
        $activateCardAction->execute($card, $request->user(), $request->input('notes'));

        return back()->with('success', __('id-cards.activated_successfully'));
    }

    public function reportLost(ReportLostCardRequest $request, IdCard $card, ReportLostOrDamagedCardAction $action): RedirectResponse
    {
        $action->execute($card, 'lost', $request->user(), $request->input('reason'));

        return back()->with('success', __('id-cards.reported_lost'));
    }

    public function reportDamaged(ReportDamagedCardRequest $request, IdCard $card, ReportLostOrDamagedCardAction $action): RedirectResponse
    {
        $action->execute($card, 'damaged', $request->user(), $request->input('reason'));

        return back()->with('success', __('id-cards.reported_damaged'));
    }

    public function replace(ReplaceCardRequest $request, IdCard $card, ReplaceCardAction $replaceCardAction): RedirectResponse
    {
        $replaceCardAction->execute($card, $request->user(), $request->input('reason'));

        return redirect()->route('card-requests.index')->with('success', __('id-cards.replacement_submitted'));
    }

    public function revoke(RevokeCardRequest $request, IdCard $card, RevokeCardAction $revokeCardAction): RedirectResponse
    {
        $revokeCardAction->execute($card, $request->user(), $request->input('reason'));

        return back()->with('success', __('id-cards.revoked_successfully'));
    }

    public function exportAudit(Request $request, IdCard $card, WriteAuditLogAction $writeAuditLogAction): JsonResponse
    {
        $side = $request->input('side', 'front');
        $action = $request->input('action', 'export_png');

        if ($action === 'print') {
            $this->authorize('printAnytime', $card);
            $eventType = AuditEventType::CardPrintedAnytime;
        } else {
            $this->authorize('exportPng', $card);
            $eventType = AuditEventType::CardExportedPng;
        }

        $writeAuditLogAction->execute(
            $eventType,
            $request->user(),
            $card,
            $card->employee?->currentAssignment?->organization_id,
            newValues: [
                'card_number' => $card->card_number,
                'side' => $side,
                'action' => $action,
            ],
        );

        return response()->json(['success' => true]);
    }
}
