<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LettersAccess;

/** Pick-lists the compose and register pages fetch when the institution changes. Scope is re-checked here. */
final class LookupController extends Controller
{
    public function organization(Request $request, string $organization, LettersAccess $access, LetterLookups $lookups): JsonResponse
    {
        $user = $request->user();
        abort_unless(($user->can('letters.compose') || $user->can('letters.register') || $user->can('letters.route')) && $access->reaches($user, $organization), 403);

        return response()->json([
            'units' => $lookups->units($organization),
            'people' => $lookups->people($organization),
            'templates' => $user->can('letters.compose') ? $lookups->templatesFor($organization) : [],
        ]);
    }
}
