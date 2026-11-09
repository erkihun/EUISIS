<?php

declare(strict_types=1);

namespace App\Actions\CodeRules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\User;
use App\Services\CodeGeneration\CodeRuleResolver;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidateManualCodeOverrideAction
{
    public function __construct(
        private readonly CodeRuleResolver $codeRuleResolver,
        private readonly CodeRuleTargetRegistry $targetRegistry,
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    /**
     * Validate a manual code override for the given entity type.
     *
     * @throws ValidationException
     */
    public function validate(
        CodeRuleEntityType|string $entityType,
        string $code,
        array $context,
        User $user,
        string $field = 'code',
        ?string $currentId = null,
    ): void {
        $entityValue = $entityType instanceof CodeRuleEntityType ? $entityType->value : $entityType;

        $codeRule = $this->codeRuleResolver->resolve($entityValue, $context);

        if ($codeRule !== null) {
            // Rule exists — check manual override permission
            if (! $codeRule->allow_manual_override) {
                throw ValidationException::withMessages([
                    $field => __('code-rules.manual_override_not_allowed'),
                ]);
            }

            if ($codeRule->require_approval_for_override && ! $user->can('code-rules.manageOverrides')) {
                throw ValidationException::withMessages([
                    $field => __('code-rules.manual_override_not_allowed'),
                ]);
            }
        } else {
            // No rule configured — check generic policy permission
            if (! $user->can('code-rules.manageOverrides')) {
                throw ValidationException::withMessages([
                    $field => __('code-rules.manual_override_not_allowed'),
                ]);
            }
        }

        // Check uniqueness in the target table
        $target = $this->targetRegistry->get($entityValue);

        if ($target !== null) {
            $exists = DB::table($target['table'])
                ->where($target['code_column'], $code)
                ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    $field => __('code-rules.generated_code_exists'),
                ]);
            }
        }

        // Audit the override attempt
        $this->writeAuditLogAction->execute(
            AuditEventType::CodeManualOverrideUsed,
            $user,
            $codeRule,
            null,
            null,
            [
                'entity_type' => $entityValue,
                'code' => $code,
                'field' => $field,
                'override_validated' => true,
            ],
        );
    }
}
