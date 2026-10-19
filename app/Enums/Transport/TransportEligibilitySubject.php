<?php

declare(strict_types=1);

namespace App\Enums\Transport;

/** Who an eligibility grant names: one employee, or an office (and optionally its subtree). */
enum TransportEligibilitySubject: string
{
    case Employee = 'employee';
    case Organization = 'organization';
}
