<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\SaveEstablishment;
use App\Actions\SavePosition;
use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Positions and their establishments (approved seats). */
class PositionController extends Controller
{
    public function __construct(private readonly OrganizationScope $scope) {}

    public function show(Request $request, Position $position, SeatCapacity $seats): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), $position->organization_id), 404);
        $position->load(['organization:id,code,name_en,name_am', 'unit:id,code,name_en,name_am']);

        return response()->json(['data' => [
            'position' => $position->only(['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'title_en', 'title_am', 'grade_level', 'is_active', 'effective_from', 'organization', 'unit']),
            'capacity' => $seats->capacity((string) $position->id),
            'holders' => $position->holders()->with('employee:id,employee_number,full_name')->get(['id', 'employee_id', 'effective_from']),
            'establishments' => PositionEstablishment::query()->where('position_id', $position->id)->orderByDesc('effective_from')
                ->get(['id', 'establishment_number', 'approved_slots', 'effective_from', 'effective_to', 'status', 'approval_reference']),
        ]]);
    }

    public function store(Request $request, SavePosition $save): JsonResponse
    {
        return response()->json(['data' => $save->execute(null, $this->data($request, true), $request->user())], 201);
    }

    public function update(Request $request, Position $position, SavePosition $save): JsonResponse
    {
        return response()->json(['data' => $save->execute($position, $this->data($request, false), $request->user())]);
    }

    public function approveEstablishment(Request $request, Position $position, SaveEstablishment $save): JsonResponse
    {
        $data = $request->validate([
            'approved_slots' => ['required', 'integer', 'min:1', 'max:10000'],
            'effective_from' => ['required', 'date'],
            'establishment_number' => ['nullable', 'string', 'max:255'],
            'approval_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $save->approve($position, $data, $request->user())], 201);
    }

    public function endEstablishment(Request $request, PositionEstablishment $establishment, SaveEstablishment $save): JsonResponse
    {
        $data = $request->validate(['effective_to' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $save->end($establishment, $data['effective_to'], $request->user(), $data['reason'] ?? null)]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'organization_unit_id' => ['nullable', 'uuid', Rule::exists('organization_units', 'id')],
            'job_position_code' => ['nullable', 'string', 'max:64'],
            'title_en' => [$required, 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'grade_level' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
