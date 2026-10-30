<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workflows\GenerateSettlementReportAction;
use App\Http\Controllers\Controller;
use App\Models\ServiceProvider;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProviderSettlementController extends Controller
{
    public function __invoke(Request $request, ServiceProvider $provider, string $period, GenerateSettlementReportAction $generateSettlementReportAction): JsonResponse
    {
        try {
            return response()->json($generateSettlementReportAction->execute($provider, $period));
        } catch (InvalidArgumentException) {
            return ApiError::response($request, 422, 'invalid_period', 'Period must be YYYY-MM or YYYY-MM-DD.');
        }
    }
}
