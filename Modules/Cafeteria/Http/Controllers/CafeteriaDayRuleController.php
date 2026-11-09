<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cafeteria\Application\Actions\UpdateCafeteriaDayRuleAction;
use Modules\Cafeteria\Domain\Models\CafeteriaDayRule;
use Modules\Cafeteria\Http\Requests\UpdateCafeteriaDayRuleRequest;
use Modules\Cafeteria\Http\Resources\CafeteriaDayRuleResource;

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
            'can' => [
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
