<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferAnnouncementStatus;
use App\Models\TransferAnnouncement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class CreateTransferAnnouncementAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $data, User $actor): TransferAnnouncement
    {
        if (isset($data['opening_date'], $data['closing_date'])
            && $data['opening_date'] >= $data['closing_date']
        ) {
            throw new DomainException('Opening date must be before closing date.');
        }

        return DB::transaction(function () use ($data, $actor): TransferAnnouncement {
            $announcement = TransferAnnouncement::query()->create(array_merge($data, [
                'status' => TransferAnnouncementStatus::Draft->value,
                'created_by' => $actor->id,
            ]));

            $this->writeAuditLogAction->execute(
                AuditEventType::TransferAnnouncementCreated,
                $actor,
                $announcement,
                $announcement->organization_id,
                newValues: $announcement->toArray(),
            );

            return $announcement;
        });
    }
}
