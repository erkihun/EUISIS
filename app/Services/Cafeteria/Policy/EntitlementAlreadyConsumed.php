<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

use RuntimeException;

/**
 * Another scan — at this or any other cafeteria — claimed one of the
 * entitlements first. Raised when the database refuses the claim, so it holds
 * even when two scanners check availability at the same moment.
 */
final class EntitlementAlreadyConsumed extends RuntimeException {}
