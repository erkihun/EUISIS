<?php

declare(strict_types=1);

namespace Modules\PublicComplaints\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Feedback\Contracts\PublicCaseDashboard;
use Modules\PublicComplaints\Domain\Models\PublicComplaint;

/** The Public complaints module's dashboard (owner request 2026-11-08, ADR 0032). */
final class ComplaintDashboardController extends Controller
{
    private const STATUS_ORDER = ['pending', 'unrouted', 'assigned', 'under_review', 'resolved', 'reviewed', 'closed'];

    public function __construct(private readonly PublicCaseDashboard $cases) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['service_feedback.complaints.view']), 403);
        $query = fn () => $this->cases->scope(PublicComplaint::query(), $user);

        return Inertia::render('ServiceFeedback/SubmissionDashboard', [
            'type' => 'complaint',
            ...$this->cases->build($query, $user, self::STATUS_ORDER),
            'byTipType' => null,
            'violations' => null,
            'canManageRouting' => $user->hasAnyPermission(['service_feedback.manage_routing']),
        ]);
    }
}
