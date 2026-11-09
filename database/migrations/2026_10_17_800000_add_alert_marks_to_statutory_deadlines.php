<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the "due soon" and "overdue" alerts for a statutory deadline were sent
 * (legal:statutory-deadline-alerts), so each is sent once. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_deadlines', function (Blueprint $table): void {
            $table->timestamp('due_soon_notified_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('statutory_deadlines', function (Blueprint $table): void {
            $table->dropColumn(['due_soon_notified_at', 'overdue_notified_at']);
        });
    }
};
