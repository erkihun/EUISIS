<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Intelligence;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\IntelligenceRecommendation;
use App\Models\User;
use App\Services\Intelligence\IntelligenceLabels;
use App\Services\Intelligence\IntelligencePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Persisted recommendations (brief §52–53) and the reviewer's verdict:
 * useful, not useful, accepted, rejected or an incorrect diagnosis. A verdict
 * never changes the recommendation text; it is recorded beside it and audited,
 * and it feeds the evaluation in Governance.
 */
final class RecommendationController extends Controller
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'review_status' => ['nullable', Rule::in(IntelligenceRecommendation::REVIEWS)],
            'risk' => ['nullable', Rule::in(IntelligenceRecommendation::RISKS)],
            'source_type' => ['nullable', Rule::in(IntelligenceRecommendation::SOURCES)],
        ]);
        $query = IntelligenceRecommendation::query()->with(['incident:id,reference,severity,status', 'reviewer:id,name'])->orderByDesc('generated_at');
        foreach (['review_status', 'risk', 'source_type'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        return Inertia::render('Intelligence/Recommendations/Index', [
            'labels' => IntelligenceLabels::dictionary(),
            'filters' => ['review_status' => $filters['review_status'] ?? '', 'risk' => $filters['risk'] ?? '', 'source_type' => $filters['source_type'] ?? ''],
            'options' => ['reviews' => IntelligenceRecommendation::REVIEWS, 'risks' => IntelligenceRecommendation::RISKS, 'sources' => IntelligenceRecommendation::SOURCES],
            'recommendations' => $query->paginate(25)->withQueryString()->through(fn (IntelligenceRecommendation $r): array => [
                'id' => $r->id, 'source_type' => $r->source_type, 'category' => $r->category, 'risk' => $r->risk, 'title' => $r->title, 'body' => $r->body,
                'generated_by' => $r->generated_by, 'confidence' => $r->confidence, 'generated_at' => $r->generated_at->toIso8601String(),
                'review_status' => $r->review_status, 'reviewed_at' => $r->reviewed_at?->toIso8601String(), 'reviewer' => $r->reviewer?->name, 'review_note' => $r->review_note,
                'params' => $r->evidence['params'] ?? [],
                'incident' => $r->incident === null ? null : ['reference' => $r->incident->reference, 'severity' => $r->incident->severity, 'status' => $r->incident->status, 'href' => route('intelligence.incidents.show', $r->incident->id)],
            ]),
            'can' => ['review' => $request->user()->can(IntelligencePermissions::RECOMMENDATIONS_REVIEW)],
        ]);
    }

    public function review(Request $request, IntelligenceRecommendation $recommendation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'review_status' => ['required', Rule::in(array_values(array_diff(IntelligenceRecommendation::REVIEWS, ['pending'])))],
            'note' => ['nullable', 'string', 'max:2000'],
            'linked_action_reference' => ['nullable', 'string', 'max:60'],
        ]);
        $before = $recommendation->only(['review_status', 'review_note']);

        $recommendation->forceFill([
            'review_status' => $data['review_status'],
            'review_note' => $data['note'] ?? null,
            'linked_action_reference' => $data['linked_action_reference'] ?? $recommendation->linked_action_reference,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit->execute(
            AuditEventType::IntelligenceRecommendationReviewed,
            $user,
            $recommendation,
            oldValues: $before,
            newValues: $recommendation->only(['review_status', 'review_note', 'linked_action_reference']),
            request: $request,
        );

        return back()->with('flash', ['message' => __('intelligence.flash.recommendation_reviewed'), 'type' => 'success']);
    }
}
