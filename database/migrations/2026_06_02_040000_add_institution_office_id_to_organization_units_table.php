<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_units', function (Blueprint $table): void {
            $table->uuid('institution_office_id')->nullable()->after('organization_unit_type_id');
            $table->foreign('institution_office_id')
                ->references('id')
                ->on('institution_offices')
                ->nullOnDelete();
            $table->index('institution_office_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_units', function (Blueprint $table): void {
            $table->dropForeign(['institution_office_id']);
            $table->dropIndex(['institution_office_id']);
            $table->dropColumn('institution_office_id');
        });
    }
};
