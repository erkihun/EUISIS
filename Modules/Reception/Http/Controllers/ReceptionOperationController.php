<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Reception\Application\Services\ReceptionOperations;
use Modules\Reception\Domain\Models\ReceptionAppointment;
use Modules\Reception\Domain\Models\ReceptionCounter;
use Modules\Reception\Domain\Models\ReceptionQueueTicket;
use Modules\Reception\Domain\Models\ReceptionReferral;
use Modules\Reception\Domain\Models\ReceptionVisit;
use Modules\Reception\Http\Requests\AssignReceptionStaffRequest;
use Modules\Reception\Http\Requests\IssueQueueTicketRequest;
use Modules\Reception\Http\Requests\RegisterReceptionVisitRequest;
use Modules\Reception\Http\Requests\StoreReceptionAppointmentRequest;
use Modules\Reception\Http\Requests\StoreReceptionCounterRequest;
use Modules\Reception\Http\Requests\StoreReceptionDeskRequest;
use Modules\Reception\Http\Requests\StoreReceptionReferralRequest;
use Modules\Reception\Http\Requests\TransitionQueueTicketRequest;
use Modules\Reception\Http\Requests\UpdateReceptionAppointmentRequest;
use Modules\Reception\Http\Requests\UpdateReceptionCounterRequest;
use Modules\Reception\Http\Requests\UpdateReceptionReferralRequest;

final class ReceptionOperationController extends Controller
{
    public function desk(StoreReceptionDeskRequest $request, ReceptionOperations $operations): RedirectResponse
    {
        $operations->createDesk($request->validated(), $request->user());

        return back()->with('success', __('reception.saved'));
    }

    public function counter(StoreReceptionCounterRequest $request, ReceptionOperations $operations): RedirectResponse
    {
        $operations->createCounter($request->validated(), $request->user());

        return back()->with('success', __('reception.saved'));
    }

    public function updateCounter(UpdateReceptionCounterRequest $request, ReceptionCounter $counter, ReceptionOperations $operations): RedirectResponse
    {
        $operations->updateCounter($counter, $request->validated(), $request->user());

        return back()->with('success', __('reception.saved'));
    }

    public function staff(AssignReceptionStaffRequest $request, ReceptionOperations $operations): RedirectResponse
    {
        $operations->assignStaff($request->validated(), $request->user());

        return back()->with('success', __('reception.saved'));
    }

    public function visit(RegisterReceptionVisitRequest $request, ReceptionOperations $operations): RedirectResponse
    {
        $visit = $operations->registerVisit($request->validated(), $request->user());

        return redirect()->route('reception.queue')->with('success', __('reception.visit_registered', ['reference' => $visit->reference]));
    }

    public function ticket(IssueQueueTicketRequest $request, ReceptionVisit $visit, ReceptionOperations $operations): RedirectResponse
    {
        $ticket = $operations->issueTicket($visit, $request->validated(), $request->user());

        return back()->with('success', __('reception.ticket_issued', ['number' => $ticket->display_number]));
    }

    public function transition(TransitionQueueTicketRequest $request, ReceptionQueueTicket $ticket, ReceptionOperations $operations): RedirectResponse
    {
        $operations->transitionTicket($ticket, $request->validated('transition'), $request->validated('reception_counter_id'), $request->user());

        return back()->with('success', __('reception.queue_updated'));
    }

    public function appointment(StoreReceptionAppointmentRequest $request, ReceptionOperations $operations): RedirectResponse
    {
        $operations->createAppointment($request->validated(), $request->user());

        return back()->with('success', __('reception.saved'));
    }

    public function updateAppointment(UpdateReceptionAppointmentRequest $request, ReceptionAppointment $appointment, ReceptionOperations $operations): RedirectResponse
    {
        $operations->updateAppointment($appointment, $request->validated(), $request->user());

        return back()->with('success', __('reception.saved'));
    }

    public function referral(StoreReceptionReferralRequest $request, ReceptionVisit $visit, ReceptionOperations $operations): RedirectResponse
    {
        $operations->refer($visit, $request->validated(), $request->user());

        return back()->with('success', __('reception.saved'));
    }

    public function acknowledgeReferral(UpdateReceptionReferralRequest $request, ReceptionReferral $referral, ReceptionOperations $operations): RedirectResponse
    {
        $operations->acknowledgeReferral($referral, $request->validated('outcome'), $request->user());

        return back()->with('success', __('reception.saved'));
    }
}
