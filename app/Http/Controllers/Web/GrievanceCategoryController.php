<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GrievanceCategoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage', Grievance::class);

        return Inertia::render('GrievanceCategories/Index', [
            'categories' => GrievanceCategory::query()->orderBy('name_en')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', Grievance::class);

        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string'],
            'description_am' => ['nullable', 'string'],
        ]);

        $data['code'] = Str::slug($data['name_en']).'-'.Str::random(4);

        GrievanceCategory::query()->create($data);

        return back()->with('flash', ['message' => __('grievances.categoryCreated'), 'type' => 'success']);
    }

    public function update(Request $request, GrievanceCategory $grievanceCategory): RedirectResponse
    {
        $this->authorize('manage', Grievance::class);

        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string'],
            'description_am' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $grievanceCategory->update($data);

        return back()->with('flash', ['message' => __('grievances.categoryUpdated'), 'type' => 'success']);
    }
}
