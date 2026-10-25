<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-factor authentication for Core accounts (ADR 0008), with the EUISIS
 * column names: the TOTP secret (encrypted at rest), the recovery codes
 * (hashed, then encrypted), when enrolment was confirmed, and when a code was
 * last accepted (a code is never accepted twice). Nobody is enrolled by the
 * migration: privileged accounts enrol at their next sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->timestamp('two_factor_last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_enabled', 'two_factor_last_used_at']);
        });
    }
};
