<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The institution's official public contact number — the "Call" action on
 * its own feedback QR page and on its employees' feedback QR pages
 * (docs/public-qr-mobile-actions.md). Optional and nullable: no existing row
 * is touched. Guarded so a retry on MySQL, whose DDL is not transactional,
 * resumes instead of failing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organizations') && ! Schema::hasColumn('organizations', 'public_contact_phone')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->string('public_contact_phone', 32)->nullable()->after('branding_secondary_color');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organizations', 'public_contact_phone')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropColumn('public_contact_phone');
            });
        }
    }
};
