<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferApplicationStatus;
use App\Enums\TransferApprovalStatus;
use App\Enums\TransferApprovalType;
use App\Models\TransferApplication;
use App\Models\TransferApproval;
use App\Models\TransferScreeningReview;
use App\Models\TransferSetting;
use App\Models\User;
use App\Services\Transfers\CanonicalTransferCreationService;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class SelectTransferCandidateAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private CanonicalTransferCreationService $canonicalTransfers,
    ) {}

    public function execute(
        TransferApplication $application,
        User $actor,
        ?string $notes = null,
        ?string $effectiveDate = null,
    ): TransferApplication {
        if (! in_array($application->status, [
            TransferApplicationStatus::UnderReview,
            TransferApplicationStatus::Verified,
        ], true)) {
            throw new DomainException('Application must be under review or verified before selection.');
        }

        $settings = TransferSetting::current();

        return DB::transaction(function () use ($application, $actor, $notes, $settings): TransferApplication {
            $application = TransferApplication::query()->lockForUpdate()->findOrFail($application->id);
            if (! in_array($application->status, [
                TransferApplicationStatus::UnderReview,
                TransferApplicationStatus::Verified,
            ], true)) {
                throw new DomainException('Application must be under review or verified before selection.');
            }

            $application->update([
                'status' => TransferApplicationStatus::Selected->value,
                'selected_at' => now(),
                'selected_by' => $actor->id,
                'effective_date' => $effectiveDate ?? $application->effective_date ?? now()->toDateString(),
            ]);

            TransferScreeningReview::query()->create([
                'transfer_application_id' => $application->id,
                'reviewer_id' => $actor->id,
                'action' => 'selected',
                'notes' => $notes,
            ]);

            // Determine next status based on required approvals
            $nextStatus = $this->determineApprovalChainEntry($settings);
            $application->update([
                'status' => $nextStatus->value,
                'approved_at' => $nextStatus === TransferApplicationStatus::Approved ? now() : null,
            ]);

            // Create pending approval records based on settings
            $this->createApprovalRecords($application, $settings);

            if ($nextStatus === TransferApplicationStatus::Approved) {
                $this->canonicalTransfers->create($application, $actor);
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::TransferApplicationSelected,
                $actor,
                $application->fresh(),
                $application->releasing_organization_id,
                newValues: $application->fresh()->toArray(),
            );

            return $application->fresh();
        });
    }

    private function determineApprovalChainEntry(TransferSetting $settings): TransferApplicationStatus
    {
        if ($settings->releasing_consent_required) {
            return TransferApplicationStatus::ReleasePending;
        }
        if ($settings->receiving_consent_required) {
            return TransferApplicationStatus::ReceivingPending;
        }
        if ($settings->final_approval_required) {
            return TransferApplicationStatus::FinalApprovalPending;
        }

        return TransferApplicationStatus::Approved;
    }

    private function createApprovalRecords(TransferApplication $application, TransferSetting $settings): void
    {
        if ($settings->releasing_consent_required) {
            TransferApproval::query()->create([
                'transfer_application_id' => $application->id,
                'approval_type' => TransferApprovalType::Release->value,
                'status' => TransferApprovalStatus::Pending->value,
            ]);
        }
        if ($settings->receiving_consent_required) {
            TransferApproval::query()->create([
                'transfer_application_id' => $application->id,
                'approval_type' => TransferApprovalType::Receiving->value,
                'status' => TransferApprovalStatus::Pending->value,
            ]);
        }
        if ($settings->final_approval_required) {
            TransferApproval::query()->create([
                'transfer_application_id' => $application->id,
                'approval_type' => TransferApprovalType::Final->value,
                'status' => TransferApprovalStatus::Pending->value,
            ]);
        }
    }
}
