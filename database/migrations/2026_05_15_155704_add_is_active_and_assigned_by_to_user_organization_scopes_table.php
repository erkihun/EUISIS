<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_organization_scopes', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('effective_to');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete()->after('is_active');
            $table->json('metadata')->nullable()->after('assigned_by');
        });
    }

    public function down(): void
    {
        Schema::table('user_organization_scopes', function (Blueprint $table): void {
            $table->dropForeign(['assigned_by']);
            $table->dropColumn(['is_active', 'assigned_by', 'metadata']);
        });
    }
};
