<?php

declare(strict_types=1);

namespace App\Backbone\Data;

/**
 * The answer to "is this card a valid credential, and whose is it", reduced to
 * what a point of service needs. The card's public UUID, the employee UUID and
 * the employee number are separate fields and never stand in for each other.
 * No national ID, phone, address, photo path or HR history.
 */
final readonly class CredentialVerificationData
{
    public function __construct(
        public bool $valid,
        /** Card state: active, revoked, expired, replaced, … or not_found. */
        public string $status,
        public ?string $cardUuid,
        public ?string $employeeUuid,
        public ?string $employeeNumber,
        public ?string $displayName,
        public ?string $employmentStatus,
        public ?string $organizationUuid,
    ) {}

    public static function notFound(): self
    {
        return new self(false, 'not_found', null, null, null, null, null, null);
    }
}
