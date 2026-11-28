<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Calendar\EthiopianCalendarService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\WorkExperience\Application\Services\WorkExperienceAccess;
use Modules\WorkExperience\Application\Services\WorkExperiencePresenter;
use Modules\WorkExperience\Application\Services\WorkExperienceRequests;
use Modules\WorkExperience\Contracts\Data\WorkExperienceStatus;
use Modules\WorkExperience\Domain\Models\WorkExperienceRequest;

/**
 * Employee management > Work experience requests. The office looks at each
 * request beside the recorded experience, accepts it or sends it back, prints
 * the certificate for signature and seal, and records that it was sent.
 */
class RequestController extends Controller
{
    public function __construct(
        private readonly WorkExperienceAccess $access,
        private readonly WorkExperiencePresenter $presenter,
        private readonly WorkExperienceRequests $requests,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('work_experience.view'), 403);

        $statuses = array_map(static fn (WorkExperienceStatus $status): string => $status->value, WorkExperienceStatus::cases());
        $status = in_array($request->query('status'), $statuses, true) ? (string) $request->query('status') : '';
        $search = is_string($request->query('search')) ? trim((string) $request->query('search')) : '';
        $visible = fn (): Builder => $this->access->constrainRequests(WorkExperienceRequest::query(), $user);

        $query = $visible()->with(['employee', 'organization:id,name_en,name_am'])
            ->when($status !== '', fn (Builder $q) => $q->where('status', $status))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('reference_number', 'like', $like)
                    ->orWhere('certificate_number', 'like', $like)
                    // The code printed on a certificate, typed in to check that the paper is genuine.
                    ->orWhere('verification_code', strtoupper($search))
                    ->orWhereHas('employee', fn (Builder $e) => $e->where('employee_number', 'like', $like)->orWhere('full_name', 'like', $like)->orWhere('name_en', 'like', $like)));
            });

        $open = null;
        $openId = $request->query('request');
        if (is_string($openId) && $openId !== '') {
            $record = WorkExperienceRequest::query()->with('employee')->find($openId);
            if ($record !== null && $this->access->canView($user, $record) && $user->can('work_experience.view')) {
                $open = $this->presenter->detail($record, $user);
            }
        }

        // Registering for an employee who has left: the office looks the person up by name or number.
        $former = null;
        $term = $request->query('former');
        if (is_string($term) && trim($term) !== '' && $user->can('work_experience.requests.manage')) {
            $like = '%'.addcslashes(trim($term), '%_\\').'%';
            $former = $this->access->constrainEmployees(Employee::query(), $user)
                ->whereIn('employees.status', [EmployeeStatus::Transferred->value, EmployeeStatus::Retired->value, EmployeeStatus::Terminated->value, EmployeeStatus::Deceased->value])
                ->where(fn (Builder $w) => $w->where('employees.employee_number', 'like', $like)->orWhere('employees.full_name', 'like', $like)->orWhere('employees.name_en', 'like', $like))
                ->orderBy('employees.full_name')->limit(10)->get()
                ->map(fn (Employee $employee): array => $this->presenter->employee($employee))->all();
        }

        return Inertia::render('modules/work-experience/Requests', [
            'experienceRequests' => $query->orderByRaw("case when status = 'submitted' then 0 when status = 'accepted' then 1 else 2 end")
                ->orderByDesc('created_at')->paginate(25)->withQueryString()
                ->through(fn (WorkExperienceRequest $row): array => $this->presenter->row($row)),
            'filters' => ['status' => $status, 'search' => $search],
            'experienceSummary' => [
                'waiting' => $visible()->where('status', WorkExperienceStatus::Submitted->value)->count(),
                'to_send' => $visible()->where('status', WorkExperienceStatus::Accepted->value)->count(),
                'sent' => $visible()->where('status', WorkExperienceStatus::Sent->value)->count(),
            ],
            'experienceOpenRequest' => $open,
            'experienceFormer' => $former,
            'canManage' => $user->can('work_experience.requests.manage'),
            'canSettings' => $user->can('work_experience.settings.manage'),
        ]);
    }

    /** The office enters a request for an employee who has left and has no account. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('work_experience.requests.manage'), 403);

        $data = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'addressee' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:500'],
            'language' => ['required', 'in:am,en'],
        ], [], [
            'employee_id' => __('work_experience::messages.fields.employee'),
            'addressee' => __('work_experience::messages.fields.addressee'),
            'purpose' => __('work_experience::messages.fields.purpose'),
            'language' => __('work_experience::messages.fields.language'),
        ]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        abort_unless($this->access->inScope($user, $this->access->officeOf($employee)) && ! $this->access->isSelf($user, $employee), 403);

        $this->requests->submit($user, $employee, $data, true);

        return back()->with('success', __('work_experience::messages.flash.registered'));
    }

    public function accept(Request $request, WorkExperienceRequest $experienceRequest): RedirectResponse
    {
        abort_unless($this->access->canManage($request->user(), $experienceRequest), 403);

        $this->requests->accept($request->user(), $experienceRequest);

        return back()->with('success', __('work_experience::messages.flash.accepted'));
    }

    public function sendBack(Request $request, WorkExperienceRequest $experienceRequest): RedirectResponse
    {
        abort_unless($this->access->canManage($request->user(), $experienceRequest), 403);
        // Sending a request back always says why.
        $data = $request->validate(['comment' => ['required', 'string', 'max:1000']], [], ['comment' => __('work_experience::messages.fields.comment')]);

        $this->requests->sendBack($request->user(), $experienceRequest, $data['comment']);

        return back()->with('success', __('work_experience::messages.flash.returned'));
    }

    public function rewrite(Request $request, WorkExperienceRequest $experienceRequest): RedirectResponse
    {
        abort_unless($this->access->canManage($request->user(), $experienceRequest), 403);

        $this->requests->refresh($request->user(), $experienceRequest);

        return back()->with('success', __('work_experience::messages.flash.rewritten'));
    }

    public function markSent(Request $request, WorkExperienceRequest $experienceRequest): RedirectResponse
    {
        abort_unless($this->access->canManage($request->user(), $experienceRequest), 403);
        $data = $request->validate(['delivery_note' => ['nullable', 'string', 'max:500']], [], ['delivery_note' => __('work_experience::messages.fields.delivery_note')]);

        $this->requests->markSent($request->user(), $experienceRequest, $data['delivery_note'] ?? null);

        return back()->with('success', __('work_experience::messages.flash.sent'));
    }

    /** The certificate as a page to print on the institution's paper, then sign and stamp. */
    public function print(Request $request, WorkExperienceRequest $experienceRequest, EthiopianCalendarService $ethiopian): View
    {
        abort_unless($this->access->canManage($request->user(), $experienceRequest), 403);
        abort_unless($experienceRequest->status->hasCertificate() && is_array($experienceRequest->certificate), 404);

        $this->requests->recordPrint($request->user(), $experienceRequest);

        $language = $experienceRequest->language === 'en' ? 'en' : 'am';
        $date = static function (?string $value) use ($ethiopian, $language): string {
            if ($value === null) {
                return '';
            }
            $day = Carbon::parse($value);

            // An Amharic certificate is dated in the Ethiopian calendar; an English one in the Gregorian.
            return $language === 'am'
                ? $ethiopian->formatGregorianDateAsEthiopian($day, 'am').' '.__('work_experience::messages.certificate.era', [], 'am')
                : $day->format('F j, Y');
        };
        $length = static function (array $duration) use ($language): string {
            $parts = [];
            foreach (['years', 'months', 'days'] as $unit) {
                if ((int) ($duration[$unit] ?? 0) > 0) {
                    $parts[] = trans_choice("work_experience::messages.certificate.{$unit}", (int) $duration[$unit], ['n' => (int) $duration[$unit]], $language);
                }
            }

            return $parts === [] ? trans_choice('work_experience::messages.certificate.days', 0, ['n' => 0], $language) : implode(__('work_experience::messages.certificate.joiner', [], $language), $parts);
        };

        return view('work_experience::certificate', [
            'request' => $experienceRequest,
            'certificate' => $experienceRequest->certificate,
            'language' => $language,
            'date' => $date,
            'length' => $length,
            't' => static fn (string $key, array $params = []): string => (string) __("work_experience::messages.certificate.{$key}", $params, $language),
        ]);
    }
}
