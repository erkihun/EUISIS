<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Cafeteria\UpdateCafeteriaDayRuleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCafeteriaDayRuleRequest;
use App\Http\Resources\CafeteriaDayRuleResource;
use App\Models\CafeteriaDayRule;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaDayRuleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', CafeteriaDayRule::class);

        $rules = CafeteriaDayRule::query()
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->get();

        return Inertia::render('Cafeteria/DayRules/Index', [
            'rules' => CafeteriaDayRuleResource::collection($rules)->resolve(),
            'can'   => [
                'update' => request()->user()?->can('cafeteria_day_rules.update') ?? false,
            ],
        ]);
    }

    public function update(UpdateCafeteriaDayRuleRequest $request, CafeteriaDayRule $cafeteriaDayRule, UpdateCafeteriaDayRuleAction $action): RedirectResponse
    {
        $this->authorize('update', $cafeteriaDayRule);

        $action->execute($cafeteriaDayRule, $request->validated(), $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria.dayRuleUpdated'), 'type' => 'success']);
    }
}
