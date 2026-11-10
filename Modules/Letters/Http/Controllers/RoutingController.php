<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Letters\Application\Actions\ResolveRouting;
use Modules\Letters\Application\Actions\RouteLetter;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;
use Modules\Letters\Http\Requests\RouteLetterRequest;
use Modules\Letters\Http\Requests\RoutingOutcomeRequest;

/** Forwarding inside the institution and the addressee's answers. */
final class RoutingController extends Controller
{
    public function store(RouteLetterRequest $request, LetterRegistration $registration, RouteLetter $route): RedirectResponse
    {
        $this->authorize('route', $registration);
        $route->execute($registration, $request->validated(), $request->user());

        return back()->with('success', __('letters.flash.routed'));
    }

    /** The person a letter was forwarded to passes it on; the new step hangs under theirs. */
    public function forward(RouteLetterRequest $request, LetterRouting $routing, RouteLetter $route): RedirectResponse
    {
        $this->authorize('forward', $routing);
        $route->execute($routing->registration, $request->validated(), $request->user(), $routing);

        return back()->with('success', __('letters.flash.routed'));
    }

    public function acknowledge(Request $request, LetterRouting $routing, ResolveRouting $resolve): RedirectResponse
    {
        $this->authorize('act', $routing);
        $resolve->acknowledge($routing, $request->user());

        return back()->with('success', __('letters.flash.acknowledged'));
    }

    public function complete(RoutingOutcomeRequest $request, LetterRouting $routing, ResolveRouting $resolve): RedirectResponse
    {
        $this->authorize('act', $routing);
        $resolve->complete($routing, (string) $request->validated('outcome'), $request->user());

        return back()->with('success', __('letters.flash.completed'));
    }

    public function return(RoutingOutcomeRequest $request, LetterRouting $routing, ResolveRouting $resolve): RedirectResponse
    {
        $this->authorize('act', $routing);
        $resolve->return($routing, (string) $request->validated('outcome'), $request->user());

        return back()->with('success', __('letters.flash.routing_returned'));
    }
}
