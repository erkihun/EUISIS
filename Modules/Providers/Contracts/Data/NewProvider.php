<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\Data;

final readonly class NewProvider
{
    public function __construct(
        public string $code,
        public string $nameEn,
        public ?string $nameAm,
        /** The provider type by code, e.g. TRANSPORT or CAFETERIA. */
        public string $typeCode,
        /** When given, the type is created with this name if it does not exist; otherwise a missing type fails. */
        public ?string $typeNameIfMissing,
        /** The service enabled on registration, by service type code. */
        public string $serviceCode,
        public ?string $serviceTypeNameIfMissing = null,
        public ?string $contactPerson = null,
        public ?string $phoneNumber = null,
        public ?string $email = null,
        public ?string $address = null,
        public string $status = 'active',
        /** Null leaves the column's default (self). */
        public ?string $assignedScopeType = null,
        public ?string $assignedOrganizationId = null,
        /** Null stores none. */
        public ?array $metadata = null,
        public ?int $actorId = null,
        /** Also stamp updated_by with the actor. */
        public bool $stampUpdatedBy = false,
    ) {}
}
