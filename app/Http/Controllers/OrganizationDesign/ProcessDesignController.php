<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\ActivityRequest;
use App\Http\Requests\OrganizationDesign\IoSyncRequest;
use App\Http\Requests\OrganizationDesign\MasterStandardRequest;
use App\Http\Requests\OrganizationDesign\TaskRequest;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Services\OrganizationDesign\TaskStandardService;
use App\Services\OrganizationDesign\ProcessArchitectureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Everything under a process: activities, tasks, typed I/O rows, master standards and the Daily Work bridge. */
class ProcessDesignController extends Controller
{
    public function __construct(private readonly ProcessArchitectureService $design, private readonly TaskStandardService $standards) {}

    public function storeActivity(ActivityRequest $request, Process $process): RedirectResponse
    {
        $this->design->createActivity($request->user(), $process, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.activity_saved'), 'type' => 'success']);
    }

    public function updateActivity(ActivityRequest $request, ProcessActivity $activity): RedirectResponse
    {
        $this->design->updateActivity($request->user(), $activity, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.activity_saved'), 'type' => 'success']);
    }

    public function destroyActivity(Request $request, ProcessActivity $activity): RedirectResponse
    {
        $this->authorize('update', $activity->process);
        $this->design->removeActivity($request->user(), $activity);

        return back()->with('flash', ['message' => __('organization-design.activity_removed'), 'type' => 'success']);
    }

    public function storeTask(TaskRequest $request, ProcessActivity $activity): RedirectResponse
    {
        $this->design->createTask($request->user(), $activity, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.task_saved'), 'type' => 'success']);
    }

    public function updateTask(TaskRequest $request, ProcessTask $task): RedirectResponse
    {
        $this->design->updateTask($request->user(), $task, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.task_saved'), 'type' => 'success']);
    }

    public function destroyTask(Request $request, ProcessTask $task): RedirectResponse
    {
        $this->authorize('update', $task->process);
        $this->design->removeTask($request->user(), $task);

        return back()->with('flash', ['message' => __('organization-design.task_removed'), 'type' => 'success']);
    }

    public function syncIo(IoSyncRequest $request): RedirectResponse
    {
        $this->design->syncIo($request->user(), $request->subject(), $request->validated('items'));

        return back()->with('flash', ['message' => __('organization-design.io_saved'), 'type' => 'success']);
    }

    public function storeTaskStandard(MasterStandardRequest $request, ProcessTask $task): RedirectResponse
    {
        $this->standards->createDraft($request->user(), $task, $request->standardData());

        return back()->with('flash', ['message' => __('work-standards.draft_saved'), 'type' => 'success']);
    }

    public function storeActivityStandard(MasterStandardRequest $request, ProcessActivity $activity): RedirectResponse
    {
        $this->standards->createDraft($request->user(), $activity, $request->standardData());

        return back()->with('flash', ['message' => __('work-standards.draft_saved'), 'type' => 'success']);
    }

    public function updateStandard(MasterStandardRequest $request, PositionServiceTaskStandard $standard): RedirectResponse
    {
        $this->standards->updateDraft($request->user(), $standard, $request->standardData());

        return back()->with('flash', ['message' => __('work-standards.draft_saved'), 'type' => 'success']);
    }

    public function destroyStandard(Request $request, PositionServiceTaskStandard $standard): RedirectResponse
    {
        $this->authorize('manageStandards', $this->processOf($standard));
        $this->standards->deleteDraft($request->user(), $standard);

        return back()->with('flash', ['message' => __('work-standards.deleted'), 'type' => 'success']);
    }

    public function approveStandard(Request $request, PositionServiceTaskStandard $standard): RedirectResponse
    {
        $this->authorize('approveStandards', $this->processOf($standard));
        $this->standards->approve($request->user(), $standard);

        return back()->with('flash', ['message' => __('work-standards.approved'), 'type' => 'success']);
    }

    public function retireStandard(Request $request, PositionServiceTaskStandard $standard): RedirectResponse
    {
        $this->authorize('approveStandards', $this->processOf($standard));
        $this->standards->retire($request->user(), $standard);

        return back()->with('flash', ['message' => __('work-standards.retired'), 'type' => 'success']);
    }

    public function linkPositionTask(Request $request, PositionServiceTask $positionTask): RedirectResponse
    {
        $this->authorize('linkPositionTask', [Process::class, $positionTask]);
        $data = $request->validate(['master_task_id' => ['nullable', 'uuid', Rule::exists('process_tasks', 'id')]]);
        $this->design->linkPositionTask($request->user(), $positionTask, $data['master_task_id'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.position_task_linked'), 'type' => 'success']);
    }

    /** Master standards only: a position-task standard belongs to the Daily Work pages. */
    private function processOf(PositionServiceTaskStandard $standard): Process
    {
        $subject = $standard->subjectModel();

        return $subject instanceof ProcessTask || $subject instanceof ProcessActivity ? $subject->process : abort(404);
    }
}
