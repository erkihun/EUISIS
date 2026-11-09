<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_transfers', function (Blueprint $table): void {
            $table->uuid('from_organization_unit_id')->nullable()->after('from_organization_id');
            $table->uuid('to_organization_unit_id')->nullable()->after('to_organization_id');

            $table->foreign('from_organization_unit_id')
                ->references('id')
                ->on('organization_units')
                ->nullOnDelete();

            $table->foreign('to_organization_unit_id')
                ->references('id')
                ->on('organization_units')
                ->nullOnDelete();

            $table->index('from_organization_unit_id');
            $table->index('to_organization_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_transfers', function (Blueprint $table): void {
            $table->dropForeign(['from_organization_unit_id']);
            $table->dropForeign(['to_organization_unit_id']);
            $table->dropColumn(['from_organization_unit_id', 'to_organization_unit_id']);
        });
    }
};
