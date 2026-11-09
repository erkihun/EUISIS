<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The integration API's error body (docs/integration/api-v1-contract.md):
 *
 *   { "message": "...", "error_code": "...", "request_id": "...", ...extra }
 *
 * `message` and `error_code` keep the names existing consumers already read;
 * `request_id` is the correlation id also sent as X-Request-ID, so a partner
 * can quote it when reporting a problem. Never a trace or an internal detail.
 */
final class ApiError
{
    /** @param array<string, mixed> $extra */
    public static function response(Request $request, int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error_code' => $code,
            ...$extra,
            'request_id' => self::requestId($request),
        ], $status);
    }

    public static function requestId(Request $request): ?string
    {
        $id = $request->attributes->get('correlation_id');

        return is_string($id) ? $id : null;
    }
}
