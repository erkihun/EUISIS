<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Models\CodeRule;
use Database\Seeders\CodeRuleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Transfer applications are numbered by a code rule (application_number), but
 * no default rule was ever shipped, so submitting an application failed with
 * "No active code rule is configured for this entity." on every database
 * where an administrator had not created one by hand. Adds the default rule;
 * an administrator's existing active rule is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        CodeRuleSeeder::ensureFor(CodeRuleEntityType::TransferApplication);
    }

    public function down(): void
    {
        // Only the untouched default: never a rule that has issued numbers.
        CodeRule::query()
            ->where('entity_type', CodeRuleEntityType::TransferApplication->value)
            ->where('name_en', 'Transfer Application Number')
            ->where('next_number', 1)
            ->forceDelete();
    }
};
