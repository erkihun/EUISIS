<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Reception\Application\Services\ReceptionAccess;
use Modules\Reception\Contracts\Data\ReceptionPermissions;
use Modules\Reception\Domain\Models\ReceptionAppointment;
use Modules\Reception\Domain\Models\ReceptionQueueTicket;
use Modules\Reception\Domain\Models\ReceptionReferral;

final class ReceptionPrintController extends Controller
{
    public function ticket(Request $request, ReceptionQueueTicket $ticket, ReceptionAccess $access): View
    {
        $ticket->loadMissing(['visit.visitor', 'desk', 'counter']);
        $access->authorize($request->user(), ReceptionPermissions::VIEW, $ticket->visit->organization_id);

        return view('reception.queue-slip', compact('ticket'));
    }

    public function appointment(Request $request, ReceptionAppointment $appointment, ReceptionAccess $access): View
    {
        $appointment->loadMissing(['visitor', 'desk']);
        $access->authorize($request->user(), ReceptionPermissions::VIEW, $appointment->organization_id);

        return view('reception.appointment-slip', compact('appointment'));
    }

    public function referral(Request $request, ReceptionReferral $referral, ReceptionAccess $access): View
    {
        $referral->loadMissing('visit.visitor');
        $access->authorizeAny($request->user(), ReceptionPermissions::VIEW, [$referral->from_organization_id, $referral->to_organization_id]);

        return view('reception.referral-slip', compact('referral'));
    }
}
