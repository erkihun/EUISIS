<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

readonly class UpdateSystemSettingAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(SystemSetting $setting, string $value, User $actor): SystemSetting
    {
        $oldValue = $setting->value;
        $isDefaultPassword = $setting->group === 'security' && $setting->key === 'default_password_hash';
        $storedValue = $isDefaultPassword && Hash::needsRehash($value)
            ? Hash::make($value)
            : $value;

        $setting->update([
            'value' => $storedValue,
            'updated_by' => $actor->id,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingUpdated,
            $actor,
            $setting,
            null,
            oldValues: ['value' => $isDefaultPassword ? ($oldValue ? 'configured' : 'not_configured') : $oldValue],
            newValues: ['value' => $isDefaultPassword ? ($storedValue ? 'configured' : 'not_configured') : $storedValue],
        );

        return $setting->fresh();
    }
}
