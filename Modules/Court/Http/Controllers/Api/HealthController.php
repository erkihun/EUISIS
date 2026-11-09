<?php

namespace Modules\Court\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Modules\Court\Http\Controllers\Controller;

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
