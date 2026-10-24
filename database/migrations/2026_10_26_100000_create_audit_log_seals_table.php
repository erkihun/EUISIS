<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One keyed checksum per finished day of the audit log (ADR 0003). Additive:
 * no audit row is touched; `audit:seal` fills it, `audit:verify` reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional: a retried deploy must be able to resume.
        if (Schema::hasTable('audit_log_seals')) {
            return;
        }

        Schema::create('audit_log_seals', function (Blueprint $table): void {
            $table->id();
            $table->date('sealed_on')->unique();
            $table->unsignedBigInteger('row_count');
            $table->char('digest', 64);
            $table->char('previous_digest', 64)->nullable();
            $table->string('key_id', 16);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_seals');
    }
};
