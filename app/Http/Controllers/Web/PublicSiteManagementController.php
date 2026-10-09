<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicSite\UpdatePublicSiteSettingsRequest;
use App\Models\PublicPageSection;
use App\Services\PublicSite\PublicSiteContent;
use App\Services\PublicSite\PublicSiteEditor;
use App\Services\PublicSite\PublicSiteSectionRegistry;
use App\Services\PublicSite\PublicUrlPolicy;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PublicSiteManagementController extends Controller
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    private function access(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can('public_site.view') && $request->user()->can($permission), 403);
    }

    public function index(Request $request, SystemSettingsService $settings): Response
    {
        $this->access($request, 'public_site.view');
        $tabs = [];
        foreach ([...PublicSiteEditor::PAGES, 'faqs' => 'public_support', 'settings' => 'public_site_settings'] as $tab => $permission) {
            if ($request->user()->can($permission.'.view')) {
                $tabs[] = $tab;
            }
        }
        $tab = $request->string('tab')->toString() ?: ($tabs[0] ?? '');
        abort_unless(in_array($tab, $tabs, true) || ($tab === '' && $tabs === []), 403);
        $props = ['tabs' => $tabs, 'tab' => $tab, 'sections' => [], 'fields' => [], 'records' => null,
            'settingsFields' => [], 'can' => [], 'search' => '', 'publicRoutes' => PublicUrlPolicy::ROUTES];
        if (isset(PublicSiteEditor::PAGES[$tab])) {
            $rows = PublicPageSection::where('page', $tab)->get()->keyBy('section_key');
            foreach (PublicSiteSectionRegistry::definitions()[$tab] as $key => $definition) {
                $props['sections'][] = ['key' => $key, 'definition' => $definition, 'values' => $rows->get($key)?->only([
                    'title_en', 'title_am', 'subtitle_en', 'subtitle_am', 'body_en', 'body_am', 'is_visible', 'sort_order', 'options',
                ]) ?? ['is_visible' => true, 'sort_order' => 0, 'options' => []]];
            }
            $props['can']['updatePage'] = $request->user()->can(PublicSiteEditor::PAGES[$tab].'.update');
        }
        if (in_array($tab, ['announcements', 'services', 'faqs'], true)) {
            [$model, $permission, $title] = PublicSiteEditor::collection($tab);
            $search = mb_substr(trim($request->string('search')->toString()), 0, 160);
            $props['search'] = $search;
            $props['fields'] = PublicSiteEditor::fields($tab);
            $columns = array_column($props['fields'], 'key');
            $props['records'] = $model::query()
                // ci_like_operator() rather than a bare LIKE: every other search
                // in the app is case-insensitive on PostgreSQL too.
                ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where($title.'_en', ci_like_operator(), "%{$search}%")->orWhere($title.'_am', ci_like_operator(), "%{$search}%")))
                ->orderByDesc('updated_at')->orderBy('id')->paginate(15, ['id', ...$columns, ...($tab === 'faqs' ? [] : ['status', 'published_at', ...($tab === 'announcements' ? ['expires_at'] : [])])])->withQueryString();
            foreach (['create', 'update', 'publish', 'archive'] as $action) {
                $props['can'][$action] = $request->user()->can($permission.'.'.($tab === 'faqs' ? 'update' : $action));
            }
        }
        if ($tab === 'settings') {
            $props['settingsFields'] = $settings->getGroupForAdmin('public_site');
            $props['can']['update'] = $request->user()->can('public_site_settings.update');
        }

        return Inertia::render('PublicSiteManagement/Index', $props);
    }

    public function section(Request $request, string $page, string $section): RedirectResponse
    {
        abort_unless(isset(PublicSiteEditor::PAGES[$page]) && PublicSiteSectionRegistry::has($page, $section), 404);
        $this->access($request, PublicSiteEditor::PAGES[$page].'.update');
        $definition = PublicSiteSectionRegistry::definition($page, $section);
        $rules = [];
        foreach ($definition['fields'] as $field) {
            foreach (['en', 'am'] as $locale) {
                $rules[$field.'_'.$locale] = ['nullable', 'string', 'max:'.match ($field) {
                    'title' => 255, 'subtitle' => 500, default => 20000
                }];
            }
        }
        $rules['is_visible'] = ['required', 'boolean', ...($definition['hideable'] ? [] : [Rule::in([true, 1, '1'])])];
        if ($definition['sortable']) {
            $rules['sort_order'] = ['required', 'integer', 'min:0', 'max:10000'];
        }
        $optionKeys = array_keys($definition['options']);
        $rules['options'] = $optionKeys === [] ? ['prohibited'] : ['required', 'array:'.implode(',', $optionKeys)];
        foreach ($definition['options'] as $key => $optionRules) {
            $rules['options.'.$key] = $optionRules;
            if (str_ends_with($key, '_route')) {
                $rules['options.'.$key][] = Rule::in(array_keys(PublicUrlPolicy::ROUTES));
            }
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $page, $section, $data): void {
            $row = PublicPageSection::updateOrCreate(['page' => $page, 'section_key' => $section], [...$data, 'updated_by' => $request->user()->id]);
            $this->audit->execute(AuditEventType::PublicPageSectionUpdated, $request->user(), $row, newValues: ['fields' => array_keys($data)]);
        });
        PublicSiteContent::flush();

        return back();
    }

    public function save(Request $request, string $kind, ?string $id = null): RedirectResponse
    {
        [$model, $permission] = PublicSiteEditor::collection($kind);
        $this->access($request, $permission.'.'.($kind === 'faqs' ? 'update' : ($id ? 'update' : 'create')));
        $data = $request->validate(PublicSiteEditor::rules($kind, $id));
        DB::transaction(function () use ($request, $kind, $id, $model, $permission, $data): void {
            $row = $id ? $model::lockForUpdate()->findOrFail($id) : new $model;
            // Editing live/scheduled content changes the public site just as publishing does.
            if ($kind !== 'faqs' && $id && in_array($row->status->value, ['published', 'scheduled'], true)) {
                $this->access($request, $permission.'.publish');
            }
            $row->fill($data);
            $row->updated_by = $request->user()->id;
            if (! $id && $kind !== 'faqs') {
                $row->created_by = $request->user()->id;
                $row->status = 'draft';
            }
            $row->save();
            $event = match ($kind) {
                'announcements' => $id ? AuditEventType::PublicAnnouncementUpdated : AuditEventType::PublicAnnouncementCreated,
                'services' => $id ? AuditEventType::PublicServiceUpdated : AuditEventType::PublicServiceCreated,
                default => AuditEventType::PublicFaqSaved,
            };
            $this->audit->execute($event, $request->user(), $row, newValues: ['fields' => array_keys($data)]);
        });
        PublicSiteContent::flush();

        return back();
    }

    public function transition(Request $request, string $kind, string $id): RedirectResponse
    {
        abort_unless(in_array($kind, ['announcements', 'services'], true), 404);
        [$model, $permission] = PublicSiteEditor::collection($kind);
        $this->access($request, $permission.'.view');
        $data = $request->validate(['action' => ['required', Rule::in(['publish', 'unpublish', 'archive'])]]);
        $action = $data['action'];
        $this->access($request, $permission.'.'.($action === 'archive' ? 'archive' : 'publish'));
        DB::transaction(function () use ($request, $model, $id, $kind, $action): void {
            $row = $model::lockForUpdate()->findOrFail($id);
            $row->status = match ($action) {
                'publish' => 'published', 'archive' => 'archived', default => 'draft'
            };
            if ($action === 'publish') {
                $row->published_at = now();
                $row->published_by = $request->user()->id;
                // Explicit "publish now" starts a fresh visibility window.
                if ($kind === 'announcements') {
                    $row->expires_at = null;
                }
            }
            $row->updated_by = $request->user()->id;
            $row->save();
            $event = $kind === 'announcements'
                ? match ($action) {
                    'publish' => AuditEventType::PublicAnnouncementPublished, 'archive' => AuditEventType::PublicAnnouncementArchived, default => AuditEventType::PublicAnnouncementUnpublished
                }
            : match ($action) {
                'publish' => AuditEventType::PublicServicePublished, 'archive' => AuditEventType::PublicServiceArchived, default => AuditEventType::PublicServiceHidden
            };
            $this->audit->execute($event, $request->user(), $row, newValues: ['status' => $row->status->value]);
        });
        PublicSiteContent::flush();

        return back();
    }

    public function settings(UpdatePublicSiteSettingsRequest $request, UpdateSystemSettingsGroupAction $action): RedirectResponse
    {
        $this->access($request, 'public_site_settings.update');
        $action->execute('public_site', $request->validated(), $request->user());
        PublicSiteContent::flush();

        return back();
    }
}
