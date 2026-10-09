<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferApplicationStatus;
use App\Models\TransferApplication;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Applicant-controlled withdrawal before selection, with an immutable reason. */
readonly class WithdrawTransferApplicationAction
{
    public function __construct(private WriteAuditLogAction $audit) {}

    public function execute(TransferApplication $application, User $actor, string $reason): TransferApplication
    {
        $application = DB::transaction(function () use ($application, $reason): TransferApplication {
            $locked = TransferApplication::query()->lockForUpdate()->findOrFail($application->id);
            if (! in_array($locked->status, [
                TransferApplicationStatus::Submitted,
                TransferApplicationStatus::UnderReview,
                TransferApplicationStatus::Verified,
            ], true)) {
                throw new DomainException('This application can no longer be withdrawn.');
            }
            $locked->update([
                'status' => TransferApplicationStatus::Withdrawn->value,
                'withdrawn_at' => now(),
                'withdrawal_reason' => $reason,
            ]);

            return $locked->fresh();
        });

        $this->audit->execute(
            AuditEventType::TransferApplicationWithdrawn,
            $actor,
            $application,
            $application->releasing_organization_id,
            newValues: ['status' => TransferApplicationStatus::Withdrawn->value],
            reason: $reason,
        );

        return $application;
    }
}
