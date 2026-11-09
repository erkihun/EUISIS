<?php

declare(strict_types=1);

namespace Modules\Transfer\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use DomainException;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;
use Modules\Transfer\Domain\Models\TransferApplication;
use Modules\Transfer\Domain\Models\TransferScreeningReview;

readonly class ScreenTransferApplicationAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(TransferApplication $application, User $actor, ?string $notes = null): TransferApplication
    {
        if ($application->status !== TransferApplicationStatus::Submitted) {
            throw new DomainException('Only submitted applications can be moved to under review.');
        }

        $application->update(['status' => TransferApplicationStatus::UnderReview->value]);

        TransferScreeningReview::query()->create([
            'transfer_application_id' => $application->id,
            'reviewer_id' => $actor->id,
            'action' => 'under_review',
            'notes' => $notes,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::TransferApplicationUnderReview,
            $actor,
            $application->fresh(),
            $application->releasing_organization_id,
            newValues: $application->fresh()->toArray(),
        );

        return $application->fresh();
    }
}
