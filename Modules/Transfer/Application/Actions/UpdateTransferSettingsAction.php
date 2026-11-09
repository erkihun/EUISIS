<?php

declare(strict_types=1);

namespace Modules\Transfer\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Modules\Transfer\Domain\Models\TransferSetting;

readonly class UpdateTransferSettingsAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $data, User $actor): TransferSetting
    {
        $settings = TransferSetting::current();

        $settings->fill(array_merge($data, ['updated_by' => $actor->id]));
        $settings->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::TransferSettingsUpdated,
            $actor,
            $settings,
            null,
            newValues: $settings->toArray(),
        );

        return $settings->fresh();
    }
}
