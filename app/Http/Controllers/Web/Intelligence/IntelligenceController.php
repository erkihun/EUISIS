<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligenceLabels;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\IntelligenceService;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\SituationRoom\SituationContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Intelligence launcher and the generic area page. Thin by design: the
 * route gates `intelligence.view`, the area enum gates its own grant, the
 * service resolves the scope (403 for a focus outside it) and computes, and
 * the heavy payload is an Inertia deferred prop so the frame paints first.
 */
final class IntelligenceController extends Controller
{
    public function __construct(
        private readonly IntelligenceService $intelligence,
        private readonly IntelligenceSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Intelligence/Index', $this->frame($user));
    }

    /** The System Health hub under its own parameter-less route, for the sidebar. */
    public function systemHealth(Request $request): Response
    {
        return $this->area($request, IntelligenceArea::SystemHealth->value);
    }

    public function area(Request $request, string $area): Response
    {
        $area = IntelligenceArea::from($area);
        /** @var User $user */
        $user = $request->user();
        abort_unless($area->visibleTo($user), 403);

        $props = [...$this->frame($user), 'area' => $area->toArray()];

        if ($this->intelligence->analysisFor($area) !== null) {
            $context = $this->intelligence->context($user, $request);
            $props = [
                ...$props,
                ...$this->analysisFrame($context),
                'result' => Inertia::defer(fn (): array => $this->intelligence->area($area, $context)),
            ];
        }

        return Inertia::render('Intelligence/Area', $props);
    }

    /**
     * What every Intelligence page shows around its content.
     *
     * @return array<string, mixed>
     */
    private function frame(User $user): array
    {
        return [
            'areas' => array_map(fn (IntelligenceArea $a): array => $a->toArray(), IntelligenceArea::visibleFor($user)),
            'sections' => IntelligenceArea::sections(),
            'ai' => [
                'enabled' => $this->settings->aiEnabled(),
                'provider' => $this->settings->aiProvider(),
            ],
            'can' => [
                'governance' => $user->can(IntelligencePermissions::GOVERNANCE),
                'manage_settings' => $user->can(IntelligencePermissions::SETTINGS),
            ],
        ];
    }

    /**
     * The scope trail, filters and dictionary an analysed area needs.
     *
     * @return array<string, mixed>
     */
    private function analysisFrame(SituationContext $context): array
    {
        return [
            'labels' => IntelligenceLabels::dictionary(),
            'filters' => $context->filters->toArray(),
            'query' => $context->filters->query(),
            'scope' => $this->intelligence->scopeFrame($context),
            'options' => $this->intelligence->options($context),
        ];
    }
}
