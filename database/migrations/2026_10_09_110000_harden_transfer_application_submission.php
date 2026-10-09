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
            $table->string('application_number', 100)->nullable()->unique()->after('id');
            $table->timestamp('withdrawn_at')->nullable()->after('submitted_at');
            $table->text('withdrawal_reason')->nullable()->after('withdrawn_at');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_applications', function (Blueprint $table): void {
            $table->dropUnique(['application_number']);
            $table->dropColumn(['application_number', 'withdrawn_at', 'withdrawal_reason']);
        });
    }
};
