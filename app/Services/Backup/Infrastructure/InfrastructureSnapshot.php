<?php

namespace App\Services\Backup\Infrastructure;

/**
 * Sanitized result of one infrastructure probe. It holds status codes, allowlisted backup
 * metadata and process diagnostics (exit code, output presence, tool error number) only:
 * never raw output, paths from error text, environment values or credentials.
 */
final class InfrastructureSnapshot
{
    public const AVAILABLE = 'AVAILABLE';

    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const UNAVAILABLE = 'UNAVAILABLE';

    public const UNKNOWN = 'UNKNOWN';

    /**
     * @param  list<array{id: int, status: string, encrypted: ?bool, backups: list<array{reference: string, type: string, completed_at: int, size: int}>, errored_backups: int, usage_percent: float|int|null, wal_max: ?string}>  $repositories
     * @param  array<string, bool|int|string|null>  $diagnostics
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $reasonCode,
        public readonly string $driver,
        public readonly ?string $stanza = null,
        public readonly ?string $binary = null,
        public readonly ?string $version = null,
        public readonly array $repositories = [],
        public readonly array $diagnostics = [],
        public readonly ?int $checkedAt = null,
    ) {}

    /** Timeouts cannot establish state either way; every other failure means unavailable. */
    public static function failure(string $reasonCode, string $driver, ?string $stanza = null, ?string $binary = null, array $diagnostics = []): self
    {
        return new self($reasonCode === 'COMMAND_TIMEOUT' ? self::UNKNOWN : self::UNAVAILABLE, $reasonCode, $driver, $stanza, $binary, null, [], $diagnostics, time());
    }

    public function toArray(): array
    {
        return ['status' => $this->status, 'reason_code' => $this->reasonCode, 'driver' => $this->driver, 'stanza' => $this->stanza,
            'binary' => $this->binary, 'version' => $this->version, 'repositories' => $this->repositories,
            'diagnostics' => $this->diagnostics, 'checked_at' => $this->checkedAt];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['status'], $data['reason_code'], $data['driver'], $data['stanza'], $data['binary'], $data['version'],
            $data['repositories'], $data['diagnostics'], $data['checked_at']);
    }
}
