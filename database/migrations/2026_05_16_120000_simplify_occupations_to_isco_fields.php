<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occupations', function (Blueprint $table): void {
            if (! Schema::hasColumn('occupations', 'description')) {
                $table->text('description')->nullable()->after('skill_specialization');
            }
        });

        if (
            Schema::hasColumn('occupations', 'description')
            && Schema::hasColumn('occupations', 'description_en')
            && Schema::hasColumn('occupations', 'description_am')
        ) {
            DB::table('occupations')
                ->whereNull('description')
                ->update([
                    'description' => DB::raw("COALESCE(NULLIF(description_en, ''), NULLIF(description_am, ''))"),
                ]);
        }

        if (Schema::hasTable('code_rules')) {
            DB::table('code_rules')
                ->where('entity_type', CodeRuleEntityType::Occupation->value)
                ->update(['is_active' => false]);
        }
    }

    public function down(): void
    {
        Schema::table('occupations', function (Blueprint $table): void {
            if (Schema::hasColumn('occupations', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
