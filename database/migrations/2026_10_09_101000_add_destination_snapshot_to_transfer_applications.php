<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_applications', function (Blueprint $table): void {
            $table->foreignUuid('announcement_position_id')
                ->nullable()
                ->after('announcement_id')
                ->constrained('transfer_announcement_positions')
                ->nullOnDelete();
            $table->json('source_assignment_snapshot')->nullable()->after('eligibility_snapshot');
            $table->json('destination_snapshot')->nullable()->after('source_assignment_snapshot');
            $table->index(['announcement_position_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('transfer_applications', function (Blueprint $table): void {
            $table->dropIndex(['announcement_position_id', 'status']);
            $table->dropConstrainedForeignUuid('announcement_position_id');
            $table->dropColumn(['source_assignment_snapshot', 'destination_snapshot']);
        });
    }
};
