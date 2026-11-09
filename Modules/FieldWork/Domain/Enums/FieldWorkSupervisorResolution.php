<?php

declare(strict_types=1);

namespace Modules\FieldWork\Domain\Enums;

/**
 * Outcome of resolving the immediate supervisor at submission. NOT_RESOLVED
 * is a visible flag: the request waits, it is never auto-approved.
 */
enum FieldWorkSupervisorResolution: string
{
    case Resolved = 'resolved';
    case NotResolved = 'supervisor_not_resolved';
}
