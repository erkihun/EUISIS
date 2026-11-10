<?php

declare(strict_types=1);

namespace Modules\Letters\Contracts\Data;

/**
 * What another module hands the Letters module to get an official letter
 * drafted for one of its records (ADR 0037). The letter is created as a draft
 * in the sending institution's registry; people finish, sign and dispatch it
 * in the Letters module.
 *
 * @param  array<string, string>  $context  values for the template's own placeholders
 */
final class IssueLetterRequest
{
    public function __construct(
        /** The template's code (platform or the sending institution's). */
        public readonly string $templateCode,
        /** The sending institution. */
        public readonly string $organizationId,
        /** The record this letter is about, e.g. 'grievance' and its id. */
        public readonly string $regardingType,
        public readonly string $regardingId,
        /** The signed-in user asking for it (becomes created_by), or null for system work. */
        public readonly ?int $actorId,
        public readonly ?string $recipientOrganizationId = null,
        public readonly ?string $externalPartyName = null,
        public readonly ?string $externalPartyAddress = null,
        public readonly ?int $signatoryUserId = null,
        public readonly array $context = [],
        /** 'outgoing' (default) or 'internal'. */
        public readonly string $direction = 'outgoing',
        /** 'am' or 'en'; defaults to the template's language. */
        public readonly ?string $language = null,
    ) {}
}
