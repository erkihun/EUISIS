<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The public feedback "Issue Category" is removed end to end (owner decision,
 * 2026-10-27): the public forms no longer ask it, routing rules no longer
 * match on it, and nothing reports on it. This drops the two columns that
 * carried it and the categories table, and retires the management
 * permission.
 *
 * Safe to re-run on a server where an earlier attempt stopped halfway: every
 * step checks what is still there. On SQLite (the test database) the routing
 * rules column and the empty categories table stay: SQLite cannot drop a
 * column that carries a foreign key, and with the parent table gone it refuses
 * every write to the child table. Nothing reads either of them any more.
 *
 * migration-safety: reviewed. The drop is the owner's decision of 2026-10-27
 * (the category is gone end to end and is not to come back); nothing reads the
 * dropped table or columns, and the release backup taken before deploying
 * holds the old categories should they ever be needed.
 */
return new class extends Migration
{
    private const PERMISSION = 'service_feedback.manage_categories';

    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        if (Schema::hasColumn('employee_service_feedback', 'category_id')) {
            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                if ($this->hasForeignKey('employee_service_feedback', 'category_id')) {
                    $table->dropForeign(['category_id']);
                }
                if ($this->hasIndex('employee_service_feedback', 'employee_service_feedback_category_id_index')) {
                    $table->dropIndex('employee_service_feedback_category_id_index');
                }
                $table->dropColumn('category_id');
            });
        }

        if (! $sqlite && Schema::hasColumn('public_feedback_routing_rules', 'category_id')) {
            Schema::table('public_feedback_routing_rules', function (Blueprint $table): void {
                if ($this->hasForeignKey('public_feedback_routing_rules', 'category_id')) {
                    $table->dropForeign(['category_id']);
                }
                $table->dropColumn('category_id');
            });
        }

        if (! $sqlite) {
            Schema::dropIfExists('public_feedback_categories');
        }

        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')->where('name', self::PERMISSION)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('public_feedback_categories')) {
            Schema::create('public_feedback_categories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 40);
                $table->string('name_en');
                $table->string('name_am')->nullable();
                $table->text('description_en')->nullable();
                $table->text('description_am')->nullable();
                $table->json('submission_types');
                $table->json('target_types');
                $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_public_selectable')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['organization_id', 'code']);
                $table->index(['organization_id', 'is_active', 'sort_order'], 'pfc_org_active_sort_idx');
            });
        }

        if (! Schema::hasColumn('employee_service_feedback', 'category_id')) {
            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                $table->uuid('category_id')->nullable()->index();
            });
        }

        if (DB::getDriverName() !== 'sqlite' && ! Schema::hasColumn('public_feedback_routing_rules', 'category_id')) {
            Schema::table('public_feedback_routing_rules', function (Blueprint $table): void {
                $table->foreignUuid('category_id')->nullable()->constrained('public_feedback_categories')->restrictOnDelete();
            });
        }
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn (array $key): bool => $key['columns'] === [$column]);
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
