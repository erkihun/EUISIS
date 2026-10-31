<?php

declare(strict_types=1);

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Performance\Application\Services\EpmsSettings;

/**
 * Base for EPMS controllers: business logic lives in App\Services\Performance;
 * controllers validate input, call one service and redirect/render.
 */
abstract class PerformanceController extends Controller
{
    protected function ensureEnabled(): void
    {
        abort_unless(app(EpmsSettings::class)->enabled(), 404);
    }

    protected function saved(?string $message = null): RedirectResponse
    {
        return back()->with('flash', ['message' => $message ?? __('performance.saved'), 'type' => 'success']);
    }
}
