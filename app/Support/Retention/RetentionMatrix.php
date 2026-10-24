<?php

declare(strict_types=1);

namespace App\Support\Retention;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Throwable;

/**
 * The retention schedule by data class (ADR 0004, docs/governance/retention-
 * matrix.md). Periods are a policy decision for HR, Legal, Records
 * Management, Audit and Data Protection together, never invented here: until
 * a class is approved it is NEEDS_POLICY_DECISION_RETENTION and its records
 * are kept. A class may then be approved to be retained, archived (exported
 * to the archive disk, then removed from the live table) or purged, each
 * only with the approval reference and an approved period.
 *
 * Operational housekeeping (notifications, reminders) is not a record and
 * keeps its own automatic windows in config/retention.php.
 */
final readonly class RetentionMatrix
{
    public const NEEDS_POLICY_DECISION = 'NEEDS_POLICY_DECISION_RETENTION';

    public const AUTHORIZED = 'AUTHORIZED';

    public const APPROVED_RETAIN = 'APPROVED_RETAIN';

    public const APPROVED_NOT_AUTOMATED = 'APPROVED_NOT_AUTOMATED';

    public const ACTIONS = ['retain', 'archive', 'purge_when_authorized'];

    /**
     * Record classes: label, tables, and whether the application can archive
     * or purge them (only log classes can; everything else has no deletion path).
     *
     * @var array<string, array{label: string, tables: list<string>, automated: bool}>
     */
    public const RECORD_CLASSES = [
        'employee_master' => ['label' => 'Employee master and employment status history', 'tables' => ['employees', 'employment_status_histories', 'employee_correction_requests'], 'automated' => false],
        'assignment_history' => ['label' => 'Assignment, occupancy and transfer history', 'tables' => ['employee_assignments', 'position_occupancies', 'employee_transfers'], 'automated' => false],
        'id_card_history' => ['label' => 'ID cards, requests, print and replacement history', 'tables' => ['id_cards', 'card_requests', 'card_replacements', 'card_issuances', 'card_print_batches', 'card_print_batch_items', 'id_card_print_snapshots'], 'automated' => false],
        'grievance_cases' => ['label' => 'Grievance cases', 'tables' => ['grievances', 'grievance_status_histories', 'grievance_responses', 'grievance_decision_letters'], 'automated' => false],
        'discipline_cases' => ['label' => 'Discipline cases', 'tables' => ['disciplinary_cases', 'disciplinary_charges', 'disciplinary_decisions', 'disciplinary_evidence'], 'automated' => false],
        'appeal_and_tribunal_cases' => ['label' => 'Appeals and administrative tribunal cases', 'tables' => ['disciplinary_appeals', 'performance_appeals', 'assessment_appeals', 'administrative_tribunal_cases'], 'automated' => false],
        'performance_records' => ['label' => 'Performance and assessment records', 'tables' => ['performance_plans', 'performance_reviews', 'performance_results', 'assessment_records', 'assessment_result_versions'], 'automated' => false],
        'provider_transactions' => ['label' => 'Service and provider transactions, settlements', 'tables' => ['service_transactions', 'cafeteria_transactions', 'transport_transactions', 'cafeteria_settlements', 'transport_settlements', 'provider_settlements'], 'automated' => false],
        'uploaded_documents' => ['label' => 'Uploaded documents and case attachments', 'tables' => ['employee_documents'], 'automated' => false],
        'audit_logs' => ['label' => 'Audit log and security events', 'tables' => ['audit_logs', 'security_events'], 'automated' => true],
        'verification_logs' => ['label' => 'Card and NFC verification logs', 'tables' => ['card_verifications', 'nfc_verification_logs'], 'automated' => true],
        'api_logs' => ['label' => 'Partner API request logs', 'tables' => ['api_request_logs'], 'automated' => true],
    ];

    public function __construct(private SystemSettingsService $settings) {}

    /**
     * @return array<string, array{label: string, tables: list<string>, automated: bool, action: string, days: ?int, approval: ?string, state: string}>
     */
    public function classes(): array
    {
        $classes = [];
        foreach (self::RECORD_CLASSES as $key => $definition) {
            $classes[$key] = $definition + $this->decision($key, $definition['automated']);
        }

        return $classes;
    }

    /** @return array{label: string, tables: list<string>, automated: bool, action: string, days: ?int, approval: ?string, state: string} */
    public function for(string $class): array
    {
        return $this->classes()[$class];
    }

    /** True when the application may archive or purge this class now. */
    public function authorized(string $class): bool
    {
        return $this->for($class)['state'] === self::AUTHORIZED;
    }

    /** @return array{action: string, days: ?int, approval: ?string, state: string} */
    private function decision(string $class, bool $automated): array
    {
        $config = (array) config('retention.records.'.$class, []);
        $action = in_array($config['action'] ?? null, self::ACTIONS, true) ? $config['action'] : 'retain';
        $approval = filled($config['approval'] ?? null) ? (string) $config['approval'] : null;
        $days = filled($config['days'] ?? null) ? max(1, (int) $config['days']) : null;
        if ($class === 'audit_logs' && $days === null) {
            // The audit period is kept in Security settings; it applies only once approved.
            $days = $this->auditSettingDays();
        }

        $state = match (true) {
            $approval === null => self::NEEDS_POLICY_DECISION,
            $action === 'retain' => self::APPROVED_RETAIN,
            ! $automated => self::APPROVED_NOT_AUTOMATED,
            $days === null => self::NEEDS_POLICY_DECISION,
            default => self::AUTHORIZED,
        };

        return ['action' => $action, 'days' => $days, 'approval' => $approval, 'state' => $state];
    }

    private function auditSettingDays(): ?int
    {
        try {
            return max(30, (int) $this->settings->get(SystemSettingsRegistry::GROUP_SECURITY, 'audit_retention_days', 730));
        } catch (Throwable) {
            return null;
        }
    }
}
