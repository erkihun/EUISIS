<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Letters, header and footer layouts (ADR 0037 §13, owner 2026-11-10): an
 * institution may keep several letter layouts, each with its own page setup
 * and a header and footer composed of rows, columns, text lines and images
 * whose content, font, size, colour and alignment are managed, not fixed.
 * The fixed letterhead fields of the earlier cut are converted into rows of
 * the institution's first layout and then dropped. A letter records the
 * layout it prints on.
 *
 * The same owner instruction removes the word "settings" from the module:
 * letter_settings becomes letter_standards and letters.settings.manage
 * becomes letters.standards.manage (role grants carried over).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const LEGACY_COLUMNS = [
        'name_am', 'name_en', 'parent_am', 'parent_en', 'address_am', 'address_en', 'phone', 'fax', 'email', 'website', 'po_box',
        'motto_am', 'motto_en', 'footer_am', 'footer_en', 'contacts_position', 'show_page_numbers', 'logo_left_path', 'logo_right_path',
    ];

    public function up(): void
    {
        Schema::table('letter_letterheads', function (Blueprint $table): void {
            $table->string('name', 120)->nullable()->after('organization_id');
            $table->boolean('is_default')->default(false)->after('name');
            $table->boolean('active')->default(true)->after('is_default');
            $table->json('page')->nullable()->after('active');
            $table->json('header')->nullable()->after('page');
            $table->json('footer')->nullable()->after('header');
            $table->json('images')->nullable()->after('footer');
            $table->foreignId('created_by')->nullable()->after('stamp_path')->constrained('users', 'id', 'ltr_lh_created_by_fk')->nullOnDelete();
            // Several layouts per institution: a plain index for the foreign key first (MySQL 1553), the unique one goes below.
            $table->index('organization_id', 'ltr_lh_org_idx');
        });
        Schema::table('letter_letterheads', function (Blueprint $table): void {
            $table->dropUnique('ltr_lh_org_uq');
        });

        foreach (DB::table('letter_letterheads')->get() as $row) {
            $converted = $this->convert((array) $row);
            DB::table('letter_letterheads')->where('id', $row->id)->update([
                'name' => $row->organization_id === null ? 'Platform default · የመድረኩ ነባሪ' : 'Standard · መደበኛ',
                'is_default' => true,
                'active' => true,
                'page' => json_encode($converted['page'], JSON_UNESCAPED_UNICODE),
                'header' => json_encode($converted['header'], JSON_UNESCAPED_UNICODE),
                'footer' => json_encode($converted['footer'], JSON_UNESCAPED_UNICODE),
                'images' => json_encode($converted['images'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        Schema::table('letter_letterheads', function (Blueprint $table): void {
            $table->dropColumn(self::LEGACY_COLUMNS);
        });

        Schema::table('letters', function (Blueprint $table): void {
            $table->foreignUuid('letterhead_id')->nullable()->after('organization_id')->constrained('letter_letterheads', 'id', 'ltr_letters_lh_fk')->nullOnDelete();
        });

        if (Schema::hasTable('letter_settings') && ! Schema::hasTable('letter_standards')) {
            Schema::rename('letter_settings', 'letter_standards');
        }

        $this->renamePermission('letters.settings.manage', 'letters.standards.manage');
        $rbac = app(RbacSynchronizer::class);
        $rbac->syncCatalog();
        $rbac->ensureRoles(createMissing: false);
        $rbac->applyMatrix(RbacSynchronizer::MODE_ADDITIVE, onlyExisting: true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $this->renamePermission('letters.standards.manage', 'letters.settings.manage');
        if (Schema::hasTable('letter_standards') && ! Schema::hasTable('letter_settings')) {
            Schema::rename('letter_standards', 'letter_settings');
        }
        Schema::table('letters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('letterhead_id');
        });
        // The converted rows cannot be folded back into fixed fields; keep one layout per institution and restore empty columns.
        $keep = DB::table('letter_letterheads')->orderByDesc('is_default')->orderBy('created_at')->get()->unique('organization_id')->pluck('id');
        DB::table('letter_letterheads')->whereNotIn('id', $keep)->delete();
        Schema::table('letter_letterheads', function (Blueprint $table): void {
            foreach (self::LEGACY_COLUMNS as $column) {
                match ($column) {
                    'contacts_position' => $table->string($column, 8)->default('header'),
                    'show_page_numbers' => $table->boolean($column)->default(true),
                    default => $table->string($column, 300)->nullable(),
                };
            }
            $table->unique('organization_id', 'ltr_lh_org_uq');
        });
        Schema::table('letter_letterheads', function (Blueprint $table): void {
            $table->dropIndex('ltr_lh_org_idx');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['name', 'is_default', 'active', 'page', 'header', 'footer', 'images']);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function renamePermission(string $from, string $to): void
    {
        $old = DB::table('permissions')->where('name', $from)->where('guard_name', 'web')->first();
        if ($old === null) {
            return;
        }
        $new = DB::table('permissions')->where('name', $to)->where('guard_name', 'web')->first();
        if ($new === null) {
            DB::table('permissions')->where('id', $old->id)->update(['name' => $to]);

            return;
        }
        foreach (DB::table('role_has_permissions')->where('permission_id', $old->id)->get() as $grant) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $new->id, 'role_id' => $grant->role_id]);
        }
        foreach (DB::table('model_has_permissions')->where('permission_id', $old->id)->get() as $grant) {
            DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $new->id, 'model_type' => $grant->model_type, 'model_id' => $grant->model_id]);
        }
        DB::table('role_has_permissions')->where('permission_id', $old->id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $old->id)->delete();
        DB::table('permissions')->where('id', $old->id)->delete();
    }

    /**
     * The earlier fixed letterhead as rows: emblems and names in the header,
     * contacts where they were printed, motto, footer text and page number
     * in the footer.
     *
     * @param  array<string, mixed>  $r
     * @return array{page: array<string, mixed>, header: array<string, mixed>, footer: array<string, mixed>, images: list<array<string, string>>}
     */
    private function convert(array $r): array
    {
        $text = fn (string $value, string $language = 'both', array $style = []): array => [
            'type' => 'text', 'text' => $value, 'language' => $language, 'font_family' => '',
            'font_size' => $style['size'] ?? 10, 'bold' => $style['bold'] ?? false, 'underline' => false, 'color' => $style['color'] ?? '#111111',
        ];
        $image = fn (string $key): array => ['type' => 'image', 'image' => $key, 'width_mm' => 22, 'language' => 'both'];
        $value = fn (string $field): ?string => isset($r[$field]) && trim((string) $r[$field]) !== '' ? trim((string) $r[$field]) : null;

        $images = [];
        foreach (['logo_left' => 'logo_left_path', 'logo_right' => 'logo_right_path'] as $key => $column) {
            if ($value($column) !== null) {
                $images[] = ['key' => $key, 'path' => $value($column), 'name' => $key === 'logo_left' ? 'Left emblem · የግራ አርማ' : 'Right emblem · የቀኝ አርማ'];
            }
        }

        $names = array_values(array_filter([
            $value('parent_am') !== null ? $text($value('parent_am'), $value('parent_en') !== null ? 'am' : 'both', ['size' => 10, 'color' => '#444444']) : null,
            $value('parent_en') !== null ? $text($value('parent_en'), $value('parent_am') !== null ? 'en' : 'both', ['size' => 10, 'color' => '#444444']) : null,
            $text($value('name_am') ?? '{{institution.name_am}}', 'both', ['size' => 14, 'bold' => true, 'color' => '#122170']),
            $text($value('name_en') ?? '{{institution.name_en}}', 'both', ['size' => 10.5, 'color' => '#333333']),
            $text('{{unit.name}}', 'both', ['size' => 10, 'color' => '#444444']),
        ]));

        $contactParts = fn (string $language): string => implode(' · ', array_values(array_filter([
            $language === 'am' ? ($value('address_am') ?? $value('address_en')) : ($value('address_en') ?? $value('address_am')),
            $value('po_box') !== null ? ($language === 'am' ? 'ፖ.ሣ.ቁ. ' : 'P.O. Box ').$value('po_box') : null,
            $value('phone') !== null ? ($language === 'am' ? 'ስልክ ' : 'Tel. ').$value('phone') : null,
            $value('fax') !== null ? ($language === 'am' ? 'ፋክስ ' : 'Fax ').$value('fax') : null,
            $value('email'),
            $value('website'),
        ])));
        $contactItems = $contactParts('am') !== '' ? [
            $text($contactParts('am'), 'am', ['size' => 8.5, 'color' => '#444444']),
            $text($contactParts('en'), 'en', ['size' => 8.5, 'color' => '#444444']),
        ] : [];
        $position = in_array($r['contacts_position'] ?? 'header', ['header', 'footer', 'both'], true) ? $r['contacts_position'] : 'header';

        $row = fn (array $cells, string $border = 'none', string $style = 'thin', string $color = '#122170'): array => [
            'cells' => $cells, 'border_position' => $border, 'border_style' => $style, 'border_color' => $color, 'space_after' => 3,
        ];
        $cell = fn (array $items, string $align, int $width): array => ['items' => $items, 'align' => $align, 'valign' => 'middle', 'width' => $width];

        $header = [$row([
            $cell([$image($value('logo_left_path') !== null ? 'logo_left' : 'org_logo')], 'left', 18),
            $cell($names, 'center', 64),
            $cell($value('logo_right_path') !== null ? [$image('logo_right')] : [], 'right', 18),
        ], 'bottom', 'thick')];
        if ($contactItems !== [] && in_array($position, ['header', 'both'], true)) {
            $header[] = $row([$cell($contactItems, 'center', 0)]);
        }

        $footerItems = array_values(array_filter([
            $value('motto_am') !== null ? $text($value('motto_am'), $value('motto_en') !== null ? 'am' : 'both', ['size' => 9, 'color' => '#122170']) : null,
            $value('motto_en') !== null ? $text($value('motto_en'), $value('motto_am') !== null ? 'en' : 'both', ['size' => 9, 'color' => '#122170']) : null,
            $value('footer_am') !== null ? $text($value('footer_am'), $value('footer_en') !== null ? 'am' : 'both', ['size' => 8.5, 'color' => '#333333']) : null,
            $value('footer_en') !== null ? $text($value('footer_en'), $value('footer_am') !== null ? 'en' : 'both', ['size' => 8.5, 'color' => '#333333']) : null,
            ...(in_array($position, ['footer', 'both'], true) ? $contactItems : []),
            $text('{{institution.name}} · {{letter.number}}'.(($r['show_page_numbers'] ?? true) ? ' · {{page}}' : ''), 'both', ['size' => 8.5, 'color' => '#555555']),
        ]));

        return [
            'page' => [
                'paper' => 'a4', 'orientation' => 'portrait', 'margin_top' => 20, 'margin_right' => 18, 'margin_bottom' => 30, 'margin_left' => 18,
                'font_family' => 'abyssinica', 'font_size' => 11.5, 'text_color' => '#111111', 'reference_align' => 'left',
            ],
            'header' => ['rows' => $header],
            'footer' => ['rows' => [$row([$cell($footerItems, 'center', 0)], 'top', 'thin', '#C7CBE0')]],
            'images' => $images,
        ];
    }
};
