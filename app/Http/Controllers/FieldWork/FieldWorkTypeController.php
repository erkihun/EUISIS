<?php

declare(strict_types=1);

namespace App\Http\Controllers\FieldWork;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldWork\SaveFieldWorkTypeRequest;
use App\Models\FieldWorkType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Field Work Types catalog. City-wide configuration (field_work.manage_types).
 * Types are never deleted: a used type is deactivated so history keeps its
 * label, and a deactivated type cannot be chosen for new requests.
 */
class FieldWorkTypeController extends Controller
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('field_work.manage_types'), 403);

        return Inertia::render('FieldWork/Types', [
            'types' => FieldWorkType::query()->ordered()->withCount('requests')->get()
                ->map(fn (FieldWorkType $type): array => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name_en' => $type->name_en,
                    'name_am' => $type->name_am,
                    'description_en' => $type->description_en,
                    'description_am' => $type->description_am,
                    'is_active' => $type->is_active,
                    'sort_order' => $type->sort_order,
                    'requests_count' => $type->requests_count,
                ])->all(),
        ]);
    }

    public function store(SaveFieldWorkTypeRequest $request): RedirectResponse
    {
        $type = new FieldWorkType($request->validated());
        $type->forceFill(['created_by' => $request->user()->getKey(), 'updated_by' => $request->user()->getKey(), 'sort_order' => (int) ($request->validated('sort_order') ?? 0)])->save();
        $this->audit->execute(AuditEventType::FieldWorkTypeSaved, $request->user(), $type, null, null, $type->only(['code', 'name_en', 'is_active']));

        return back()->with('success', __('field-work.flash.type_saved'));
    }

    public function update(SaveFieldWorkTypeRequest $request, FieldWorkType $fieldWorkType): RedirectResponse
    {
        $old = $fieldWorkType->only(['code', 'name_en', 'name_am', 'is_active']);
        $fieldWorkType->fill($request->validated());
        $fieldWorkType->forceFill(['updated_by' => $request->user()->getKey(), 'sort_order' => (int) ($request->validated('sort_order') ?? 0)])->save();
        $this->audit->execute(AuditEventType::FieldWorkTypeSaved, $request->user(), $fieldWorkType, null, $old, $fieldWorkType->only(['code', 'name_en', 'name_am', 'is_active']));

        return back()->with('success', __('field-work.flash.type_saved'));
    }
}
