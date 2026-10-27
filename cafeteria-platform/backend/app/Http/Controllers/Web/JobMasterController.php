<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageJobMasters;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Core/JobMasters (ADR 0010): one tab per master (grades, levels,
 * occupations, professions, employment types, position categories), how many
 * positions use each value, and — for a holder of positions.master.manage —
 * adding, editing and deactivating values through ManageJobMasters.
 */
class JobMasterController extends Controller
{
    public function index(Request $request): Response
    {
        $kind = $request->validate(['kind' => ['sometimes', Rule::in(array_keys(ManageJobMasters::KINDS))]])['kind'] ?? 'grades';
        [$class, $column] = ManageJobMasters::KINDS[$kind];
        $rows = $class::query()->withCount('positions')->orderBy('sort_order')->orderBy('code')->get();

        return Inertia::render('Core/JobMasters', [
            'kind' => $kind,
            'kinds' => array_keys(ManageJobMasters::KINDS),
            'values' => $rows->map(fn (Model $value): array => [
                'id' => (string) $value->getKey(),
                'code' => $value->code,
                'name_en' => $value->name_en,
                'name_am' => $value->name_am,
                'description' => $value->description,
                'sort_order' => (int) $value->sort_order,
                'is_active' => (bool) $value->is_active,
                'needs_review' => (bool) $value->needs_review,
                'positions_count' => (int) $value->positions_count,
                'update_url' => route('core.job-masters.update', [$kind, $value->getKey()]),
            ])->values(),
            'can_manage' => $request->user()->can('positions.master.manage'),
            'store_url' => route('core.job-masters.store', $kind),
            'url' => route('core.job-masters'),
        ]);
    }

    public function store(Request $request, string $kind, ManageJobMasters $masters): RedirectResponse
    {
        $masters->save($kind, null, $request->all(), $request->user());

        return back()->with('flash', ['message' => __('positions.job_master_saved'), 'type' => 'success']);
    }

    public function update(Request $request, string $kind, string $id, ManageJobMasters $masters): RedirectResponse
    {
        $masters->save($kind, $id, $request->all(), $request->user());

        return back()->with('flash', ['message' => __('positions.job_master_saved'), 'type' => 'success']);
    }
}
