<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

use Modules\Feedback\Contracts\Data\PublicFeedbackTarget;

/**
 * Who a public feedback QR is about, as the visitor sees it (ADR 0017), for
 * the public assistant on the same pages. Only what the QR page itself shows
 * travels: a display name, a position title, the institution's id and public
 * phone.
 */
interface PublicFeedbackTargets
{
    /** The target of an employee QR token, or null when the token does not open a page. */
    public function employee(string $token, string $locale): ?PublicFeedbackTarget;

    /** The target of an institution QR token, or null when the token does not open a page. */
    public function organization(string $token, string $locale): ?PublicFeedbackTarget;
}
