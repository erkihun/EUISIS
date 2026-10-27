<?php

declare(strict_types=1);

namespace App\Enums;

/** Lifecycle of organization cafeteria access and of service assignments. */
enum CafeteriaGrantStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
}
