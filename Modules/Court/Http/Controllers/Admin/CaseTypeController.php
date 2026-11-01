<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CaseTypeController extends Controller
{
    /**
     * List case types (+ usage count) with optional search.
     */
    public function index(Request $request)
    {
        $this->authorizeTypes();

        $q = trim($request->string('q')->toString());
        $hasPrefix = Schema::hasColumn('court_case_types', 'prefix');

        $query = DB::table('court_case_types as ct')
            ->when($q !== '', fn($w) => $w->where('ct.name', 'like', "%{$q}%"))
            ->leftJoin('court_cases as c', 'c.case_type_id', '=', 'ct.id')
            ->groupBy('ct.id', 'ct.name')
            ->select('ct.id', 'ct.name', DB::raw('COUNT(c.id) as cases_count'))
            ->orderBy('ct.name');

        if ($hasPrefix) {
            $query->addSelect('ct.prefix')
                ->groupBy('ct.prefix');
        }

        $types = $query->paginate(12)->withQueryString();

        // The register (React, Court/CaseTypes/Index).
        return Inertia::render('Court/CaseTypes/Index', [
            'types' => $types->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'prefix' => $hasPrefix ? ($row->prefix ?? null) : null,
                'cases_count' => (int) $row->cases_count,
            ]),
            'filters' => ['q' => $q],
        ]);
    }

    /**
     * Show create form.
     */
    public function create()
    {
        $this->authorizeTypes();

        return Inertia::render('Court/CaseTypes/Form', ['type' => null, 'hasPrefix' => Schema::hasColumn('court_case_types', 'prefix')]);
    }

    /**
     * Store a new case type.
     */
    public function store(Request $request)
    {
        $this->authorizeTypes();

        $hasPrefix = Schema::hasColumn('court_case_types', 'prefix');
        $data = $request->validate(array_filter([
            'name' => ['required', 'string', 'max:100', Rule::unique('court_case_types', 'name')],
            'prefix' => $hasPrefix ? ['nullable', 'string', 'max:16'] : null,
        ]));

        // Build insert payload
        $row = [
            'name' => $data['name'],
        ];
        if ($hasPrefix) {
            $row['prefix'] = $data['prefix'] ?? null;
        }

        if (Schema::hasColumn('court_case_types', 'created_at')) $row['created_at'] = now();
        if (Schema::hasColumn('court_case_types', 'updated_at')) $row['updated_at'] = now();

        DB::table('court_case_types')->insert($row);

        return redirect()->route('court.case-types.index')->with('success', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.case_type')],
        ]);
    }

    /**
     * Show edit form.
     */
    public function edit(int $id)
    {
        $this->authorizeTypes();

        $type = DB::table('court_case_types')->where('id', $id)->first();
        abort_if(!$type, 404);
        $hasPrefix = Schema::hasColumn('court_case_types', 'prefix');

        return Inertia::render('Court/CaseTypes/Form', [
            'type' => ['id' => (int) $type->id, 'name' => (string) $type->name, 'prefix' => $hasPrefix ? ($type->prefix ?? null) : null],
            'hasPrefix' => $hasPrefix,
        ]);
    }

    /**
     * Update case type.
     */
    public function update(Request $request, int $id)
    {
        $this->authorizeTypes();

        $type = DB::table('court_case_types')->where('id', $id)->first();
        abort_if(!$type, 404);

        $hasPrefix = Schema::hasColumn('court_case_types', 'prefix');
        $data = $request->validate(array_filter([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('court_case_types', 'name')->ignore($id),
            ],
            'prefix' => $hasPrefix ? ['nullable', 'string', 'max:16'] : null,
        ]));

        // Build update payload
        $row = [
            'name' => $data['name'],
        ];
        if ($hasPrefix) {
            $row['prefix'] = $data['prefix'] ?? null;
        }
        if (Schema::hasColumn('court_case_types', 'updated_at')) $row['updated_at'] = now();

        DB::table('court_case_types')->where('id', $id)->update($row);

        return redirect()->route('court.case-types.index')->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.case_type')],
        ]);
    }

    /**
     * Delete case type (blocked if in use).
     */
    public function destroy(int $id)
    {
        $this->authorizeTypes();

        $type = DB::table('court_case_types')->where('id', $id)->first();
        abort_if(!$type, 404);

        $inUse = DB::table('court_cases')->where('case_type_id', $id)->count();
        if ($inUse > 0) {
            return back()->with('error', [
                'key' => 'court::messages.error.delete_case_type_in_use',
                'replace' => ['count' => $inUse],
            ]);
        }

        DB::table('court_case_types')->where('id', $id)->delete();

        return back()->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.case_type')],
        ]);
    }

    /**
     * Permission gate for managing case types.
     * Reuse your helper style from other controllers.
     */
    private function authorizeTypes(): void
    {
        if (!\Modules\Court\Application\Support\CourtPermissions::check('cases.types')) {
            abort(403, 'You do not have permission: cases.types');
        }
    }
}
