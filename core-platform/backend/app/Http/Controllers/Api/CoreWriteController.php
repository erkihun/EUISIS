<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ChangeCardStatus;
use App\Actions\RegisterEmployee;
use App\Actions\TransferEmployee;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\IdCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master-data changes through the Core actions. Only officers and admins reach
 * these routes (`core.writer` middleware); each action checks scope itself.
 */
class CoreWriteController extends Controller
{
    public function registerEmployee(Request $request, RegisterEmployee $register): JsonResponse
    {
        $data = $request->validate([
            // Blank = the next number from Core settings › Numbering.
            'employee_number' => ['nullable', 'string', 'max:64', Rule::unique('employees', 'employee_number')],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            // Used for the duplicate check and kept only as a keyed hash.
            'national_id' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z ]{4,32}$/'],
            'position_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
            'confirm_not_duplicate' => ['sometimes', 'boolean'],
            'duplicate_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $register->execute($data, $request->user())], 201);
    }

    public function transfer(Request $request, Employee $employee, TransferEmployee $transfer): JsonResponse
    {
        $data = $request->validate([
            'position_id' => ['required', 'uuid'],
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'assignment_type' => ['sometimes', 'string', 'max:24'],
            'reason_code' => ['nullable', 'string', 'max:32'],
            'source_document_id' => ['nullable', 'uuid'],
            'external_reference' => ['nullable', 'string', 'max:128'],
        ]);
        $provenance = array_intersect_key($data, array_flip(['assignment_type', 'reason_code', 'source_document_id', 'external_reference'])) + ['source_system' => 'api'];

        return response()->json(['data' => $transfer->execute($employee, $data['position_id'], $data['effective_date'], $request->user(), $data['reason'] ?? null, $provenance)]);
    }

    public function changeCard(Request $request, IdCard $card, string $transition, ChangeCardStatus $change): JsonResponse
    {
        // Blocking or ending a card needs a written reason; the routine print, issue and activate steps do not.
        $rules = in_array($transition, ChangeCardStatus::NEEDS_REASON, true) ? ['required', 'string', 'min:5', 'max:1000'] : ['nullable', 'string', 'max:1000'];
        $data = $request->validate(['reason' => $rules]);

        return response()->json(['data' => $change->execute($card, $transition, $data['reason'] ?? null, $request->user())->only(['id', 'card_number', 'status'])]);
    }
}
