<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Http\Support\ErrorResponses;

/**
 * Base of the module's controllers. A controller validates the shape of the
 * request, resolves the actor's security context on the server and calls a
 * use case; authorization and the workflow rules live in the use cases.
 */
abstract class ReportingController extends Controller
{
    /**
     * Every action runs through here: a refusal by the workflow rules, the
     * guard, the upload inspector or the maintenance switch is answered as a
     * message (or a 403), never as an error page.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $parameters
     */
    public function callAction($method, $parameters)
    {
        try {
            return parent::callAction($method, $parameters);
        } catch (\Throwable $e) {
            foreach (ErrorResponses::HANDLED as $class) {
                if ($e instanceof $class) {
                    return ErrorResponses::for($e, request());
                }
            }
            throw $e;
        }
    }

    protected function actor(Request $request): ActorContext
    {
        return app(ReportingAccessResolver::class)->contextFor((int) $request->user()->getAuthIdentifier());
    }

    /** am or en, as the reader's interface is set. */
    protected function language(): string
    {
        return app()->getLocale() === 'am' ? 'am' : 'en';
    }
}
