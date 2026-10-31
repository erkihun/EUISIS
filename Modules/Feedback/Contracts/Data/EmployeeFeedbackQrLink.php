<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts\Data;

/** The public URL an employee's feedback QR encodes, and its token's status (FeedbackTokenStatus value). */
final readonly class EmployeeFeedbackQrLink
{
    public function __construct(
        public string $url,
        public string $status,
    ) {}
}
