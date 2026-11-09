<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->string('old_code', 100)->nullable()->after('job_position_code');
            $table->string('bpr_name')->nullable()->after('title_am');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['old_code', 'bpr_name']);
        });
    }
};
