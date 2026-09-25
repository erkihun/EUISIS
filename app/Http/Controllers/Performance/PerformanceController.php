<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Services\Performance\EpmsSettings;
use Illuminate\Http\RedirectResponse;

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
