<?php

declare(strict_types=1);

namespace App\Services\OrganizationalChange;

use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\CodeRuleEntityType;

/**
 * Request numbers from the organizational-change code rule (OCR-{year}-{6-digit
 * sequence}, restarting each year, by default). The code-rule sequence is taken
 * under a row lock; the unique index on request_no is the final guard.
 */
final class ChangeRequestNumberGenerator
{
    public function __construct(private readonly GenerateCodeAction $codes) {}

    public function generate(): string
    {
        return $this->codes->execute(CodeRuleEntityType::OrganizationalChangeRequest, [], null, null, 'request_no');
    }
}
