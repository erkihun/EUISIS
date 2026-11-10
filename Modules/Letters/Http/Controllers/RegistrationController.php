<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Letters\Application\Actions\CloseRegistration;
use Modules\Letters\Application\Actions\ReceiveLetter;
use Modules\Letters\Application\Actions\RecordAcknowledgement;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Http\Requests\AcknowledgeReceiptRequest;
use Modules\Letters\Http\Requests\CloseRegistrationRequest;
use Modules\Letters\Http\Requests\ReceiveLetterRequest;

/** One institution's registry entry: receiving a dispatched letter and filing it as done. */
final class RegistrationController extends Controller
{
    public function receive(ReceiveLetterRequest $request, LetterRegistration $registration, ReceiveLetter $receive): RedirectResponse
    {
        $this->authorize('receive', $registration);
        $registration = $receive->execute($registration, $request->validated(), $request->user());

        return back()->with('success', __('letters.flash.received', ['number' => $registration->registry_number]));
    }

    public function acknowledge(AcknowledgeReceiptRequest $request, LetterRegistration $registration, RecordAcknowledgement $acknowledge): RedirectResponse
    {
        $this->authorize('acknowledge', $registration);
        $acknowledge->execute($registration, $request->validated(), $request->user());

        return back()->with('success', __('letters.flash.acknowledged_receipt'));
    }

    public function close(CloseRegistrationRequest $request, LetterRegistration $registration, CloseRegistration $close): RedirectResponse
    {
        $this->authorize('close', $registration);
        $note = $request->validated('closing_note');
        $close->execute($registration, is_string($note) && $note !== '' ? $note : null, $request->user());

        return back()->with('success', __('letters.flash.closed'));
    }
}
