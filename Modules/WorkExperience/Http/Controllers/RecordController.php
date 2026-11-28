<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Http\Controllers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\WorkExperience\Application\Services\WorkExperienceAccess;
use Modules\WorkExperience\Application\Services\WorkExperiencePresenter;
use Modules\WorkExperience\Application\Services\WorkExperienceRecords;
use Modules\WorkExperience\Domain\Models\WorkExperienceRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee management > Work experience. The office that manages employees
 * keeps each employee's experience here: the assignments the system holds
 * come by themselves, the office adds the salary and what the system does
 * not know.
 */
class RecordController extends Controller
{
    private const LEFT = [EmployeeStatus::Transferred, EmployeeStatus::Retired, EmployeeStatus::Terminated, EmployeeStatus::Deceased];

    public function __construct(
        private readonly WorkExperienceAccess $access,
        private readonly WorkExperiencePresenter $presenter,
        private readonly WorkExperienceRecords $records,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('work_experience.view'), 403);

        $search = is_string($request->query('search')) ? trim((string) $request->query('search')) : '';
        $service = in_array($request->query('service'), ['in', 'left'], true) ? (string) $request->query('service') : '';
        $left = array_map(static fn (EmployeeStatus $status): string => $status->value, self::LEFT);

        $employees = $this->access->constrainEmployees(Employee::query(), $user)
            ->with(['currentAssignment.position:id,title_en,title_am', 'currentAssignment.organizationUnit:id,name_en,name_am'])
            ->withCount(['assignments as assignments_count'])
            ->addSelect(['records_count' => WorkExperienceRecord::query()->selectRaw('count(*)')->whereColumn('work_experience_records.employee_id', 'employees.id')])
            ->where('employees.status', '!=', EmployeeStatus::Draft->value)
            ->when($service === 'in', fn (Builder $q) => $q->whereNotIn('employees.status', $left))
            ->when($service === 'left', fn (Builder $q) => $q->whereIn('employees.status', $left))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('employees.employee_number', 'like', $like)->orWhere('employees.full_name', 'like', $like)->orWhere('employees.name_en', 'like', $like));
            })
            ->orderBy('employees.full_name')->orderBy('employees.id');

        $selected = null;
        $selectedId = $request->query('selected');
        if (is_string($selectedId) && $selectedId !== '') {
            $employee = Employee::query()->find($selectedId);
            if ($employee !== null && $this->access->canSeeEmployee($user, $employee)) {
                $selected = [
                    'employee' => $this->presenter->employee($employee),
                    ...$this->presenter->experience($employee),
                    'can_record' => $this->access->canRecord($user, $employee),
                ];
            }
        }

        return Inertia::render('modules/work-experience/Records', [
            'experienceEmployees' => $employees->paginate(25)->withQueryString()->through(fn (Employee $employee): array => [
                ...$this->presenter->employee($employee),
                'position' => $employee->currentAssignment?->position === null ? null : ['name_en' => $employee->currentAssignment->position->title_en, 'name_am' => $employee->currentAssignment->position->title_am],
                'unit' => $employee->currentAssignment?->organizationUnit === null ? null : ['name_en' => $employee->currentAssignment->organizationUnit->name_en, 'name_am' => $employee->currentAssignment->organizationUnit->name_am],
                'service_start_date' => $employee->service_start_date instanceof \DateTimeInterface ? $employee->service_start_date->format('Y-m-d') : null,
                'assignments_count' => (int) $employee->assignments_count,
                'records_count' => (int) $employee->records_count,
            ]),
            'filters' => ['search' => $search, 'service' => $service],
            'experienceSelected' => $selected,
            'canRecordAny' => $user->can('work_experience.records.manage'),
            'today' => now('Africa/Addis_Ababa')->toDateString(),
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($this->access->canRecord($request->user(), $employee), 403);

        $this->records->save($request->user(), $employee, $this->validated($request, true), null, $request->file('evidence'));

        return back()->with('success', __('work_experience::messages.flash.record_saved'));
    }

    public function update(Request $request, WorkExperienceRecord $experienceRecord): RedirectResponse
    {
        $employee = $experienceRecord->employee()->firstOrFail();
        abort_unless($this->access->canRecord($request->user(), $employee), 403);

        $this->records->save($request->user(), $employee, $this->validated($request, false), $experienceRecord, $request->file('evidence'));

        return back()->with('success', __('work_experience::messages.flash.record_saved'));
    }

    public function destroy(Request $request, WorkExperienceRecord $experienceRecord): RedirectResponse
    {
        $employee = $experienceRecord->employee()->firstOrFail();
        abort_unless($this->access->canRecord($request->user(), $employee), 403);

        $this->records->delete($request->user(), $experienceRecord);

        return back()->with('success', __('work_experience::messages.flash.record_removed'));
    }

    /** The evidence of a recorded line: for the office, and for the employee it is about. */
    public function evidence(Request $request, WorkExperienceRecord $experienceRecord, WriteAuditLogAction $audit): StreamedResponse
    {
        $user = $request->user();
        $employee = $experienceRecord->employee()->firstOrFail();
        $own = $user->can('work_experience.request_own') && $this->access->isSelf($user, $employee);
        abort_unless($own || $this->access->canSeeEmployee($user, $employee), 403);
        $disk = $experienceRecord->evidence_disk ?: 'local';
        abort_unless($experienceRecord->evidence_path !== null && Storage::disk($disk)->exists($experienceRecord->evidence_path), 404);

        $audit->execute(AuditEventType::WorkExperienceEvidenceDownloaded, $user, $experienceRecord, $this->access->officeOf($employee), null, [
            'record_id' => $experienceRecord->id, 'employee_id' => $employee->id,
        ], null, $request);

        return Storage::disk($disk)->download($experienceRecord->evidence_path, $experienceRecord->evidence_name ?: 'evidence');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            ...($creating ? ['employee_assignment_id' => ['nullable', 'uuid']] : []),
            'kind' => ['required', 'in:internal,external'],
            'employer_name' => ['nullable', 'string', 'max:255'],
            'position_title' => ['required', 'string', 'max:255'],
            'unit_name' => ['nullable', 'string', 'max:255'],
            'started_on' => ['required', 'date_format:Y-m-d'],
            'ended_on' => ['nullable', 'date_format:Y-m-d'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [], [
            'kind' => __('work_experience::messages.fields.kind'),
            'employer_name' => __('work_experience::messages.fields.employer_name'),
            'position_title' => __('work_experience::messages.fields.position_title'),
            'unit_name' => __('work_experience::messages.fields.unit_name'),
            'started_on' => __('work_experience::messages.fields.started_on'),
            'ended_on' => __('work_experience::messages.fields.ended_on'),
            'monthly_salary' => __('work_experience::messages.fields.monthly_salary'),
            'note' => __('work_experience::messages.fields.note'),
            'evidence' => __('work_experience::messages.fields.evidence'),
        ]);
    }
}
