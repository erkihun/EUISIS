<?php

namespace App\Services\Backup\Infrastructure;

/**
 * Read-only view of the backup tool. Implementations never accept caller-supplied commands,
 * stanzas or paths: every value comes from server-side configuration.
 */
interface BackupInfrastructureAdapter
{
    public function driver(): string;

    public function isConfigured(): bool;

    /** Tool version, or null when it cannot be established. */
    public function version(): ?string;

    /** Availability, repository state and backup inventory from a single probe. */
    public function inspect(): InfrastructureSnapshot;

    /**
     * Operator-only end-to-end check. It may switch and archive a WAL segment, so it is never
     * called from a web request.
     *
     * @return array{status: string, reason_code: ?string, diagnostics: array<string, mixed>}
     */
    public function check(): array;
}
