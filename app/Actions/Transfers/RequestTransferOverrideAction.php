<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferOverrideStatus;
use App\Models\TransferApplication;
use App\Models\TransferRuleOverride;
use App\Models\TransferSetting;
use App\Models\User;
use DomainException;

readonly class RequestTransferOverrideAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(
        TransferApplication $application,
        User $actor,
        string $ruleKey,
        string $reason,
    ): TransferRuleOverride {
        $settings = TransferSetting::current();

        if (! $settings->allow_exceptional_override) {
            throw new DomainException('Exceptional overrides are not permitted by current Transfer Settings.');
        }

        $existing = TransferRuleOverride::query()
            ->where('transfer_application_id', $application->id)
            ->where('rule_key', $ruleKey)
            ->where('status', TransferOverrideStatus::Pending->value)
            ->exists();

        if ($existing) {
            throw new DomainException('An override request for this rule is already pending.');
        }

        $override = TransferRuleOverride::query()->create([
            'transfer_application_id' => $application->id,
            'requested_by' => $actor->id,
            'rule_key' => $ruleKey,
            'reason' => $reason,
            'status' => TransferOverrideStatus::Pending->value,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::TransferOverrideRequested,
            $actor,
            $application,
            $application->releasing_organization_id,
            newValues: $override->toArray(),
            reason: $reason,
        );

        return $override;
    }
}
