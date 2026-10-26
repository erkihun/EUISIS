<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendCoreNotifications;
use App\Models\CoreAuditLog;
use App\Models\User;
use App\Support\CorePermissions;
use Illuminate\Support\Facades\DB;

/**
 * Core notifications (ADR 0011) come from the audit trail: an audited change
 * that someone must act on or know about queues one job after its
 * transaction commits, so a notification can never roll back or block the
 * change. The job finds the recipients — holders of a permission whose scope
 * covers the change's organization, or the people the record names — leaves
 * out the actor, and writes each an in-app notification; e-mail and SMS go
 * only where Core settings › Notifications turn them on.
 */
final class NotificationRouter
{
    /**
     * audit action => [event, recipients]; recipients are "permission:<name>" (in the change's
     * organization), "citywide:<name>" (city-wide holders only) or "record:<column>" (a user id on
     * the audited record or its card request).
     */
    public const RULES = [
        'employee.submitted' => ['employee_submitted', ['permission:employees.verify']],
        'employee.returned' => ['employee_returned', ['record:registered_by', 'record:submitted_by']],
        'employee.verified' => ['employee_verified', ['permission:employees.approve']],
        'employee.approved' => ['employee_approved', ['record:registered_by']],
        'hierarchy.review' => ['hierarchy_submitted', ['citywide:hierarchy-versions.approve']],
        'hierarchy.approve' => ['hierarchy_approved', ['citywide:hierarchy-versions.publish']],
        'hierarchy.publish' => ['hierarchy_published', ['citywide:hierarchy-versions.manage']],
        'card_request.approved' => ['card_approved', ['record:requested_by', 'permission:card_print_batches.manage']],
        'card.print' => ['card_printed', ['permission:cards.quality_check']],
        'card.reprinted' => ['card_printed', ['permission:cards.quality_check']],
        'card.quality_failed' => ['card_quality_failed', ['permission:cards.reprint']],
        'card.quality_passed' => ['card_ready_for_pickup', ['permission:id-cards.issue', 'record:requested_by']],
        'card.activate' => ['card_activated', ['record:requested_by']],
        'card.suspend' => ['card_suspended', ['permission:cards.manage']],
        'card.revoke' => ['card_revoked', ['permission:cards.manage', 'record:requested_by']],
        'card.expiring' => ['card_expiring', ['permission:cards.manage']],
        'api_client.rotated' => ['api_credential_rotated', ['citywide:integrations.manage']],
        'api_client.revoked' => ['api_client_revoked', ['citywide:integrations.manage']],
        'webhook.suspended' => ['webhook_suspended', ['citywide:integrations.manage']],
        'break_glass.requested' => ['break_glass_requested', ['citywide:break_glass.approve']],
        'break_glass.approved' => ['break_glass_approved', ['record:user_id']],
        'break_glass.expiring' => ['break_glass_expiring', ['record:user_id']],
        'break_glass.expired' => ['break_glass_expired', ['record:user_id']],
        'import.completed' => ['import_completed', ['record:uploaded_by']],
        'import.failed' => ['import_failed', ['record:uploaded_by']],
        'data_quality.critical_detected' => ['data_quality_critical', ['citywide:data_quality.manage']],
        'audit.verification_failed' => ['security_alert', ['citywide:security_dashboard.view']],
        'mfa.reset' => ['security_alert', ['record:subject_user']],
    ];

    /** Models whose audited record names the people to tell (the subject type in the audit row => table). */
    private const RECORDS = [
        'Employee' => 'employees', 'CardRequest' => 'card_requests', 'IdCard' => 'id_cards', 'BreakGlassGrant' => 'break_glass_grants', 'ImportBatch' => 'import_batches',
    ];

    public function queue(CoreAuditLog $log): void
    {
        if (isset(self::RULES[$log->action])) {
            SendCoreNotifications::dispatch((string) $log->id)->afterCommit();
        }
    }

    /**
     * The people to tell about an audited change.
     *
     * @return list<User>
     */
    public function recipients(CoreAuditLog $log): array
    {
        [, $rules] = self::RULES[$log->action] ?? [null, []];
        $ids = [];
        foreach ($rules as $rule) {
            [$kind, $name] = explode(':', $rule, 2);
            if ($kind === 'record') {
                $ids = [...$ids, ...$this->named($log, $name)];

                continue;
            }
            $roles = CorePermissions::rolesWith($name);
            if ($roles === []) {
                continue;
            }
            $candidates = User::query()->where('is_active', true)->whereIn('role', $roles)->get();
            foreach ($candidates as $user) {
                $reaches = $kind === 'citywide' ? $user->isCityWide() : ($log->organization_id === null ? $user->isCityWide() : app(OrganizationScope::class)->allows($user, (string) $log->organization_id));
                if ($reaches && $user->can($name)) {
                    $ids[] = (int) $user->id;
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id !== (int) $log->actor_user_id)));

        return $ids === [] ? [] : User::query()->whereKey($ids)->where('is_active', true)->get()->all();
    }

    /** @return list<int> */
    private function named(CoreAuditLog $log, string $column): array
    {
        if ($column === 'subject_user') {
            return $log->subject_type === 'User' && ctype_digit((string) $log->subject_id) ? [(int) $log->subject_id] : [];
        }
        $table = self::RECORDS[$log->subject_type] ?? null;
        if ($table === null || $log->subject_id === null) {
            return [];
        }
        // A card's people are on its request.
        if ($table === 'id_cards' && $column === 'requested_by') {
            $value = DB::table('id_cards')->join('card_requests', 'card_requests.id', '=', 'id_cards.card_request_id')->where('id_cards.id', $log->subject_id)->value('card_requests.requested_by');
        } else {
            $value = DB::getSchemaBuilder()->hasColumn($table, $column) ? DB::table($table)->where('id', $log->subject_id)->value($column) : null;
        }

        return $value === null ? [] : [(int) $value];
    }
}
