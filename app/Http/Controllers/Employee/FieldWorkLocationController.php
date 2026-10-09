<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Enums\FieldWorkLocationEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldWork\CaptureFieldWorkLocationRequest;
use App\Models\FieldWorkRequest;
use App\Services\FieldWork\FieldWorkLocationService;
use Illuminate\Http\JsonResponse;

class FieldWorkLocationController extends Controller
{
    public function __construct(private readonly FieldWorkLocationService $locations) {}

    public function checkIn(CaptureFieldWorkLocationRequest $request, FieldWorkRequest $fieldWork): JsonResponse
    {
        return $this->response($request, $fieldWork, FieldWorkLocationEventType::FieldCheckIn);
    }

    public function checkOut(CaptureFieldWorkLocationRequest $request, FieldWorkRequest $fieldWork): JsonResponse
    {
        return $this->response($request, $fieldWork, FieldWorkLocationEventType::FieldCheckOut);
    }

    private function response(CaptureFieldWorkLocationRequest $request, FieldWorkRequest $fieldWork, FieldWorkLocationEventType $type): JsonResponse
    {
        $result = $this->locations->capture($request->user(), $fieldWork, $type, $request->validated());
        $event = $result['event'];

        return response()->json(['event' => ['id' => $event->id, 'event_type' => $event->event_type->value, 'captured_at' => $event->captured_at?->toIso8601String(), 'accuracy_meters' => $event->accuracy_meters, 'validation_status' => $event->validation_status->value, 'distance_from_destination_meters' => $event->distance_from_destination_meters, 'review_state' => $event->review_state], 'session' => ['status' => $result['session']->status->value, 'checked_in_at' => $result['session']->checked_in_at?->toIso8601String(), 'checked_out_at' => $result['session']->checked_out_at?->toIso8601String()], 'message' => $result['reason']], $result['blocked'] ? 422 : 201);
    }
}
