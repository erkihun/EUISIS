<?php

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActAsRespondent
{
    public function handle(Request $request, Closure $next)
    {
        // Flag the session/UI to show respondent navigation while keeping applicant guard/session.
        if (!$request->session()->get('acting_as_respondent')) {
            $request->session()->regenerate();
        }
        $request->session()->put('acting_as_respondent', true);
        return $next($request);
    }
}
