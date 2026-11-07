<?php

declare(strict_types=1);

namespace Modules\TipOffs\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Feedback\Contracts\Data\PublicTipType;
use Modules\Feedback\Contracts\PublicCaseDashboard;
use Modules\TipOffs\Application\Services\PublicTipForm;
use Modules\TipOffs\Domain\Models\TipOff;

/** The Tip-offs module's dashboard (owner request 2026-11-08, ADR 0032). */
final class TipOffDashboardController extends Controller
{
    private const STATUS_ORDER = ['pending', 'unrouted', 'assigned', 'under_review', 'accepted', 'not_adopted', 'reviewed', 'resolved', 'closed'];

    public function __construct(private readonly PublicCaseDashboard $cases) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['service_feedback.view']), 403);
        $query = fn () => $this->cases->scope(TipOff::query(), $user);

        // Tip-off type: the two kinds, and rows from before types existed in their own bucket.
        $byType = $query()->selectRaw("COALESCE(tip_type, '".PublicTipType::UNCLASSIFIED_LEGACY."') AS bucket, COUNT(*) AS total")->groupBy('bucket')->pluck('total', 'bucket');
        $byTipType = ['total' => (int) $byType->sum()];
        foreach ([...PublicTipType::values(), PublicTipType::UNCLASSIFIED_LEGACY] as $bucket) {
            $byTipType[$bucket] = (int) ($byType[$bucket] ?? 0);
        }

        // Violations alleged on the Tip-off form over the last six months (decrypted, so counted here).
        $violations = array_fill_keys(PublicTipForm::VIOLATIONS, 0);
        foreach ($query()->where('created_at', '>=', now()->subMonths(5)->startOfMonth())->whereNotNull('tip_details')->get(['id', 'tip_details']) as $row) {
            foreach ((array) ($row->tip_details['violation_types'] ?? []) as $violation) {
                if (array_key_exists($violation, $violations)) {
                    $violations[$violation]++;
                }
            }
        }

        return Inertia::render('ServiceFeedback/SubmissionDashboard', [
            'type' => 'suggestion',
            ...$this->cases->build($query, $user, self::STATUS_ORDER),
            'byTipType' => $byTipType,
            'violations' => $violations,
            'canManageRouting' => $user->hasAnyPermission(['service_feedback.manage_routing']),
        ]);
    }
}
