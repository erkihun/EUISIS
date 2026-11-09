<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog of the integration API surface.
 *
 * Rows are synced from the Laravel route table rather than written by hand, so
 * the documented surface cannot drift from the code. Editable metadata
 * (description, status, documentation visibility) lives here because it has no
 * home in a route definition.
 *
 * `created_by` is VARCHAR to match external_applications.created_by — users.id
 * is a BIGINT, and declaring this UUID caused an insert failure there already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_endpoint_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('method', 16);
            $table->string('uri', 255);
            $table->string('route_name')->nullable();
            $table->string('controller_action')->nullable();
            $table->string('required_scope', 64)->nullable();
            $table->json('middleware')->nullable();
            $table->boolean('auth_required')->default(true);
            $table->string('rate_limit', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('version', 16)->nullable();
            $table->string('status', 24)->default('active');
            $table->boolean('is_public_documented')->default(true);
            $table->string('created_by', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // Method+URI identifies an endpoint; the route name may be null or
            // may change without the endpoint itself changing.
            $table->unique(['method', 'uri'], 'api_endpoint_method_uri_unique');
            $table->index(['status', 'is_public_documented'], 'api_endpoint_status_documented_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_endpoint_definitions');
    }
};
