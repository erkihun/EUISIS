<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Why a membership ended: LEFT (the person left), REMOVED (an owner, admin or
 * manager removed them) or SYNC (automatic membership no longer matches their
 * assignment). Automatic membership re-adds SYNC rows when the person is
 * placed back in scope, and never re-adds someone who left or was removed,
 * except in system-managed channels. Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_members', function (Blueprint $table): void {
            $table->string('left_reason', 10)->nullable()->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_members', function (Blueprint $table): void {
            $table->dropColumn('left_reason');
        });
    }
};
