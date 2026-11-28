<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\WorkExperience\Application\Services\WorkExperienceAccess;
use Modules\WorkExperience\Application\Services\WorkExperienceOffice;
use Modules\WorkExperience\Domain\Models\WorkExperienceOfficeSetting;

/**
 * Employee management > Work experience settings: for each institution, who
 * signs the certificate and what the certificate carries.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly WorkExperienceAccess $access,
        private readonly WorkExperienceOffice $office,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('work_experience.settings.manage'), 403);

        $search = is_string($request->query('search')) ? trim((string) $request->query('search')) : '';
        $allowed = $this->access->organizationsInScope($user);
        $organizations = Organization::query()
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('id', $allowed))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('name_am', 'like', $like)->orWhere('name_en', 'like', $like));
            })
            ->orderBy('name_am')->orderBy('id')
            ->paginate(25)->withQueryString();
        $settings = WorkExperienceOfficeSetting::query()->whereIn('organization_id', $organizations->getCollection()->pluck('id'))->get()->keyBy('organization_id');

        return Inertia::render('modules/work-experience/Settings', [
            'experienceOffices' => $organizations->through(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                ...$this->office->present($settings->get($organization->id)),
            ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('work_experience.settings.manage') && $this->access->inScope($user, (string) $organization->id), 403);

        $data = $request->validate([
            // A certificate is signed under a name and a title: one language at least.
            'signatory_name_am' => ['nullable', 'required_without:signatory_name_en', 'string', 'max:255'],
            'signatory_name_en' => ['nullable', 'required_without:signatory_name_am', 'string', 'max:255'],
            'signatory_title_am' => ['nullable', 'required_without:signatory_title_en', 'string', 'max:255'],
            'signatory_title_en' => ['nullable', 'required_without:signatory_title_am', 'string', 'max:255'],
            'include_salary' => ['required', 'boolean'],
            'include_external' => ['required', 'boolean'],
        ], [], [
            'signatory_name_am' => __('work_experience::messages.fields.signatory_name_am'),
            'signatory_name_en' => __('work_experience::messages.fields.signatory_name_en'),
            'signatory_title_am' => __('work_experience::messages.fields.signatory_title_am'),
            'signatory_title_en' => __('work_experience::messages.fields.signatory_title_en'),
        ]);

        $this->office->save($user, (string) $organization->id, $data);

        return back()->with('success', __('work_experience::messages.flash.settings_saved'));
    }
}
