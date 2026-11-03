<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('occupations', 'skill_specialization_am')) {
            return;
        }

        Schema::table('occupations', function (Blueprint $table): void {
            $table->string('skill_specialization_am', 255)->nullable()->after('skill_specialization');
        });
    }

    public function down(): void
    {
        Schema::table('occupations', function (Blueprint $table): void {
            $table->dropColumn('skill_specialization_am');
        });
    }
};
