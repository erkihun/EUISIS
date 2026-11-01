<?php

namespace Modules\Court\Http\Controllers\Api;

use Modules\Court\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'app' => 'court-ms',
            'time' => now()->toIso8601String(),
        ]);
    }
}
