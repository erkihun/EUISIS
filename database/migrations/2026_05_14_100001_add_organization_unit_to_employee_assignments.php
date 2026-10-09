<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table): void {
            $table->uuid('organization_unit_id')->nullable()->index()->after('organization_id');
            $table->foreign('organization_unit_id')
                ->references('id')
                ->on('organization_units')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table): void {
            $table->dropForeign(['organization_unit_id']);
            $table->dropColumn('organization_unit_id');
        });
    }
};
