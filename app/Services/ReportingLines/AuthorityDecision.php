<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

/**
 * Whether an actor may exercise a supervisor action over an employee, and on
 * what footing (ADR 0025). Workflows record `basis` and `onBehalfOf` with
 * the decision so history shows who acted and whose authority it was.
 */
final readonly class AuthorityDecision
{
    public const DIRECT = 'direct';

    public const INDIRECT = 'indirect';

    public const DELEGATED = 'delegated';

    public function __construct(
        public bool $granted,
        public ?string $basis = null,
        /** The delegator's employee id when the authority is delegated. */
        public ?string $onBehalfOfEmployeeId = null,
        public ?string $delegationId = null,
        public string $reason = 'reporting-lines.authority.none',
    ) {}

    public static function direct(): self
    {
        return new self(true, self::DIRECT, reason: 'reporting-lines.authority.direct');
    }

    public static function indirect(): self
    {
        return new self(true, self::INDIRECT, reason: 'reporting-lines.authority.indirect');
    }

    public static function delegated(string $delegatorEmployeeId, string $delegationId): self
    {
        return new self(true, self::DELEGATED, $delegatorEmployeeId, $delegationId, 'reporting-lines.authority.delegated');
    }

    public static function denied(string $reason): self
    {
        return new self(false, reason: $reason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'granted' => $this->granted,
            'basis' => $this->basis,
            'on_behalf_of_employee_id' => $this->onBehalfOfEmployeeId,
            'delegation_id' => $this->delegationId,
            'reason' => $this->reason,
        ];
    }
}
