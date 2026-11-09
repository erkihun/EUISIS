<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->supportedTables() as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->softDeletes()->index();
                }

                if (! Schema::hasColumn($tableName, 'deleted_by')) {
                    $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn($tableName, 'deletion_reason')) {
                    $table->text('deletion_reason')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->supportedTables()) as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'deleted_by')) {
                    $table->dropConstrainedForeignId('deleted_by');
                }

                if (Schema::hasColumn($tableName, 'deletion_reason')) {
                    $table->dropColumn('deletion_reason');
                }

                // Keep deleted_at on rollback because some supported tables already had soft deletes
                // before this migration and their original migration owns that column.
            });
        }
    }

    /**
     * @return list<string>
     */
    private function supportedTables(): array
    {
        return [
            'organization_units',
            'organization_unit_types',
            'positions',
            'service_types',
            'entitlement_rules',
            'code_rules',
        ];
    }
};
