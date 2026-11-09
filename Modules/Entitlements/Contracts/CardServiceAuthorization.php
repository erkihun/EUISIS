<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Entitlements\Contracts\Data\ServiceAuthorizationOutcome;

/**
 * Card-based service checks for the APIs and terminals outside the module
 * (ADR 0017): a token verification, and the authorization of a card a
 * terminal has already resolved, with the transaction recorded on request.
 */
interface CardServiceAuthorization
{
    /**
     * Verifies a presented card token ("<card id>|<raw token>") for a service,
     * at a service point when one is named. Returns the verification result as
     * the API resources present it (allowed, result_code, denial_reason, card
     * and employee data where permitted).
     *
     * @return array<string, mixed>
     */
    public function verifyToken(string $token, string $serviceTypeId, ?string $servicePointId, ?User $actor = null, ?Request $request = null): array;

    /**
     * Authorizes a card a terminal has resolved for a non-cafeteria service at
     * a service point, under the employee's entitlement, and records the
     * transaction when $record is set (a retried reference finds the first).
     */
    public function authorizeCard(string $cardId, string $serviceTypeId, string $servicePointId, bool $record, ?string $reference, ?Request $request = null): ServiceAuthorizationOutcome;
}
