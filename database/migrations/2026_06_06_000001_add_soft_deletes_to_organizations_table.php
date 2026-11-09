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
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete()->after('branding_secondary_color');
            $table->text('deletion_reason')->nullable()->after('deleted_by');
            $table->softDeletes()->after('deletion_reason');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['deletion_reason', 'deleted_at']);
        });
    }
};
