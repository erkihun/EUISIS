<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

/**
 * The public feedback policy as other parts of the application read it
 * (ADR 0017): whether intake is on, which submission types are open, and
 * the thresholds the Situation Room's attention rules use. Changing it is
 * the Feedback module's settings screen.
 */
interface FeedbackSettings
{
    public function enabled(): bool;

    public function allowAttachments(): bool;

    /**
     * The open submission types (Data\ServiceFeedbackType values).
     *
     * @return list<string>
     */
    public function enabledTypes(): array;

    /**
     * The Situation Room rules as configured; a null entry means the rule is off.
     *
     * @return array<string, int|null>
     */
    public function situationRoomRules(): array;
}
