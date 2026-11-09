<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts\Data;

/**
 * The target of a public feedback QR as its page shows it: an employee
 * (display name and position title only) or an institution (name, id and
 * the raw public contact phone it publishes).
 */
final readonly class PublicFeedbackTarget
{
    public function __construct(
        public PublicFeedbackTargetType $type,
        public string $name,
        public ?string $positionTitle = null,
        public ?string $organizationId = null,
        public ?string $publicContactPhone = null,
    ) {}
}
