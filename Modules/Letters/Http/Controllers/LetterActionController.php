<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Actions\ApproveLetter;
use Modules\Letters\Application\Actions\CancelLetter;
use Modules\Letters\Application\Actions\CreateReply;
use Modules\Letters\Application\Actions\DispatchLetter;
use Modules\Letters\Application\Actions\ReturnLetter;
use Modules\Letters\Application\Actions\SubmitForApproval;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Http\Requests\DispatchLetterRequest;
use Modules\Letters\Http\Requests\ReasonRequest;

/** The document's transitions: submit, sign, return, dispatch, cancel, reply. Every one re-checks the policy. */
final class LetterActionController extends Controller
{
    public function submit(Request $request, Letter $letter, SubmitForApproval $submit): RedirectResponse
    {
        $this->authorize('submit', $letter);
        $submit->execute($letter, $request->user());

        return back()->with('success', __('letters.flash.submitted'));
    }

    public function approve(Request $request, Letter $letter, ApproveLetter $approve): RedirectResponse
    {
        $this->authorize('approve', $letter);
        $approve->execute($letter, $request->user());

        return back()->with('success', __('letters.flash.approved'));
    }

    public function return(ReasonRequest $request, Letter $letter, ReturnLetter $return): RedirectResponse
    {
        $this->authorize('return', $letter);
        $return->execute($letter, (string) $request->validated('reason'), $request->user());

        return back()->with('success', __('letters.flash.returned'));
    }

    public function dispatch(DispatchLetterRequest $request, Letter $letter, DispatchLetter $dispatch): RedirectResponse
    {
        $this->authorize('dispatch', $letter);
        $letter = $dispatch->execute($letter, $request->user(), $request->validated());

        return back()->with('success', __('letters.flash.dispatched', ['number' => $letter->referenceNumber()]));
    }

    public function cancel(ReasonRequest $request, Letter $letter, CancelLetter $cancel): RedirectResponse
    {
        $this->authorize('cancel', $letter);
        $cancel->execute($letter, (string) $request->validated('reason'), $request->user());

        return back()->with('success', __('letters.flash.cancelled'));
    }

    public function reply(Request $request, Letter $letter, CreateReply $reply, LettersAccess $access): RedirectResponse
    {
        $this->authorize('reply', $letter);
        $organizationId = (string) $request->string('organization_id');
        $allowed = $letter->registrations->first(fn ($r): bool => $r->organization_id === $organizationId && $r->direction->value === 'incoming' && $access->canActFor($request->user(), 'letters.compose', $r->organization_id));
        if ($allowed === null) {
            throw ValidationException::withMessages(['organization_id' => __('letters.validation.reply_organization')]);
        }
        $draft = $reply->execute($letter, $organizationId, $request->user());

        return redirect()->route('letters.edit', $draft)->with('success', __('letters.flash.reply_started'));
    }
}
