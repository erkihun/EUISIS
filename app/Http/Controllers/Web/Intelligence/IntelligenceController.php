<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\IntelligenceSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Intelligence launcher and the generic area page. Thin by design: the
 * route gates `intelligence.view`, the area enum gates its own grant, and the
 * analysis classes do the work behind Inertia deferred props.
 */
final class IntelligenceController extends Controller
{
    public function __construct(private readonly IntelligenceSettings $settings) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Intelligence/Index', $this->frame($user));
    }

    public function area(Request $request, string $area): Response
    {
        $area = IntelligenceArea::from($area);
        /** @var User $user */
        $user = $request->user();
        abort_unless($area->visibleTo($user), 403);

        return Inertia::render('Intelligence/Area', [
            ...$this->frame($user),
            'area' => $area->toArray(),
        ]);
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
                'settings' => $user->can(IntelligencePermissions::SETTINGS),
            ],
        ];
    }
}
