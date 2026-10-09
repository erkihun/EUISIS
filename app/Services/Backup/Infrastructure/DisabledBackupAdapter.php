<?php

namespace App\Services\Backup\Infrastructure;

/** Used when backup status integration is switched off. It never reports a healthy state. */
final class DisabledBackupAdapter implements BackupInfrastructureAdapter
{
    public function __construct(private string $reasonCode = 'NOT_CONFIGURED') {}

    public function driver(): string
    {
        return 'disabled';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function version(): ?string
    {
        return null;
    }

    public function inspect(): InfrastructureSnapshot
    {
        $status = $this->reasonCode === 'NOT_CONFIGURED' ? InfrastructureSnapshot::NOT_CONFIGURED : InfrastructureSnapshot::UNAVAILABLE;

        return new InfrastructureSnapshot($status, $this->reasonCode, $this->driver(), binary: 'NOT_CHECKED', checkedAt: time());
    }

    public function check(): array
    {
        return ['status' => 'NOT_RUN', 'reason_code' => $this->reasonCode, 'diagnostics' => []];
    }
}
