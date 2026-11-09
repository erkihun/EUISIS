<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\Intelligence\IntelligenceArea;

/**
 * Deterministic intent routing (docs/assistant/context-and-tools.md). A
 * quick-action key wins; otherwise keyword sets in English and Amharic pick
 * a mode. The model never routes: it only phrases what the tools returned.
 * Nothing here looks at capabilities — the service degrades a mode the
 * context does not allow, with a notice, so that a denied intent is visible
 * rather than silently answered from a different scope.
 */
final class AssistantIntentRouter
{
    public const HELP = 'HELP';

    public const KNOWLEDGE = 'KNOWLEDGE';

    public const BUSINESS_ANALYSIS = 'BUSINESS_ANALYSIS';

    public const SYSTEM_DIAGNOSTICS = 'SYSTEM_DIAGNOSTICS';

    public const SUPPORT = 'SUPPORT';

    /** @var array<string, string> quick action → mode */
    private const ACTIONS = [
        'explain_page' => self::HELP, 'how_to' => self::HELP, 'find' => self::KNOWLEDGE, 'ask_module' => self::KNOWLEDGE,
        'report_problem' => self::SUPPORT, 'system_status' => self::SYSTEM_DIAGNOSTICS, 'create_ticket' => self::SUPPORT,
    ];

    private const STATUS_TERMS = ['system status', 'system health', 'is the system', 'server status', 'queue', 'database health', 'failed jobs', 'incident', 'outage', 'telemetry', 'health check', 'ሥርዓት ሁኔታ', 'የሥርዓት ጤንነት', 'ክስተት', 'ወረፋ', 'የመረጃ ቋት'];

    private const SUPPORT_TERMS = ['not working', "doesn't work", 'does not work', "isn't working", "can't", 'cannot', 'unable', 'error', 'failed', 'fails', 'broken', 'stuck', 'crash', 'bug', 'problem', 'err-', 'something went wrong', 'አይሰራም', 'አይሠራም', 'ስህተት', 'ችግር', 'አልተሳካም', 'አልቻልኩም'];

    private const ANALYTICS_TERMS = ['how many', 'count of', 'number of', 'vacant', 'vacanc', 'complaint', 'feedback trend', 'staffing', 'headcount', 'which organizations', 'which units', 'what changed', 'requires attention', 'needs attention', 'trend', 'growth', 'performance completion', 'agreement coverage', 'overdue reviews', 'transfers', 'tip-off', 'tip off', 'data quality', 'ስንት', 'ክፍት የሥራ መደብ', 'ቅሬታ', 'የሰው ኃይል', 'ትኩረት', 'ምን ተለወጠ', 'አዝማሚያ', 'ዝውውር', 'አፈጻጸም'];

    private const HELP_TERMS = ['how do i', 'how to', 'how can i', 'where do i', 'where is', 'where can i', 'explain', 'what is this', 'what does', 'what is the', 'steps', 'guide', 'እንዴት', 'የት', 'አብራራ', 'ምንድን ነው', 'ደረጃዎች'];

    /** @var array<string, array{area: string, keys: list<string>}> keyword → approved area and KPI keys */
    private const METRICS = [
        'vacan' => ['area' => 'workforce', 'keys' => ['vacant_positions', 'vacancy_rate', 'approved_slots', 'occupied_positions']],
        'ክፍት' => ['area' => 'workforce', 'keys' => ['vacant_positions', 'vacancy_rate', 'approved_slots', 'occupied_positions']],
        'staffing' => ['area' => 'workforce', 'keys' => ['employee_count', 'approved_headcount', 'required_fte', 'capacity', 'positions_over_capacity', 'vacant_positions']],
        'headcount' => ['area' => 'workforce', 'keys' => ['employee_count', 'approved_headcount', 'required_fte', 'capacity']],
        'employees' => ['area' => 'workforce', 'keys' => ['employee_count', 'approved_headcount', 'active_without_assignment', 'multiple_current_assignments']],
        'የሰው ኃይል' => ['area' => 'workforce', 'keys' => ['employee_count', 'approved_headcount', 'vacant_positions', 'vacancy_rate']],
        'transfer' => ['area' => 'workforce', 'keys' => ['transfers_inbound', 'transfers_outbound', 'transfers_internal', 'transfers_net']],
        'ዝውውር' => ['area' => 'workforce', 'keys' => ['transfers_inbound', 'transfers_outbound', 'transfers_internal', 'transfers_net']],
        'complaint' => ['area' => 'public_voice', 'keys' => ['complaints_received', 'complaints_open', 'unrouted', 'backlog_change', 'open_at_period_end']],
        'ቅሬታ' => ['area' => 'public_voice', 'keys' => ['complaints_received', 'complaints_open', 'unrouted', 'backlog_change']],
        'feedback' => ['area' => 'public_voice', 'keys' => ['feedback_received', 'complaints_received', 'tip_offs_received', 'unrouted']],
        'tip' => ['area' => 'public_voice', 'keys' => ['tip_offs_received', 'unrouted']],
        'public voice' => ['area' => 'public_voice', 'keys' => ['feedback_received', 'complaints_received', 'tip_offs_received', 'unrouted', 'backlog_change']],
        'performance' => ['area' => 'performance', 'keys' => ['agreement_coverage', 'agreements_overdue', 'reviews_completed', 'midyear_reviews_overdue', 'yearend_reviews_overdue', 'results_released']],
        'agreement' => ['area' => 'performance', 'keys' => ['agreement_coverage', 'agreements_in_place', 'agreements_overdue', 'plans_published']],
        'review' => ['area' => 'performance', 'keys' => ['reviews_completed', 'midyear_reviews_overdue', 'yearend_reviews_overdue', 'appeals_pending']],
        'አፈጻጸም' => ['area' => 'performance', 'keys' => ['agreement_coverage', 'agreements_overdue', 'reviews_completed', 'results_released']],
        'data quality' => ['area' => 'data_quality', 'keys' => ['checks_run', 'checks_failing', 'checks_warning', 'records_flagged']],
        'service' => ['area' => 'service', 'keys' => ['services_active', 'services_with_activity', 'services_with_public_voice', 'submissions_linked_share', 'submissions_unlinked']],
        'attention' => ['area' => 'executive', 'keys' => []],
        'what changed' => ['area' => 'executive', 'keys' => []],
        'ትኩረት' => ['area' => 'executive', 'keys' => []],
        'ምን ተለወጠ' => ['area' => 'executive', 'keys' => []],
    ];

    /** @return array{mode: string, area: ?IntelligenceArea, metrics: list<string>, period: string, error_reference: ?string} */
    public function route(?string $action, string $message): array
    {
        $text = mb_strtolower(trim($message));
        $errorReference = preg_match('/\bERR-[A-Z0-9]{8,16}\b/i', $message, $m) ? strtoupper($m[0]) : null;
        $metric = $this->metric($text);

        $mode = self::ACTIONS[$action ?? ''] ?? null;
        if ($mode === null) {
            $mode = match (true) {
                // "What is the system status?" is a status question; "How do I open incidents?" is a help question.
                $this->has($text, self::STATUS_TERMS) && ! str_starts_with($text, 'how') && ! str_starts_with($text, 'እንዴት') => self::SYSTEM_DIAGNOSTICS,
                $errorReference !== null || $this->has($text, self::SUPPORT_TERMS) => self::SUPPORT,
                $metric !== null || $this->has($text, self::ANALYTICS_TERMS) => self::BUSINESS_ANALYSIS,
                $this->has($text, self::HELP_TERMS) => self::HELP,
                default => self::KNOWLEDGE,
            };
        }

        return [
            'mode' => $mode,
            'area' => $metric !== null ? IntelligenceArea::tryFrom($metric['area']) : ($mode === self::BUSINESS_ANALYSIS ? IntelligenceArea::Executive : null),
            'metrics' => $metric['keys'] ?? [],
            'period' => $this->period($text),
            'error_reference' => $errorReference,
        ];
    }

    /** @param  list<string>  $terms */
    private function has(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{area: string, keys: list<string>}|null */
    private function metric(string $text): ?array
    {
        foreach (self::METRICS as $term => $metric) {
            if (str_contains($text, $term)) {
                return $metric;
            }
        }

        return null;
    }

    private function period(string $text): string
    {
        return match (true) {
            str_contains($text, 'quarter') || str_contains($text, 'ሩብ ዓመት') => 'quarter',
            str_contains($text, 'this year') || str_contains($text, 'year') || str_contains($text, 'ዓመት') => 'year',
            str_contains($text, 'week') || str_contains($text, 'ሳምንት') => 'week',
            default => 'month',
        };
    }
}
