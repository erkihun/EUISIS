<?php

declare(strict_types=1);

namespace Modules\Letters\Contracts;

use Modules\Letters\Contracts\Data\IssueLetterRequest;
use Modules\Letters\Contracts\Data\LetterSummary;

/**
 * The one way another module gets an official letter (ADR 0037). It drafts the
 * letter from a published template inside the sending institution's registry
 * and returns where to find it. Nothing outside the module writes letters.
 */
interface LetterIssuer
{
    /**
     * @throws \InvalidArgumentException when the template is unknown, unpublished or not
     *                                   offered to the sending institution
     */
    public function issue(IssueLetterRequest $request): LetterSummary;
}
