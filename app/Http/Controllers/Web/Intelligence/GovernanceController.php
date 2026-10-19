<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\AssistantMessage;
use App\Models\IntelligenceAiRun;
use App\Models\IntelligenceAlert;
use App\Models\IntelligenceIncident;
use App\Models\IntelligenceRecommendation;
use App\Models\IntelligenceSignal;
use App\Models\User;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\AI\PromptRegistry;
use App\Services\Intelligence\Forecasting\ForecastSnapshotService;
use App\Services\Intelligence\IntelligenceLabels;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\IntelligenceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI & Data Governance (brief §52–56): what the gateway is (never its
 * secrets), which features are on, the prompt catalog with versions and the
 * data classes each may send, the sources the module reads, every AI run,
 * how reviewers rated recommendations, forecast quality and retention. Read
 * only; switches live in System Settings › Intelligence.
 */
final class GovernanceController extends Controller
{
    public function __construct(
        private readonly LLMGateway $gateway,
        private readonly IntelligenceSettings $settings,
        private readonly ForecastSnapshotService $forecasts,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $status = $this->gateway->status();

        return Inertia::render('Intelligence/Governance/Index', [
            'labels' => IntelligenceLabels::dictionary(),
            'gateway' => [...$status, 'available' => $this->gateway->available()],
            'features' => [
                'ai_enabled' => $this->settings->aiEnabled(), 'knowledge_enabled' => $this->settings->knowledgeEnabled(), 'forecasting_enabled' => $this->settings->forecastingEnabled(),
                'runbook_execution_enabled' => $this->settings->runbookExecutionEnabled(), 'telemetry_enabled' => $this->settings->telemetryEnabled(),
                'retention_days' => $this->settings->retentionDays(), 'baseline_window_days' => $this->settings->baselineWindowDays(), 'anomaly_sigma' => $this->settings->anomalySigma(),
                'notify_min_severity' => $this->settings->notifyMinSeverity(), 'ai_max_input_chars' => $this->settings->aiMaxInputChars(), 'ai_timeout_seconds' => $this->settings->aiTimeoutSeconds(),
            ],
            'prompts' => PromptRegistry::catalog(),
            'data_classes' => IntelligenceSettings::DATA_CLASSES,
            'runs' => IntelligenceAiRun::query()->with('user:id,name')->orderByDesc('created_at')->paginate(20)->withQueryString()->through(fn (IntelligenceAiRun $r): array => [
                'id' => $r->id, 'feature' => $r->feature, 'prompt_key' => $r->prompt_key, 'prompt_version' => $r->prompt_version, 'provider' => $r->provider, 'model' => $r->model,
                'status' => $r->status, 'user' => $r->user?->name, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id,
                'input_chars' => $r->input_chars, 'output_tokens' => $r->output_tokens, 'duration_ms' => $r->duration_ms, 'error' => $r->error, 'created_at' => $r->created_at->toIso8601String(),
            ]),
            'run_counts' => $this->runCounts(),
            'reviews' => $this->reviews(),
            'forecast_quality' => $this->settings->forecastingEnabled() || Schema::hasTable('intelligence_forecasts') ? $this->forecasts->quality() : [],
            'inventory' => $this->inventory(),
            'assistant' => $this->assistantStats(),
            'can' => ['manage' => $user->can(IntelligencePermissions::GOVERNANCE_MANAGE), 'manage_settings' => $user->can(IntelligencePermissions::SETTINGS)],
        ]);
    }

    /** @return array<string, int> */
    private function runCounts(): array
    {
        $rows = IntelligenceAiRun::query()->where('created_at', '>=', now()->subDays(30))->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return ['total' => (int) $rows->sum(), 'ok' => (int) ($rows['ok'] ?? 0), 'failed' => (int) ($rows['failed'] ?? 0), 'invalid' => (int) ($rows['invalid'] ?? 0), 'refused' => (int) ($rows['refused'] ?? 0), 'unavailable' => (int) ($rows['unavailable'] ?? 0)];
    }

    /** Reviewer verdicts per generator: the feedback loop the brief asks for (§51). @return array<string, array<string, int>> */
    private function reviews(): array
    {
        $out = ['rule' => array_fill_keys(IntelligenceRecommendation::REVIEWS, 0), 'ai' => array_fill_keys(IntelligenceRecommendation::REVIEWS, 0)];
        foreach (IntelligenceRecommendation::query()->selectRaw('generated_by, review_status, count(*) as c')->groupBy('generated_by', 'review_status')->get() as $row) {
            if (isset($out[$row->generated_by][$row->review_status])) {
                $out[$row->generated_by][$row->review_status] = (int) $row->c;
            }
        }

        return $out;
    }

    /** @return array<string, int|string|null> */
    /**
     * Assistant operations over the last 30 days from message columns only — never message text
     * (docs/assistant/architecture.md, A-11).
     *
     * @return array<string, int|float|null|array<string, int>>
     */
    private function assistantStats(): array
    {
        $since = now()->subDays(30);
        $replies = AssistantMessage::query()->where('role', 'assistant')->where('created_at', '>=', $since);
        $byStatus = (clone $replies)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $byMode = (clone $replies)->selectRaw('mode, count(*) as c')->groupBy('mode')->pluck('c', 'mode');
        $feedback = (clone $replies)->whereNotNull('feedback')->selectRaw('feedback, count(*) as c')->groupBy('feedback')->pluck('c', 'feedback');
        $total = (int) $byStatus->sum();
        $aiAverage = (clone $replies)->where('ai_used', true)->avg('duration_ms');

        return [
            'requests' => $total,
            'ai_used' => (int) (clone $replies)->where('ai_used', true)->count(),
            'fallback_rate' => $total > 0 ? round(100 * (int) ($byStatus['fallback'] ?? 0) / $total, 1) : null,
            'denied' => (int) ($byStatus['denied'] ?? 0),
            'errors' => (int) ($byStatus['error'] ?? 0),
            'avg_ai_ms' => $aiAverage === null ? null : (int) round((float) $aiAverage),
            'avg_ms' => $total > 0 ? (int) round((float) (clone $replies)->avg('duration_ms')) : null,
            'modes' => array_map('intval', $byMode->all()),
            'helpful' => (int) ($feedback['helpful'] ?? 0),
            'not_helpful' => (int) ($feedback['not_helpful'] ?? 0),
            'tickets' => Schema::hasTable('support_tickets') ? (int) \DB::table('support_tickets')->where('created_at', '>=', $since)->whereNotNull('conversation_id')->count() : 0,
        ];
    }

    private function inventory(): array
    {
        $knowledge = Schema::hasTable('intelligence_knowledge_documents') ? (int) \DB::table('intelligence_knowledge_documents')->count() : null;
        $questions = Schema::hasTable('intelligence_knowledge_questions') ? (int) \DB::table('intelligence_knowledge_questions')->count() : null;
        $latestSignal = IntelligenceSignal::query()->max('bucket_start');

        return [
            'signals' => (int) IntelligenceSignal::query()->count(),
            'latest_signal_at' => $latestSignal !== null ? CarbonImmutable::parse($latestSignal)->toIso8601String() : null,
            'incidents_active' => (int) IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->count(),
            'alerts_active' => (int) IntelligenceAlert::query()->whereIn('status', IntelligenceAlert::ACTIVE_STATUSES)->count(),
            'recommendations_pending' => (int) IntelligenceRecommendation::query()->where('review_status', 'pending')->count(),
            'ai_runs' => (int) IntelligenceAiRun::query()->count(),
            'knowledge_documents' => $knowledge,
            'knowledge_questions' => $questions,
        ];
    }
}
