<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Http\Controllers\Controller;
use App\Models\JobDescription;
use App\Models\OrganizationDesignStatusHistory;
use App\Models\Position;
use App\Models\PositionProfile;
use App\Services\Calendar\LocalizedDateService;
use App\Services\OrganizationDesign\JobDescriptionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Job descriptions: drafting from the profile, sections, regeneration, workflow and publication. */
class JobDescriptionController extends Controller
{
    public function __construct(private readonly JobDescriptionService $jobDescriptions) {}

    public function store(Request $request, Position $position): RedirectResponse
    {
        $this->authorize('draftJobDescription', [JobDescription::class, $position]);
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'position_profile_id' => ['nullable', 'uuid', Rule::exists('position_profiles', 'id')->where('position_id', $position->getKey())],
        ], [], [], ['position_profile_id.exists' => __('organization-design.jd_profile_required')]);
        $jd = $this->jobDescriptions->createDraft($request->user(), $position, $data);

        return to_route('organization-design.job-descriptions.show', $jd)->with('flash', ['message' => __('organization-design.jd_created'), 'type' => 'success']);
    }

    public function show(Request $request, JobDescription $jobDescription): Response
    {
        $this->authorize('view', $jobDescription);
        $user = $request->user();
        $jobDescription->load(['position.organization:id,code,name_en,name_am', 'position.organizationUnit:id,code,name_en,name_am', 'profile:id,version_no,status,effective_from', 'preparer:id,name', 'reviewer:id,name', 'approver:id,name', 'publisher:id,name']);
        $editable = $jobDescription->isEditable();

        return Inertia::render('OrganizationDesign/JobDescriptions/Show', [
            'jobDescription' => [
                ...$jobDescription->only(['id', 'reference_number', 'version_no', 'lineage_key', 'position_profile_id', 'review_comment']),
                'status' => $jobDescription->status->value, 'effective_from' => $jobDescription->effective_from?->toDateString(), 'effective_to' => $jobDescription->effective_to?->toDateString(),
                'position' => ['id' => $jobDescription->position->id, 'job_position_code' => $jobDescription->position->job_position_code, 'title_en' => $jobDescription->position->title_en, 'title_am' => $jobDescription->position->title_am, 'grade_level' => $jobDescription->position->grade_level,
                    'organization' => $jobDescription->position->organization?->only(['id', 'code', 'name_en', 'name_am']), 'unit' => $jobDescription->position->organizationUnit?->only(['id', 'code', 'name_en', 'name_am'])],
                'profile' => $jobDescription->profile ? ['id' => $jobDescription->profile->id, 'version_no' => $jobDescription->profile->version_no, 'status' => $jobDescription->profile->status->value] : null,
                'prepared_by' => $jobDescription->preparer?->name, 'reviewed_by' => $jobDescription->reviewer?->name, 'approved_by' => $jobDescription->approver?->name, 'published_by' => $jobDescription->publisher?->name,
                'submitted_at' => $jobDescription->submitted_at?->toISOString(), 'approved_at' => $jobDescription->approved_at?->toISOString(), 'published_at' => $jobDescription->published_at?->toISOString(), 'generated_at' => $jobDescription->generated_at?->toISOString(),
            ],
            'sections' => $jobDescription->sections()->get()->map->only(['id', 'section_key', 'source', 'sequence', 'title_en', 'title_am', 'content_en', 'content_am', 'payload'])->values(),
            'versions' => $jobDescription->versions()->get(['id', 'version_no', 'status', 'effective_from', 'effective_to'])->map(fn (JobDescription $v) => ['id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status->value, 'effective_from' => $v->effective_from?->toDateString(), 'effective_to' => $v->effective_to?->toDateString()]),
            'validation' => $this->jobDescriptions->validate($jobDescription),
            'history' => OrganizationDesignStatusHistory::query()->where('entity_type', $jobDescription->getMorphClass())->where('entity_id', $jobDescription->getKey())->with('actor:id,name')->orderByDesc('created_at')->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at->toISOString()]),
            'can' => [
                'update' => $editable && $user->can('update', $jobDescription),
                'submit' => $editable && $user->can('update', $jobDescription),
                'review' => $jobDescription->status === OrganizationDesignStatus::UnderReview && $user->can('approve', $jobDescription),
                'approve' => $jobDescription->status === OrganizationDesignStatus::UnderReview && $user->can('approve', $jobDescription) && (int) $jobDescription->prepared_by !== (int) $user->getKey(),
                'publish' => $jobDescription->status === OrganizationDesignStatus::Approved && $user->can('publish', $jobDescription),
                'cancel' => in_array($jobDescription->status, [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview], true) && $user->can('update', $jobDescription),
                'version' => $jobDescription->status === OrganizationDesignStatus::Published && $user->can('update', $jobDescription),
            ],
        ]);
    }

    public function updateSection(Request $request, JobDescription $jobDescription, string $sectionKey): RedirectResponse
    {
        $this->authorize('update', $jobDescription);
        $data = $request->validate(['content_en' => ['nullable', 'string', 'max:20000'], 'content_am' => ['nullable', 'string', 'max:20000']]);
        $this->jobDescriptions->updateSection($request->user(), $jobDescription, $sectionKey, $data);

        return back()->with('flash', ['message' => __('organization-design.jd_section_saved'), 'type' => 'success']);
    }

    public function regenerate(Request $request, JobDescription $jobDescription): RedirectResponse
    {
        $this->authorize('update', $jobDescription);
        $this->jobDescriptions->regenerate($request->user(), $jobDescription);

        return back()->with('flash', ['message' => __('organization-design.jd_regenerated'), 'type' => 'success']);
    }

    public function submit(Request $request, JobDescription $jobDescription): RedirectResponse
    {
        $this->authorize('update', $jobDescription);
        $this->jobDescriptions->submit($request->user(), $jobDescription);

        return back()->with('flash', ['message' => __('organization-design.jd_submitted'), 'type' => 'success']);
    }

    public function returnToDraft(Request $request, JobDescription $jobDescription): RedirectResponse
    {
        $this->authorize('approve', $jobDescription);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $this->jobDescriptions->returnToDraft($request->user(), $jobDescription, $data['reason'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.jd_returned'), 'type' => 'success']);
    }

    public function approve(Request $request, JobDescription $jobDescription): RedirectResponse
    {
        $this->authorize('approve', $jobDescription);
        $this->jobDescriptions->approve($request->user(), $jobDescription);

        return back()->with('flash', ['message' => __('organization-design.jd_approved'), 'type' => 'success']);
    }

    public function publish(Request $request, JobDescription $jobDescription): RedirectResponse
    {
        $this->authorize('publish', $jobDescription);
        $this->jobDescriptions->publish($request->user(), $jobDescription);

        return back()->with('flash', ['message' => __('organization-design.jd_published'), 'type' => 'success']);
    }

    public function cancel(Request $request, JobDescription $jobDescription): RedirectResponse
    {
        $this->authorize('update', $jobDescription);
        $this->jobDescriptions->cancel($request->user(), $jobDescription, $request->string('reason')->toString() ?: null);

        return to_route('organization-design.position-design.show', $jobDescription->position_id)->with('flash', ['message' => __('organization-design.jd_cancelled'), 'type' => 'success']);
    }

    /** The formal bilingual document (brief §26): English dated in the Gregorian calendar, Amharic in the Ethiopian one. */
    public function pdf(Request $request, JobDescription $jobDescription, LocalizedDateService $dates): HttpResponse
    {
        $this->authorize('view', $jobDescription);
        $locale = $request->string('locale')->toString() === 'am' ? 'am' : 'en';
        $jobDescription->load(['position.organization:id,code,name_en,name_am', 'position.organizationUnit:id,code,name_en,name_am', 'position.occupation:id,code,isco_code,name_en,name_am', 'profile:id,version_no', 'preparer:id,name', 'approver:id,name', 'publisher:id,name']);
        $view = view('organization-design.job-description-pdf', [
            'jd' => $jobDescription,
            'sections' => $jobDescription->sections()->get(),
            'locale' => $locale,
            'am' => $locale === 'am',
            'dates' => $dates,
            'effectiveFrom' => $dates->displayDate($jobDescription->effective_from, $locale),
            'effectiveTo' => $jobDescription->effective_to ? $dates->displayDate($jobDescription->effective_to, $locale) : null,
            'publishedAt' => $jobDescription->published_at ? $dates->displayDate($jobDescription->published_at, $locale) : null,
            'generatedAt' => $dates->displayDateTime(now(), $locale),
            'generatedBy' => $request->user()->name,
        ]);
        if ($request->boolean('preview')) {
            return response($view->render());
        }
        $filename = 'job-description-'.Str::slug((string) $jobDescription->reference_number).'-'.$locale.'.pdf';

        return Pdf::loadHTML($view->render())->setPaper('a4', 'portrait')->download($filename);
    }

    public function storeVersion(Request $request, JobDescription $jobDescription): RedirectResponse
    {
        $this->authorize('update', $jobDescription);
        $data = $request->validate(['effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from']]);
        $draft = $this->jobDescriptions->newVersion($request->user(), $jobDescription, $data);

        return to_route('organization-design.job-descriptions.show', $draft)->with('flash', ['message' => __('organization-design.jd_version_created'), 'type' => 'success']);
    }
}
