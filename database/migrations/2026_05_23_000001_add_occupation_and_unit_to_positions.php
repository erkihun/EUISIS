<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->foreignUuid('organization_unit_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('organization_units')
                ->nullOnDelete();

            $table->foreignUuid('occupation_id')
                ->nullable()
                ->after('organization_unit_id')
                ->constrained('occupations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropForeign(['organization_unit_id']);
            $table->dropForeign(['occupation_id']);
            $table->dropColumn(['organization_unit_id', 'occupation_id']);
        });
    }
};
