<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\AddActionItemEvidence;
use Modules\ServiceMonitoring\Application\Actions\UpdateActionItemStatus;
use Modules\ServiceMonitoring\Application\Services\ActionItemPresenter;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Enums\ActionItemStatus;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Http\Requests\UpdateActionItemRequest;
use Modules\ServiceMonitoring\Http\Requests\UploadFileRequest;

/**
 * Action items across visits, overdue first (D22): those of the participants
 * the user sees, and those owned by organizations in the user's scope.
 */
final class ActionItemController extends Controller
{
    public function __construct(
        private readonly ServiceMonitoringAccess $access,
        private readonly OrganizationScopeService $scope,
        private readonly FiscalCalendar $calendar,
        private readonly ActionItemPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Participant::class);
        $user = $request->user();
        $today = $this->calendar->today();
        $status = ActionItemStatus::tryFrom($request->string('status')->toString());
        $overdueOnly = $request->boolean('overdue');

        $items = ActionItem::query()
            ->when(! $this->access->isCitywide($user), fn (Builder $q): Builder => $q->where(fn (Builder $w): Builder => $w
                ->whereHas('visit.participant', fn (Builder $p): Builder => $this->access->scopeParticipants($p, $user))
                ->orWhereIn('owner_organization_id', $this->scope->allowedOrganizationIds($user))))
            ->when($status !== null, fn (Builder $q): Builder => $q->where('status', $status->value))
            ->when($overdueOnly, fn (Builder $q): Builder => $q->overdue($today))
            ->with(['ownerOrganization:id,name_en,name_am', 'ownerUser:id,name', 'evidence', 'visit.participant'])
            ->orderBy('due_on')->paginate(50)->withQueryString();

        return Inertia::render('ServiceMonitoring/ActionItems/Index', [
            'items' => $items->through(fn (ActionItem $item): array => $this->presenter->present($item, $user, $today)),
            'filters' => ['status' => $status?->value, 'overdue' => $overdueOnly],
            'statuses' => ActionItemStatus::values(),
        ]);
    }

    public function update(UpdateActionItemRequest $request, ActionItem $actionItem, UpdateActionItemStatus $update): RedirectResponse
    {
        $update->execute($actionItem, ActionItemStatus::from((string) $request->validated('status')), $request->validated('completion_note'), $request->user());

        return back()->with('success', __('service-monitoring.flash.action_item_saved'));
    }

    public function evidence(UploadFileRequest $request, ActionItem $actionItem, AddActionItemEvidence $add): RedirectResponse
    {
        $this->authorize('update', $actionItem);
        $add->execute($actionItem, $request->file('file'), $request->user());

        return back()->with('success', __('service-monitoring.flash.evidence_uploaded'));
    }
}
