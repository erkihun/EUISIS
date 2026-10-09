<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }
        });

        Schema::table('organization_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('organization_types', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn('organization_types', 'sort_order')) {
                $table->integer('sort_order')->default(0);
            }
            if (! Schema::hasColumn('organization_types', 'description_en')) {
                $table->text('description_en')->nullable();
            }
            if (! Schema::hasColumn('organization_types', 'description_am')) {
                $table->text('description_am')->nullable();
            }
        });

        if (Schema::hasTable('system_settings')) {
            return;
        }

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('group', 64)->index();
            $table->string('key', 128);
            $table->text('value')->nullable();
            $table->string('type', 32)->default('string');
            $table->string('label_en', 255)->nullable();
            $table->string('label_am', 255)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_encrypted')->default(false);
            $table->integer('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');

        Schema::table('organization_types', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'sort_order', 'description_en', 'description_am']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['status', 'last_login_at']);
        });
    }
};
