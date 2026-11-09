<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('metadata');
            $table->string('branding_primary_color', 7)->nullable()->after('logo_path');
            $table->string('branding_secondary_color', 7)->nullable()->after('branding_primary_color');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'branding_primary_color', 'branding_secondary_color']);
        });
    }
};
