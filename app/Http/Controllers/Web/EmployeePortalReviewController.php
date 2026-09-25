<?php

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EmploymentType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeCorrectionRequest;
use App\Models\IdCard;
use App\Models\IdCardPrintSnapshot;
use App\Services\IdCards\IdCardPrintSnapshotService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeePortalReviewController extends Controller
{
    public function photo(Request $request, Employee $employee)
    {
        $this->authorize('view', $employee);
        abort_unless($employee->photo_path && preg_match('#^employee-photos/[a-zA-Z0-9-]+\.(jpg|jpeg|png|webp)$#D', $employee->photo_path), 404);
        return Storage::disk('local')->response($employee->photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function reprints(Request $request, OrganizationScopeService $scope)
    {
        $this->authorize('viewAny', IdCard::class);
        $query = IdCard::query()->where('is_current', true)->where('reprint_required', true)->with('employee.currentAssignment.organization');
        if (! $scope->isUnrestricted($request->user())) {
            $query->whereHas('employee.currentAssignment', fn ($q) => $scope->applyOrganizationScope($q, $request->user()));
        }
        $cards = $query->orderBy('reprint_required_at')->paginate(25)->through(fn ($card) => [
            'id' => $card->id, 'employee' => $card->employee->full_name, 'employee_number' => $card->employee->employee_number,
            'card_number' => $card->card_number, 'status' => $card->status->value,
            'changed_fields' => array_map(fn ($field) => __('employee-portal.fields.'.$field), $card->reprint_reasons ?? []),
            'changed_at' => $card->reprint_required_at?->toDateString(), 'can_print' => $request->user()->can('reprint', $card),
        ]);
        return Inertia::render('IdCards/ReprintQueue', ['cards' => $cards, 'labels' => __('employee-portal')]);
    }

    public function prepare(Request $request, IdCard $card, IdCardPrintSnapshotService $prints)
    {
        $data = $request->validate(['orientation' => ['nullable', 'in:portrait,landscape']]);
        $snapshot = $prints->prepare($card, $request->user(), $data['orientation'] ?? null);
        return to_route('id-cards.print-preparation', [$card, $snapshot]);
    }

    public function preparation(Request $request, IdCard $card, IdCardPrintSnapshot $snapshot)
    {
        $this->authorize('view', $card);
        abort_unless($snapshot->id_card_id === $card->id, 404);
        return Inertia::render('IdCards/PrintPreparation', ['card' => ['id' => $card->id, 'card_number' => $card->card_number], 'snapshot' => ['id' => $snapshot->id, 'orientation' => $snapshot->orientation, 'width_mm' => (float) $snapshot->width_mm, 'height_mm' => (float) $snapshot->height_mm, 'confirmed' => $snapshot->printed_at !== null], 'labels' => __('employee-portal')]);
    }

    public function artifact(Request $request, IdCard $card, IdCardPrintSnapshot $snapshot, string $side)
    {
        $this->authorize('view', $card);
        abort_unless($snapshot->id_card_id === $card->id && in_array($side, ['front', 'back'], true), 404);
        return Storage::disk('local')->response($snapshot->artifact_path.'-'.$side.'.svg', null, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function confirm(Request $request, IdCard $card, IdCardPrintSnapshot $snapshot, IdCardPrintSnapshotService $prints)
    {
        $request->validate(['printed_successfully' => ['required', 'accepted']]);
        $prints->confirm($card, $snapshot, $request->user());
        return back()->with('success', __('employee-portal.saved'));
    }

    public function corrections(Request $request, OrganizationScopeService $scope)
    {
        $this->authorize('viewAny', Employee::class);
        $query = EmployeeCorrectionRequest::query()->where('status', 'pending')->with('employee.currentAssignment');
        if (! $scope->isUnrestricted($request->user())) {
            $query->whereHas('employee.currentAssignment', fn ($q) => $scope->applyOrganizationScope($q, $request->user()));
        }
        return Inertia::render('Employees/CorrectionRequests', [
            'requests' => $query->latest()->paginate(25)->through(fn ($correction) => [
                'id' => $correction->id, 'employee' => $correction->employee->full_name,
                'employee_id' => $correction->employee_id, 'field' => __('employee-portal.fields.'.$correction->field),
                'value' => $correction->field === 'national_id' ? '**** **** '.mb_substr($correction->requested_value, -4) : $correction->requested_value,
                'can_review' => $request->user()->can('update', $correction->employee),
            ]), 'labels' => __('employee-portal'),
        ]);
    }

    public function review(Request $request, EmployeeCorrectionRequest $correction)
    {
        $this->authorize('update', $correction->employee);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected']]);
        DB::transaction(function () use ($request, $correction, $data) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($correction->employee_id);
            $correction = EmployeeCorrectionRequest::query()->lockForUpdate()->findOrFail($correction->id);
            abort_unless($correction->status === 'pending', 409);
            if ($data['decision'] === 'approved') {
                $rules = match ($correction->field) {
                    'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
                    'employment_type' => ['required', Rule::enum(EmploymentType::class)],
                    'gender' => ['required', 'in:male,female,other'],
                    'employee_number' => ['required', 'string', 'max:255', Rule::unique('employees', 'employee_number')->ignore($employee->id)],
                    'national_id' => ['required', 'string', 'max:100'],
                    default => ['required', 'string', 'max:255'],
                };
                validator(['requested_value' => $correction->requested_value], ['requested_value' => $rules])->validate();
                // Names and national IDs require HR's full master-data editor to keep split names and duplicate review consistent.
                if (in_array($correction->field, ['full_name', 'national_id'], true)) {
                    abort_unless($employee->getAttribute($correction->field) === $correction->requested_value, 422, __('employee-portal.complete_master_edit'));
                } else {
                    $employee->setAttribute($correction->field, $correction->requested_value);
                    $employee->save();
                }
            }
            $correction->update(['status' => $data['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            app(WriteAuditLogAction::class)->execute(AuditEventType::EmployeeCorrectionReviewed, $request->user(), $correction, $employee->currentAssignment?->organization_id, newValues: ['field' => $correction->field, 'status' => $data['decision']]);
        });
        return back()->with('success', __('employee-portal.saved'));
    }
}
