<?php
// app/Http/Controllers/PermissionController.php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;

use Modules\Court\Domain\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        $q       = trim((string) $request->get('q', ''));
        // Bounded: an unbounded perPage would let one request load the whole table.
        $perPage = max(5, min(100, (int) $request->input('perPage', 15)));

        $permissions = Permission::query()
            ->with(['roles:id,name'])   // to render role chips
            ->withCount('roles')        // ->roles_count
            ->select('court.permissions.*')
            // users_count = distinct users tied to any role that has this permission
            ->selectSub(function ($sub) {
                $sub->from('court_role_user')
                    ->selectRaw('COUNT(DISTINCT court_role_user.user_id)')
                    ->whereIn('court_role_user.role_id', function ($q) {
                        $q->from('court_permission_role')
                            ->select('court_permission_role.role_id')
                            ->whereColumn('court_permission_role.permission_id', 'court_permissions.id');
                    });
            }, 'users_count')
            ->when($q !== '', function ($query) use ($q) {
                $term = "%{$q}%";
                $query->where(function ($w) use ($term) {
                    $w->where('name', 'like', $term)
                        ->orWhere('label', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        // The register (React, Court/Permissions/Index).
        return Inertia::render('Court/Permissions/Index', [
            'permissions' => $permissions->through(fn (Permission $permission): array => [
                'id' => (int) $permission->id,
                'name' => (string) $permission->name,
                'label' => $permission->labelLocalized ?: null,
                'description' => $permission->descriptionLocalized ?: null,
                'roles' => $permission->roles->pluck('name')->values()->all(),
                'roles_count' => (int) $permission->roles_count,
                'users_count' => (int) $permission->users_count,
            ]),
            'filters' => ['q' => $q, 'perPage' => $perPage],
        ]);
    }

    public function create()
    {
        // The create form (React, Court/Permissions/Create): a label and description per locale.
        return Inertia::render('Court/Permissions/Create', [
            'permission' => null,
            ...$this->localeProps(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                     => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_.-]+$/i', 'unique:court_permissions,name'],
            'label'                    => ['nullable', 'string', 'max:255'],
            'description'              => ['nullable', 'string', 'max:255'],
            'label_translations'       => ['nullable', 'array'],
            'label_translations.*'     => ['nullable', 'string', 'max:255'],
            'description_translations' => ['nullable', 'array'],
            'description_translations.*' => ['nullable', 'string', 'max:255'],
        ]);

        $labelTranslations = $this->collectLocaleValues((array) Arr::get($data, 'label_translations', []));
        $descriptionTranslations = $this->collectLocaleValues((array) Arr::get($data, 'description_translations', []));

        Permission::create([
            'name'                    => $data['name'],
            'label'                   => $this->fallbackTranslation($labelTranslations),
            'description'             => $this->fallbackTranslation($descriptionTranslations),
            'label_translations'      => $labelTranslations ?: null,
            'description_translations'=> $descriptionTranslations ?: null,
        ]);

        return redirect()->route('court.permissions.index')->with('ok', __('court::permissions.flash.created'));
    }

    public function edit(Permission $permission)
    {
        // The edit form (React, Court/Permissions/Edit) with each locale's current text.
        return Inertia::render('Court/Permissions/Edit', [
            'permission' => [
                'id' => (int) $permission->id,
                'name' => (string) $permission->name,
                'label_translations' => $this->translationsByLocale($permission, 'label'),
                'description_translations' => $this->translationsByLocale($permission, 'description'),
            ],
            ...$this->localeProps(),
        ]);
    }

    /** The locales a permission is described in, and the one its plain label falls back to. */
    private function localeProps(): array
    {
        $locales = array_values((array) config('app.locales', [config('app.locale', 'en')]));

        return [
            'locales' => $locales,
            'primaryLocale' => (string) config('app.locale', $locales[0] ?? 'en'),
        ];
    }

    /**
     * One value per locale: the stored translation, or for the primary locale
     * the plain column when no translation was saved (what the form showed before).
     */
    private function translationsByLocale(Permission $permission, string $field): array
    {
        $translations = (array) ($permission->{"{$field}_translations"} ?? []);
        $primaryLocale = (string) config('app.locale', 'en');
        $values = [];

        foreach ($this->localeProps()['locales'] as $locale) {
            $values[$locale] = (string) ($translations[$locale]
                ?? ($locale === $primaryLocale ? ($permission->{$field} ?? '') : ''));
        }

        return $values;
    }

    public function update(Request $request, Permission $permission)
    {
        $data = $request->validate([
            'name'                     => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_.-]+$/i', Rule::unique('court_permissions', 'name')->ignore($permission->id)],
            'label'                    => ['nullable', 'string', 'max:255'],
            'description'              => ['nullable', 'string', 'max:255'],
            'label_translations'       => ['nullable', 'array'],
            'label_translations.*'     => ['nullable', 'string', 'max:255'],
            'description_translations' => ['nullable', 'array'],
            'description_translations.*' => ['nullable', 'string', 'max:255'],
        ]);

        $labelTranslations = $this->collectLocaleValues((array) Arr::get($data, 'label_translations', []));
        $descriptionTranslations = $this->collectLocaleValues((array) Arr::get($data, 'description_translations', []));

        $permission->update([
            'name'                    => $data['name'],
            'label'                   => $this->fallbackTranslation($labelTranslations),
            'description'             => $this->fallbackTranslation($descriptionTranslations),
            'label_translations'      => $labelTranslations ?: null,
            'description_translations'=> $descriptionTranslations ?: null,
        ]);

        return redirect()->route('court.permissions.index')->with('ok', __('court::permissions.flash.updated'));
    }

    public function destroy(Permission $permission)
    {
        $permission->roles()->detach();
        $permission->delete();

        return redirect()->route('court.permissions.index')->with('ok', __('court::permissions.flash.deleted'));
    }

    private function collectLocaleValues(array $values): array
    {
        $locales = config('app.locales', [config('app.locale', 'en')]);
        $filtered = [];

        foreach ($locales as $locale) {
            $raw = trim((string) ($values[$locale] ?? ''));
            if ($raw === '') {
                continue;
            }
            $filtered[$locale] = $raw;
        }

        return $filtered;
    }

    private function fallbackTranslation(array $translations): ?string
    {
        if (empty($translations)) {
            return null;
        }

        $primaryLocale = config('app.locale', 'en');

        if (isset($translations[$primaryLocale])) {
            return $translations[$primaryLocale];
        }

        return reset($translations);
    }
}
