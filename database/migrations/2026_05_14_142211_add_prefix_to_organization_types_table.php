<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('organization_types', 'prefix')) {
                $table->string('prefix', 20)->nullable()->after('code')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('organization_types', function (Blueprint $table): void {
            if (Schema::hasColumn('organization_types', 'prefix')) {
                $table->dropIndex(['prefix']);
                $table->dropColumn('prefix');
            }
        });
    }
};
