<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured content for the public website (Public Site Management).
 *
 * Every bilingual field is an explicit `_en` / `_am` pair, matching the rest of
 * the schema (organizations.name_en/name_am, positions.title_en/title_am).
 *
 * These tables hold CONTENT only. Nothing here can influence ID verification,
 * OTP, rate limits or which employee fields are exposed — that logic stays in
 * PublicIdCheckerService and is not configurable from the CMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_announcements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('title_en', 255);
            $table->string('title_am', 255)->nullable();
            $table->string('summary_en', 500)->nullable();
            $table->string('summary_am', 500)->nullable();
            // Markdown source; rendered and sanitized server-side on output.
            $table->text('content_en')->nullable();
            $table->text('content_am')->nullable();
            $table->string('category', 60)->nullable();
            $table->string('featured_image_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The public visibility query filters on all three.
            $table->index(['status', 'published_at', 'expires_at']);
        });

        Schema::create('public_announcement_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('public_announcement_id')->constrained('public_announcements')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('public_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('slug', 160)->unique();
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->string('short_description_en', 500)->nullable();
            $table->string('short_description_am', 500)->nullable();
            $table->text('full_description_en')->nullable();
            $table->text('full_description_am')->nullable();
            $table->text('eligibility_en')->nullable();
            $table->text('eligibility_am')->nullable();
            $table->text('requirements_en')->nullable();
            $table->text('requirements_am')->nullable();
            $table->text('steps_en')->nullable();
            $table->text('steps_am')->nullable();
            // One of a fixed allow-list of existing icons, never an uploaded SVG.
            $table->string('icon', 40)->nullable();
            // Either an allow-listed public route name, or an https URL.
            $table->string('action_route', 120)->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('contact_info', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
        });

        Schema::create('public_faqs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('question_en', 500);
            $table->string('question_am', 500)->nullable();
            $table->text('answer_en');
            $table->text('answer_am')->nullable();
            $table->string('category', 60)->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });

        foreach (['public_navigation_items', 'public_footer_links'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->uuid('id')->primary();
                if ($tableName === 'public_footer_links') {
                    // useful | legal
                    $table->string('group', 20)->default('useful');
                }
                $table->string('label_en', 120);
                $table->string('label_am', 120)->nullable();
                // route | external
                $table->string('type', 20)->default('route');
                $table->string('route_name', 120)->nullable();
                $table->string('url', 500)->nullable();
                $table->boolean('is_visible')->default(true);
                // A system item (e.g. Verify ID Cards) cannot be deleted or hidden.
                $table->boolean('is_system')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['is_visible', 'sort_order']);
            });
        }

        Schema::create('public_page_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Page and section keys are fixed in PublicSiteSectionRegistry;
            // the CMS edits content of known slots, it cannot add new ones.
            $table->string('page', 40);
            $table->string('section_key', 60);
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('title_en', 255)->nullable();
            $table->string('title_am', 255)->nullable();
            $table->string('subtitle_en', 500)->nullable();
            $table->string('subtitle_am', 500)->nullable();
            $table->text('body_en')->nullable();
            $table->text('body_am')->nullable();
            // Per-section structured options (max_items, CTA route…),
            // validated against the registry's schema for that key.
            $table->json('options')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['page', 'section_key']);
        });

        Schema::create('public_page_meta', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('page', 40)->unique();
            $table->string('meta_title_en', 160)->nullable();
            $table->string('meta_title_am', 160)->nullable();
            $table->string('meta_description_en', 320)->nullable();
            $table->string('meta_description_am', 320)->nullable();
            $table->string('canonical_url', 500)->nullable();
            $table->string('og_image_path')->nullable();
            $table->boolean('noindex')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_page_meta');
        Schema::dropIfExists('public_page_sections');
        Schema::dropIfExists('public_footer_links');
        Schema::dropIfExists('public_navigation_items');
        Schema::dropIfExists('public_faqs');
        Schema::dropIfExists('public_services');
        Schema::dropIfExists('public_announcement_attachments');
        Schema::dropIfExists('public_announcements');
    }
};
